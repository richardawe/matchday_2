<?php

namespace App\Design;

use GdImage;

/**
 * One laid-out card of a video: a static backdrop plus the items drawn over it each frame (text,
 * panels, bars, photos with Ken Burns, swatches), each with its entrance. Components build scenes
 * from these primitives only, so every renderer shares the same chips, panels, type and motion.
 *
 * Text items keep the shape the frame engine and the design check read: text, font, size (px),
 * x, y (baseline), colour, tone (colour token), on (background token), style, delay, fade, motion,
 * width, top, bottom.
 */
final class Scene
{
    public GdImage $bg;

    public array $items = [];

    /** Photos shown on this card (for the credit rule and the manifest). */
    public array $photos = [];

    /** The credit line shown on this card, if any. */
    public ?string $credit = null;

    /** Seconds: when the last entrance has finished. */
    public float $settled = 0.0;

    public bool $flash = false;

    public bool $still = false;

    public readonly int $w;
    public readonly int $h;
    public readonly int $L;
    public readonly int $R;
    public readonly int $top;
    public readonly int $bottom;

    public function __construct(public string $component, public string $key, public string $backdrop = 'black', public ?string $accent = null)
    {
        [$this->w, $this->h] = Tokens::canvas();
        $safe = Tokens::safe();
        [$this->L, $this->R, $this->top, $this->bottom] = [$safe['left'], $safe['right'], $safe['top'], $safe['bottom']];
        $this->bg = imagecreatetruecolor($this->w, $this->h);
        imagealphablending($this->bg, true);
        imagefill($this->bg, 0, 0, Canvas::col($this->bg, $backdrop));
        $this->accent ??= Tokens::colour('yellow');
    }

    public function width(): int
    {
        return $this->R - $this->L;
    }

    // ---------------------------------------------------------------- text

    /**
     * One line of text, its baseline at $y. $tone is a colour token; $on the token (or 'team' with
     * $teamHex) it sits on, checked against the contrast rules. Shrinks to $maxWidth.
     */
    public function text(string $text, string $style, int $x, int $y, string $tone, string $on, float $delay = 0.0,
        ?int $maxWidth = null, ?int $px = null, string $align = 'left', ?array $box = null, string $motion = 'rise',
        ?string $teamHex = null, ?string $flag = null, bool $visible = false): array
    {
        CopyRules::assert($text, "{$this->component} card");
        Canvas::assertPair($tone, $on, $teamHex);
        $type = Tokens::type($style);
        $px ??= (int) $type['size'];
        if ($maxWidth !== null) {
            $px = Canvas::shrink($text, $type['file'], $px, min($px, (int) $type['min']), $maxWidth);
            // Still too long at the smallest size on the scale: cut it short rather than leave the safe zone.
            $text = $this->ellipsis($text, $type['file'], $px, $maxWidth);
        }
        [$width, $top, $bottom] = Canvas::measure($text, $type['file'], $px);
        if ($align !== 'left' && $box) {
            $x = $align === 'center' ? (int) round($box[0] + ($box[1] - $box[0] - $width) / 2) : (int) ($box[1] - $width);
        }
        $flagW = (int) Tokens::get('grid.flag.width');
        $item = ['kind' => 'text', 'text' => $text, 'font' => $type['file'], 'font_token' => $type['font'], 'style' => $style, 'size' => $px,
            'x' => $x, 'y' => $y, 'colour' => Canvas::hex($tone), 'tone' => $tone, 'on' => $on, 'team' => $teamHex,
            'delay' => $delay, 'fade' => (float) Tokens::motion('in'), 'motion' => $motion, 'visible' => $visible,
            'width' => $width, 'top' => $y + $top, 'bottom' => $y + $bottom, 'animate' => $motion !== 'none',
            'flag' => $flag, 'flag_x' => $flag ? $x + $width + 24 : null, 'flag_w' => $flagW];
        $this->items[] = $item;
        $this->settle($delay);

        return $item;
    }

    /**
     * Wrapped text in a type style: each line (or each word, when $kinetic) rises in turn. Lines
     * are fitted first (Canvas::fit). Returns the y below the block.
     */
    public function paragraph(array $lines, string $style, int $x, int $y, string $tone, string $on, float $delay = 0.0,
        bool $kinetic = false, string $align = 'left', ?array $box = null, ?string $teamHex = null, string $motion = 'rise', bool $visible = false): int
    {
        $type = Tokens::type($style);
        // Phrases are checked whole: kinetic text is placed word by word.
        CopyRules::assert(implode(' ', array_column($lines, 0)), "{$this->component} card");
        $k = 0;
        foreach ($lines as $i => [$line, $px]) {
            $lead = (int) round($px * (float) $type['leading']);
            $base = $y + $this->ascent($style, $px);
            if ($kinetic) {
                $words = preg_split('/\s+/u', $line) ?: [];
                $space = (int) round($px * 0.26);
                $total = Canvas::measure($line, $type['file'], $px)[0];
                $wx = $align === 'center' && $box ? (int) round($box[0] + ($box[1] - $box[0] - $total) / 2) : $x;
                foreach ($words as $word) {
                    $item = $this->text($word, $style, $wx, $base, $tone, $on, $delay + $k * (float) Tokens::motion('word_stagger'),
                        px: $px, motion: $motion, teamHex: $teamHex, visible: $visible);
                    $wx += $item['width'] + $space;
                    $k++;
                }
            } else {
                $this->text($line, $style, $x, $base, $tone, $on, $delay + $i * (float) Tokens::motion('line_stagger'),
                    px: $px, align: $align, box: $box ?? [$x, $x + $this->width()], motion: $motion, teamHex: $teamHex, visible: $visible);
            }
            $y += $lead;
        }

        return $y;
    }

    /** Height of fitted lines in a style. */
    public function linesHeight(array $lines, string $style): int
    {
        $lead = (float) Tokens::type($style)['leading'];

        return (int) array_sum(array_map(fn ($l) => round($l[1] * $lead), $lines));
    }

    /** Distance from a line's top to its baseline. */
    public function ascent(string $style, int $px): int
    {
        return (int) round(-Canvas::measure('Hd', Tokens::type($style)['file'], $px)[1] + ($px * (float) Tokens::type($style)['leading'] - $px) / 2);
    }

    // ---------------------------------------------------------------- shapes

    /** A panel: a filled (rounded) rectangle that grows in with its card. $tone is a token or a team hex. */
    public function panel(int $x1, int $y1, int $x2, int $y2, string $tone, float $delay = 0.0, string $motion = 'fade', ?int $radius = null): array
    {
        $item = ['kind' => 'shape', 'text' => '', 'shape' => ['round', $x1, $y1, $x2, $y2, Canvas::hex($tone), $radius ?? (int) Tokens::get('grid.radius')],
            'tone' => $tone, 'delay' => $delay, 'fade' => (float) Tokens::motion('in'), 'motion' => $motion, 'animate' => $motion !== 'none',
            'top' => $y1, 'bottom' => $y2, 'x' => $x1, 'width' => $x2 - $x1, 'y' => $y2, 'flag' => null];
        $this->items[] = $item;
        $this->settle($delay);

        return $item;
    }

    /** A bar split between two colours at $split, growing from both ends. */
    public function bar(int $x1, int $y1, int $x2, int $y2, string $a, string $b, int $split, float $delay = 0.0): void
    {
        $this->items[] = ['kind' => 'shape', 'text' => '', 'shape' => ['bar', $x1, $y1, $x2, $y2, Canvas::hex($a), Canvas::hex($b), $split],
            'delay' => $delay, 'fade' => (float) Tokens::motion('in') * 1.6, 'motion' => 'grow', 'animate' => true,
            'top' => $y1, 'bottom' => $y2, 'x' => $x1, 'width' => $x2 - $x1, 'y' => $y2, 'flag' => null];
        $this->settle($delay, 1.6);
    }

    /** A side's flag or kit swatch as an item. */
    public function swatch(array $side, int $x, int $y, int $size, float $delay = 0.0): void
    {
        $this->items[] = ['kind' => 'swatch', 'text' => '', 'side' => $side, 'x' => $x, 'y' => $y, 'size' => $size,
            'delay' => $delay, 'fade' => (float) Tokens::motion('in'), 'motion' => 'fade', 'animate' => true,
            'top' => $y, 'bottom' => $y + $size, 'width' => $size, 'flag' => null];
        $this->settle($delay);
    }

    /** A chip: a rounded label at the chips row (sentence case). Returns its right edge. */
    public function chip(string $label, int $x, ?int $y = null, string $fill = 'yellow', float $delay = 0.0, string $align = 'left'): int
    {
        $y ??= (int) Tokens::get('grid.chips.top');
        $h = (int) Tokens::get('grid.chips.height');
        $type = Tokens::type('label');
        $px = (int) $type['size'];
        $label = trim($label);
        $max = $this->width() - 40;
        $px = Canvas::shrink($label, $type['file'], $px, (int) $type['min'], $max);
        [$tw] = Canvas::measure($label, $type['file'], $px);
        $w = $tw + 48;
        if ($align === 'right') {
            $x -= $w;
        }
        $ink = $fill === 'yellow' || $fill === 'white' ? 'black' : ($fill === 'black' || $fill === 'surface' ? 'yellow' : 'white');
        $this->panel($x, $y, $x + $w, $y + $h, $fill, $delay, 'fade', intdiv($h, 2));
        $base = $y + intdiv($h, 2) + intdiv((int) round(-Canvas::measure('H', $type['file'], $px)[1]), 2);
        $this->text($label, 'label', $x + 24, $base, $ink, $fill, $delay, px: $px, motion: 'fade');

        return $x + $w;
    }

    /** The minute tag: uppercase allowed ("63'", "90+3'"), right-aligned on the chips row. */
    public function minute(string $label, ?int $y = null, float $delay = 0.0): void
    {
        $this->chip($label, $this->R, $y, 'white', $delay, 'right');
    }

    /**
     * The scoreboard strip at its fixed place: both sides' flags or swatches at the ends, "Home 2-1 Away"
     * (or "Home v Away") in the middle. Score digits only: the names stay in sentence case.
     */
    public function scoreboard(array $sides, string $score, float $delay = 0.0, string $fill = 'surface'): void
    {
        $y = (int) Tokens::get('grid.scoreboard.top');
        $h = (int) Tokens::get('grid.scoreboard.height');
        $this->panel($this->L, $y, $this->R, $y + $h, $fill, $delay, 'none');
        $sw = 72;
        $this->swatch($sides['home'], $this->L + 20, $y + intdiv($h - $sw, 2), $sw, $delay);
        $this->swatch($sides['away'], $this->R - 20 - $sw, $y + intdiv($h - $sw, 2), $sw, $delay);
        $score = str_replace('-', '–', $score);
        $line = "{$sides['home']['name']}  {$score}  {$sides['away']['name']}";
        $box = [$this->L + 112, $this->R - 112];
        $type = Tokens::type('strip');
        $px = Canvas::shrink($line, $type['file'], (int) $type['size'], (int) $type['min'], $box[1] - $box[0]);
        $base = $y + intdiv($h, 2) + intdiv((int) round(-Canvas::measure('H', $type['file'], $px)[1]), 2);
        $this->text($line, 'strip', $box[0], $base, $fill === 'yellow' ? 'black' : 'white', $fill, $delay, px: $px, align: 'center', box: $box, motion: 'none');
    }

    /** The score block: a two-colour band with the big score; $before ticks to $after at $delay. */
    public function scoreBlock(array $sides, string $before, string $after, int $y, int $h, float $delay): void
    {
        [$ha] = $sides['home']['colours'];
        [$aa] = $sides['away']['colours'];
        $mid = intdiv($this->L + $this->R, 2);
        $this->panel($this->L, $y, $mid, $y + $h, $ha, max(0.0, $delay - 0.3), 'grow', 0);
        $this->panel($mid, $y, $this->R, $y + $h, $aa, max(0.0, $delay - 0.3), 'grow', 0);
        // The score sits on a dark plate in the middle so it reads on any pair of kit colours.
        $plateW = 560;
        $this->panel($mid - intdiv($plateW, 2), $y + 26, $mid + intdiv($plateW, 2), $y + $h - 26, 'surface', max(0.0, $delay - 0.2), 'fade');
        $type = Tokens::type('score');
        $text = str_replace('-', ' – ', $after);
        $px = Canvas::shrink($text, $type['file'], min((int) $type['size'], $h - 70), (int) $type['min'], $plateW - 60);
        $base = $y + intdiv($h, 2) + intdiv((int) round(-Canvas::measure('0', $type['file'], $px)[1]), 2);
        $item = $this->text($text, 'score', $this->L, $base, 'white', 'surface', $delay, px: $px, align: 'center', box: [$this->L, $this->R], motion: 'count');
        $last = array_key_last($this->items);
        $this->items[$last]['before'] = str_replace('-', ' – ', $before);
        $this->items[$last]['pop'] = $before !== $after;
        $this->items[$last]['visible'] = true;
    }

    /** The photo credit line at its fixed place (on a black strip, grey type). */
    public function credit(?string $credit): void
    {
        $credit = trim((string) $credit);
        if ($credit === '') {
            return;
        }
        $text = str_starts_with($credit, 'Photo') ? $credit : 'Photo: '.$credit;
        $type = Tokens::type('credit');
        $px = Canvas::shrink($text, $type['file'], (int) $type['size'], (int) $type['min'], $this->width() - 24);
        $text = $this->ellipsis($text, $type['file'], $px, $this->width() - 24);
        $base = (int) Tokens::get('grid.credit.baseline');
        $this->panel($this->L, $base - 26, $this->L + Canvas::measure($text, $type['file'], $px)[0] + 24, $base + 8, 'black', 0.0, 'none', 6);
        $this->text($text, 'credit', $this->L + 12, $base, 'grey', 'black', 0.0, px: $px, motion: 'none');
        $this->credit = $text;
    }

    // ---------------------------------------------------------------- photos

    /**
     * A licensed photo (or one of our renders) in a box, pushed in or out over the cut (Ken Burns).
     * Checks the images rule. False when there is no readable file.
     */
    public function photo(?string $file, ?array $crop, int $x, int $y, int $w, int $h, ?string $credit, bool $zoomIn = true, bool $contain = false): bool
    {
        $src = $file ? Canvas::load($file) : null;
        if (!$src) {
            return false;
        }
        Canvas::assertImage($file, $credit);
        if ($contain) {
            // Our own art: letterboxed on the surface colour, no push.
            Canvas::rect($this->bg, $x, $y, $x + $w, $y + $h, 'surface');
            $scale = min($w / imagesx($src), $h / imagesy($src));
            [$dw, $dh] = [(int) (imagesx($src) * $scale), (int) (imagesy($src) * $scale)];
            imagecopyresampled($this->bg, $src, $x + intdiv($w - $dw, 2), $y + intdiv($h - $dh, 2), 0, 0, $dw, $dh, imagesx($src), imagesy($src));
            imagedestroy($src);
            $this->photos[] = ['file' => $file, 'credit' => $credit, 'box' => [$x, $y, $w, $h], 'own' => true];

            return true;
        }
        // Keep a working copy of the crop at twice the box at most (fast Ken Burns scaling).
        $rect = Canvas::cover($src, $crop, $w, $h);
        $scale = min(1.0, (2 * $w) / max(1, $rect[2]));
        $cw = max($w, (int) round($rect[2] * $scale));
        $ch = max($h, (int) round($rect[3] * $scale));
        $work = imagecreatetruecolor($cw, $ch);
        imagecopyresampled($work, $src, 0, 0, $rect[0], $rect[1], $cw, $ch, $rect[2], $rect[3]);
        imagedestroy($src);
        $this->items[] = ['kind' => 'photo', 'text' => '', 'src' => $work, 'box' => [$x, $y, $w, $h], 'zoom_in' => $zoomIn,
            'delay' => 0.0, 'fade' => 0.0, 'motion' => 'kenburns', 'animate' => false, 'top' => $y, 'bottom' => $y + $h, 'x' => $x, 'width' => $w, 'y' => $y + $h, 'flag' => null];
        $this->photos[] = ['file' => $file, 'credit' => $credit, 'box' => [$x, $y, $w, $h], 'own' => false];

        return true;
    }

    /** Our own render when there is no licensed photo: the side's colours, initials and flag (drawn once). */
    public function monogram(string $name, ?string $flag, int $x, int $y, int $w, int $h, array $colours): void
    {
        Canvas::monogram($this->bg, $name, $flag, $x, $y, $w, $h, $colours);
        $this->photos[] = ['file' => null, 'credit' => null, 'box' => [$x, $y, $w, $h], 'own' => true];
    }

    // ---------------------------------------------------------------- layout

    /**
     * Stacks blocks between $top and $bottom so the stack fills the space: each block is
     * ['h' => minimum height, 'flex' => share of the spare room, 'draw' => fn(int $y, int $h)].
     * With no flexible block the stack is centred. Gaps from tokens grid.gap.
     */
    public function stack(array $blocks, int $top, int $bottom, ?int $gap = null): void
    {
        $blocks = array_values(array_filter($blocks));
        if (!$blocks) {
            return;
        }
        $gap ??= (int) Tokens::get('grid.gap');
        $need = array_sum(array_column($blocks, 'h')) + $gap * (count($blocks) - 1);
        $spare = max(0, $bottom - $top - $need);
        $flex = array_sum(array_map(fn ($b) => (float) ($b['flex'] ?? 0), $blocks));
        $y = $flex > 0 ? $top : $top + intdiv($spare, 2);
        foreach ($blocks as $b) {
            $h = (int) $b['h'] + ($flex > 0 ? (int) floor($spare * (float) ($b['flex'] ?? 0) / $flex) : 0);
            ($b['draw'])($y, $h);
            $y += $h + $gap;
        }
    }

    // ---------------------------------------------------------------- helpers

    public function ellipsis(string $text, string $file, int $px, int $width): string
    {
        while (Canvas::measure($text, $file, $px)[0] > $width && mb_strlen($text) > 4) {
            $text = rtrim(mb_substr($text, 0, -2)).'…';
        }

        return $text;
    }

    private function settle(float $delay, float $factor = 1.0): void
    {
        $this->settled = max($this->settled, $delay + (float) Tokens::motion('in') * $factor);
    }

    public function destroy(): void
    {
        imagedestroy($this->bg);
        foreach ($this->items as $item) {
            if (($item['kind'] ?? '') === 'photo') {
                imagedestroy($item['src']);
            }
        }
    }
}
