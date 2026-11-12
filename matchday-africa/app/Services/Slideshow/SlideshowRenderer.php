<?php

namespace App\Services\Slideshow;

use GdImage;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Draws a slideshow's slides as 1080×1920 PNGs with GD: the two sides' colours split on a
 * diagonal, a cream comic card in the safe column holding the copy, and the matchday.africa
 * footer pill. Club names are text only: no crests or logos anywhere.
 *
 * Every piece of text is measured where it is drawn; if any of it falls outside the safe zone
 * (config slideshow.safe: the 800 px column, y 150–1540) the render fails with UnsafeLayout.
 */
class SlideshowRenderer
{
    private const INK = [11, 11, 11];
    private const CREAM = [255, 248, 231];
    private const SUN = [255, 210, 63];
    private const RED = [214, 52, 40];
    private const SOFT = [92, 84, 72];
    private const GREEN = [24, 150, 72];
    private const GREY = [150, 144, 132];

    private const CARD_TOP = 150;
    private const CARD_BOTTOM = 1420;
    private const PAD = 48;
    private const BORDER = 8;

    /** Text boxes drawn on the slide being rendered: [[x1, y1, x2, y2], …]. */
    private array $boxes = [];

    /**
     * Draws every slide into $dir as 01.png, 02.png … Returns [['file' => …, 'boxes' => […]], …].
     *
     * @throws UnsafeLayout when text leaves the safe zone
     */
    public function render(array $slides, string $dir): array
    {
        File::ensureDirectoryExists($dir);
        $out = [];
        foreach (array_values($slides) as $i => $slide) {
            $img = $this->draw($slide);
            $this->check($i + 1, $this->boxes);
            $file = sprintf('%s/%02d.png', $dir, $i + 1);
            imagepng($img, $file, 6);
            imagedestroy($img);
            $out[] = ['file' => $file, 'boxes' => $this->boxes];
        }

        return $out;
    }

    public function draw(array $slide): GdImage
    {
        [$w, $h] = config('slideshow.size', [1080, 1920]);
        $this->boxes = [];
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imageantialias($img, true);
        $colours = $slide['colours'] ?? ['#0B0B0B', '#FFD23F'];
        $this->background($img, $w, $h, $colours[0], $colours[1] ?? $colours[0]);

        $blocks = match ($slide['kind'] ?? '') {
            'hook' => $this->hookBlocks($slide),
            'flags' => $this->flagsBlocks($slide),
            'fixture' => $this->fixtureBlocks($slide),
            'players' => $this->fittingPlayers($slide),
            'form' => $this->formBlocks($slide),
            'cta' => $this->ctaBlocks($slide),
            default => throw new RuntimeException('Unknown slide kind: '.($slide['kind'] ?? 'none')),
        };
        $this->card($img, $blocks);
        $this->footer($img, $w);

        return $img;
    }

    /** Fails the render if any text box leaves the safe zone. */
    public function check(int $slide, array $boxes): void
    {
        $safe = config('slideshow.safe');
        foreach ($boxes as [$x1, $y1, $x2, $y2]) {
            if ($x1 < $safe['left'] || $x2 > $safe['right'] || $y1 < $safe['top'] || $y2 > $safe['bottom']) {
                throw new UnsafeLayout("Slide {$slide}: text at [{$x1}, {$y1}, {$x2}, {$y2}] is outside the safe zone.");
            }
        }
    }

    /** The boxes drawn on the last slide (for tests and the zoom clamp). */
    public function boxes(): array
    {
        return $this->boxes;
    }

    // ---------------------------------------------------------------- layout

    private function hookBlocks(array $s): array
    {
        $width = $this->inner();
        $blocks = [];
        if (!empty($s['photo']) && is_file($s['photo'])) {
            $blocks[] = ['type' => 'photo', 'file' => $s['photo'], 'crop' => $s['photo_crop'] ?? null, 'h' => CommonsPhotos::BOX[1], 'flag' => $s['flag'] ?? null];
            if (!empty($s['credit'])) {
                $blocks[] = $this->textBlock('Photo: '.$s['credit'], 'mono', 17, self::SOFT, 1, 26, 'left', 12);
            }
        } elseif (!empty($s['flag'])) {
            $blocks[] = ['type' => 'flags', 'files' => [$s['flag']], 'w' => 320, 'h' => 240, 'gap' => 0, 'mt' => 8];
        }
        $blocks[] = $this->textBlock($s['eyebrow'] ?? '', 'mono', 24, self::SOFT, 1, 34, 'left', 26);
        $blocks[] = $this->textBlock($s['title'] ?? '', 'display', 88, self::INK, 2, 100, 'left', 6, shrinkTo: 60);
        $line = implode(' · ', array_filter([$s['label'] ?? null, $s['club'] ?? null]));
        if ($line !== '') {
            $blocks[] = $this->textBlock($line, 'body', 32, self::INK, 2, 42, 'left', 10);
        }
        if (!empty($s['claim'])) {
            $blocks[] = ['type' => 'band', 'colour' => self::SUN, 'mt' => 18, 'h' => 0, 'inner' => $this->textBlock($s['claim'], 'display', 52, self::INK, 2, 62, 'left', 0, shrinkTo: 40, width: $width - 40)];
        }

        return $blocks;
    }

    private function flagsBlocks(array $s): array
    {
        $files = array_values(array_filter([$s['home_flag'] ?? null, $s['away_flag'] ?? null]));
        $blocks = [$this->textBlock($s['eyebrow'] ?? '', 'mono', 24, self::SOFT, 1, 34, 'center', 0)];
        if ($files) {
            $blocks[] = ['type' => 'flags', 'files' => $files, 'w' => 300, 'h' => 225, 'gap' => 56, 'mt' => 28];
        }
        $blocks[] = $this->textBlock($s['home'] ?? '', 'display', 92, self::INK, 1, 104, 'center', 30, shrinkTo: 56);
        $blocks[] = $this->textBlock($s['result'] ?? 'v', 'display', !empty($s['result']) ? 120 : 64, self::RED, 1, !empty($s['result']) ? 130 : 76, 'center', 6);
        $blocks[] = $this->textBlock($s['away'] ?? '', 'display', 92, self::INK, 1, 104, 'center', 6, shrinkTo: 56);
        if (!empty($s['claim'])) {
            $blocks[] = $this->textBlock($s['claim'], 'body', 34, self::INK, 2, 44, 'center', 24);
        }

        return $blocks;
    }

    private function fixtureBlocks(array $s): array
    {
        $colours = $s['colours'] ?? ['#0B0B0B', '#FFD23F'];
        $blocks = [$this->textBlock($s['eyebrow'] ?? '', 'mono', 24, self::SOFT, 2, 34, 'left', 0)];
        $blocks[] = $this->textBlock($s['home'] ?? '', 'display', 96, self::INK, 2, 104, 'left', 24, shrinkTo: 60);
        $blocks[] = ['type' => 'rule', 'colour' => $this->rgb($colours[0]), 'h' => 14, 'mt' => 6, 'w' => 220];
        $result = $s['result'] ?? null;
        $blocks[] = $this->textBlock($result ?? 'v', 'display', $result ? 150 : 70, self::RED, 1, $result ? 156 : 82, 'left', 14);
        $blocks[] = $this->textBlock($s['away'] ?? '', 'display', 96, self::INK, 2, 104, 'left', 10, shrinkTo: 60);
        $blocks[] = ['type' => 'rule', 'colour' => $this->rgb($colours[1] ?? $colours[0]), 'h' => 14, 'mt' => 6, 'w' => 220];
        foreach (array_slice($s['lines'] ?? [], 0, 6) as $i => $line) {
            $blocks[] = $this->textBlock($line, 'body', 34, self::INK, 2, 44, 'left', $i === 0 ? 30 : 8);
        }

        return $blocks;
    }

    /** The players slide with as many rows as fit the card (four at most). */
    private function fittingPlayers(array $s): array
    {
        $room = self::CARD_BOTTOM - self::CARD_TOP - 2 * self::PAD;
        for ($n = min(4, count($s['rows'] ?? [])); $n > 1; $n--) {
            $blocks = $this->playersBlocks(['rows' => array_slice($s['rows'], 0, $n)] + $s);
            if (array_sum(array_map(fn ($b) => $this->blockHeight($b), $blocks)) <= $room) {
                return $blocks;
            }
        }

        return $this->playersBlocks(['rows' => array_slice($s['rows'] ?? [], 0, 1)] + $s);
    }

    private function playersBlocks(array $s): array
    {
        $blocks = [$this->textBlock($s['eyebrow'] ?? '', 'mono', 24, self::SOFT, 1, 34, 'left', 0)];
        $blocks[] = $this->textBlock($s['title'] ?? '', 'display', 60, self::INK, 3, 68, 'left', 10, shrinkTo: 44);
        $rows = array_slice($s['rows'] ?? [], 0, 4);
        $tight = count($rows) > 3;
        foreach ($rows as $row) {
            $blocks[] = ['type' => 'rule', 'colour' => self::INK, 'h' => 4, 'mt' => $tight ? 18 : 26, 'w' => $this->inner()];
            $blocks[] = $this->textBlock($row['name'] ?? '', 'display', $tight ? 46 : 54, self::INK, 1, $tight ? 54 : 62, 'left', 12, shrinkTo: 36);
            $line = implode(' · ', array_filter([$row['label'] ?? null, $row['club'] ?? null]));
            if ($line !== '') {
                $blocks[] = $this->textBlock($line, 'body', $tight ? 27 : 30, self::SOFT, 2, $tight ? 34 : 38, 'left', 4);
            }
            if (!empty($row['stats'])) {
                $blocks[] = $this->textBlock($row['stats'], 'mono', $tight ? 25 : 28, self::RED, 2, $tight ? 32 : 36, 'left', 4);
            }
        }

        return $blocks;
    }

    private function formBlocks(array $s): array
    {
        $blocks = [$this->textBlock($s['eyebrow'] ?? '', 'mono', 24, self::SOFT, 1, 34, 'left', 0)];
        $blocks[] = $this->textBlock($s['title'] ?? '', 'display', 60, self::INK, 2, 68, 'left', 10, shrinkTo: 44);
        foreach (array_slice($s['rows'] ?? [], 0, 2) as $row) {
            $blocks[] = ['type' => 'rule', 'colour' => self::INK, 'h' => 4, 'mt' => 30, 'w' => $this->inner()];
            $blocks[] = $this->textBlock($row['team'] ?? '', 'display', 62, self::INK, 1, 72, 'left', 14, shrinkTo: 40);
            if (!empty($row['form'])) {
                $blocks[] = ['type' => 'form', 'letters' => str_split(substr($row['form'], -5)), 'size' => 84, 'mt' => 14];
            }
            if (!empty($row['standing'])) {
                $blocks[] = $this->textBlock($row['standing'], 'body', 34, self::INK, 1, 44, 'left', 14);
            }
        }

        return $blocks;
    }

    private function ctaBlocks(array $s): array
    {
        return [
            $this->textBlock($s['question'] ?? '', 'display', 92, self::INK, 4, 102, 'left', 0, shrinkTo: 60),
            ['type' => 'rule', 'colour' => self::RED, 'h' => 14, 'mt' => 30, 'w' => 220],
            $this->textBlock($s['cta'] ?? '', 'body', 38, self::INK, 3, 50, 'left', 34),
            ['type' => 'pill', 'mt' => 30, 'inner' => $this->textBlock($s['path'] ?? 'matchday.africa', 'mono', 30, self::SUN, 1, 40, 'left', 0, width: $this->inner() - 48)],
        ];
    }

    /** A text block: wrapped to $maxLines (stepping the size down to $shrinkTo first), then ellipsised. */
    private function textBlock(string $text, string $face, int $size, array $rgb, int $maxLines, int $lineHeight, string $align, int $mt, ?int $shrinkTo = null, ?int $width = null): array
    {
        $width ??= $this->inner();
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $shrinkTo ??= $size;
        while (true) {
            $lines = $this->wrap($text, $face, $size, $width);
            if (count($lines) <= $maxLines || $size <= $shrinkTo) {
                break;
            }
            $lineHeight = (int) round($lineHeight * ($size - 4) / $size);
            $size -= 4;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->fit($lines[$maxLines - 1].'…', $face, $size, $width);
        }
        $lines = array_map(fn ($l) => $this->fit($l, $face, $size, $width), $lines);
        // Line boxes from the font's real ascent and descent (GD draws points at 96 dpi; Anton is tall).
        [$ascent, $descent] = $this->metrics($face, $size);
        $lineHeight = max($lineHeight, $ascent + $descent + (int) round($size * 0.12));

        return ['type' => 'text', 'lines' => $text === '' ? [] : $lines, 'face' => $face, 'size' => $size, 'rgb' => $rgb,
            'lh' => $lineHeight, 'ascent' => $ascent, 'align' => $align, 'mt' => $text === '' ? 0 : $mt, 'h' => $text === '' ? 0 : count($lines) * $lineHeight];
    }

    // ---------------------------------------------------------------- drawing

    private function card(GdImage $img, array $blocks): void
    {
        $left = (int) config('slideshow.safe.left', 140);
        $right = (int) config('slideshow.safe.right', 940);
        $room = self::CARD_BOTTOM - self::CARD_TOP - 2 * self::PAD;
        $height = fn () => array_sum(array_map(fn ($b) => $this->blockHeight($b), $blocks));
        // A tall hook slide gives up photo height first.
        foreach ($blocks as $i => $b) {
            if (($b['type'] ?? '') === 'photo' && ($over = $height() - $room) > 0) {
                $blocks[$i]['h'] = max(380, $b['h'] - $over);
            }
        }
        $content = $height();
        $cardH = min(self::CARD_BOTTOM - self::CARD_TOP, $content + 2 * self::PAD);
        $top = (int) round(self::CARD_TOP + ((self::CARD_BOTTOM - self::CARD_TOP) - $cardH) / 2);
        $bottom = $top + $cardH;

        imagefilledrectangle($img, $left + 16, $top + 16, $right + 16, $bottom + 16, $this->color($img, self::INK));
        imagefilledrectangle($img, $left, $top, $right, $bottom, $this->color($img, self::INK));
        imagefilledrectangle($img, $left + self::BORDER, $top + self::BORDER, $right - self::BORDER, $bottom - self::BORDER, $this->color($img, self::CREAM));

        $x = $left + self::PAD;
        $y = $top + self::PAD;
        foreach ($blocks as $b) {
            $y += $b['mt'] ?? 0;
            $y = $this->drawBlock($img, $b, $x, $y, $left, $right);
        }
    }

    private function blockHeight(array $b): int
    {
        return match ($b['type']) {
            'band' => ($b['mt'] ?? 0) + $b['inner']['h'] + 36,
            'pill' => ($b['mt'] ?? 0) + $b['inner']['h'] + 36,
            'form' => ($b['mt'] ?? 0) + $b['size'],
            'photo' => ($b['mt'] ?? 0) + $b['h'] - self::PAD + self::BORDER,
            default => ($b['mt'] ?? 0) + $b['h'],
        };
    }

    /** Draws one block with its top at $y; returns the y below it. */
    private function drawBlock(GdImage $img, array $b, int $x, int $y, int $left, int $right): int
    {
        $width = $this->inner();
        switch ($b['type']) {
            case 'text':
                foreach ($b['lines'] as $i => $line) {
                    $box = imagettfbbox($b['size'], 0, $this->font($b['face']), $line);
                    $tw = $box[2] - $box[0];
                    $tx = $b['align'] === 'center' ? (int) round($x + ($width - $tw) / 2) - $box[0] : $x - $box[0];
                    // Baseline so the line's cap height sits inside its line box.
                    $base = $y + $i * $b['lh'] + $b['ascent'];
                    $this->text($img, $b['face'], $b['size'], $b['rgb'], $tx, $base, $line);
                }

                return $y + $b['h'];
            case 'photo':
                // Edge to edge inside the card border, from the card's top border down.
                $top = $y - self::PAD + self::BORDER;
                $this->photo($img, $b['file'], $b['crop'] ?? null, $left + self::BORDER, $top, $right - $left - 2 * self::BORDER, $b['h']);
                imagefilledrectangle($img, $left + self::BORDER, $top + $b['h'], $right - self::BORDER, $top + $b['h'] + self::BORDER - 1, $this->color($img, self::INK));
                if (!empty($b['flag']) && is_file($b['flag'])) {
                    $this->flag($img, $b['flag'], $x, $top + $b['h'] - 120 - 24, 160, 120);
                }

                return $top + $b['h'] + self::BORDER;
            case 'flags':
                $n = count($b['files']);
                $total = $n * $b['w'] + ($n - 1) * $b['gap'];
                $fx = (int) round($x + ($width - $total) / 2);
                foreach ($b['files'] as $i => $file) {
                    $this->flag($img, $file, $fx + $i * ($b['w'] + $b['gap']), $y, $b['w'], $b['h']);
                }

                return $y + $b['h'];
            case 'rule':
                imagefilledrectangle($img, $x, $y, $x + min($width, $b['w']) - 1, $y + $b['h'] - 1, $this->color($img, $b['colour']));

                return $y + $b['h'];
            case 'band':
                imagefilledrectangle($img, $x, $y, $x + $width - 1, $y + $b['inner']['h'] + 35, $this->color($img, $b['colour']));
                imagesetthickness($img, 4);
                imagerectangle($img, $x, $y, $x + $width - 1, $y + $b['inner']['h'] + 35, $this->color($img, self::INK));
                imagesetthickness($img, 1);
                $this->drawBlock($img, $b['inner'], $x + 20, $y + 18, $left, $right);

                return $y + $b['inner']['h'] + 36;
            case 'pill':
                $inner = $b['inner'];
                $box = imagettfbbox($inner['size'], 0, $this->font($inner['face']), $inner['lines'][0] ?? '');
                $pw = min($width, $box[2] - $box[0] + 48);
                imagefilledrectangle($img, $x, $y, $x + $pw, $y + $inner['h'] + 35, $this->color($img, self::INK));
                $this->drawBlock($img, $inner, $x + 24, $y + 18, $left, $right);

                return $y + $inner['h'] + 36;
            case 'form':
                $size = $b['size'];
                foreach ($b['letters'] as $i => $letter) {
                    $fx = $x + $i * ($size + 14);
                    $fill = ['W' => self::GREEN, 'D' => self::GREY, 'L' => self::RED][$letter] ?? self::GREY;
                    imagefilledrectangle($img, $fx, $y, $fx + $size, $y + $size, $this->color($img, self::INK));
                    imagefilledrectangle($img, $fx + 5, $y + 5, $fx + $size - 5, $y + $size - 5, $this->color($img, $fill));
                    $box = imagettfbbox(44, 0, $this->font('display'), $letter);
                    $this->text($img, 'display', 44, self::CREAM, (int) round($fx + ($size - ($box[2] - $box[0])) / 2) - $box[0], $y + (int) round($size * 0.74), $letter);
                }

                return $y + $size;
        }

        return $y;
    }

    /** The two sides' colours split on a diagonal (home top left, away bottom right), with an ink seam. */
    private function background(GdImage $img, int $w, int $h, string $home, string $away): void
    {
        imagefill($img, 0, 0, $this->color($img, $this->rgb($away)));
        $a = (int) round($h * 0.64);
        $b = (int) round($h * 0.36);
        imagefilledpolygon($img, [0, 0, $w, 0, $w, $b, 0, $a], $this->color($img, $this->rgb($home)));
        imagesetthickness($img, 12);
        imageline($img, 0, $a, $w, $b, $this->color($img, self::INK));
        imagesetthickness($img, 1);
    }

    private function footer(GdImage $img, int $w): void
    {
        $label = 'matchday.africa';
        $size = 34;
        $box = imagettfbbox($size, 0, $this->font('mono'), $label);
        $tw = $box[2] - $box[0];
        $pw = $tw + 64;
        $x = (int) round(($w - $pw) / 2);
        $y = 1452;
        $ink = $this->color($img, self::INK);
        imagefilledrectangle($img, $x + 8, $y + 8, $x + $pw + 8, $y + 72 + 8, $ink);
        imagefilledrectangle($img, $x, $y, $x + $pw, $y + 72, $ink);
        imagefilledrectangle($img, $x + 5, $y + 5, $x + $pw - 5, $y + 67, $this->color($img, self::SUN));
        $this->text($img, 'mono', $size, self::INK, $x + 32 - $box[0], $y + 49, $label);
    }

    private function photo(GdImage $img, string $file, ?array $crop, int $x, int $y, int $w, int $h): void
    {
        $src = @imagecreatefromstring((string) file_get_contents($file));
        if (!$src) {
            return;
        }
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        // The stored crop is for the full window; a shorter window keeps its middle band (faces sit high, so lean up).
        $crop = $crop && ($crop['w'] ?? 0) > 0 ? $crop : CommonsPhotos::crop($sw, $sh, $w, $h);
        $ch = (int) min($crop['h'], round($crop['w'] * $h / $w));
        $cy = (int) ($crop['y'] + round(($crop['h'] - $ch) * 0.3));
        imagecopyresampled($img, $src, $x, $y, (int) $crop['x'], $cy, $w, $h, (int) $crop['w'], $ch);
        imagedestroy($src);
    }

    private function flag(GdImage $img, string $file, int $x, int $y, int $w, int $h): void
    {
        $src = @imagecreatefrompng($file);
        if (!$src) {
            return;
        }
        imagefilledrectangle($img, $x - 6 + 8, $y - 6 + 8, $x + $w + 6 + 8, $y + $h + 6 + 8, $this->color($img, self::INK));
        imagefilledrectangle($img, $x - 6, $y - 6, $x + $w + 6, $y + $h + 6, $this->color($img, self::INK));
        imagecopyresampled($img, $src, $x, $y, 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);
    }

    private function text(GdImage $img, string $face, int $size, array $rgb, int $x, int $y, string $text): void
    {
        $box = imagettftext($img, $size, 0, $x, $y, $this->color($img, $rgb), $this->font($face), $text);
        if ($box) {
            $this->boxes[] = [min($box[0], $box[6]), min($box[5], $box[7]), max($box[2], $box[4]), max($box[1], $box[3])];
        }
    }

    private function wrap(string $text, string $face, int $size, int $width): array
    {
        $rows = [];
        $row = '';
        foreach (preg_split('/\s+/', $text) as $word) {
            $try = $row === '' ? $word : "{$row} {$word}";
            if ($row !== '' && $this->width($try, $face, $size) > $width) {
                $rows[] = $row;
                $row = $word;
            } else {
                $row = $try;
            }
        }

        return array_merge($rows, [$row]);
    }

    private function fit(string $text, string $face, int $size, int $width): string
    {
        while (mb_strlen($text) > 2 && $this->width($text, $face, $size) > $width) {
            $text = rtrim(mb_substr(rtrim($text, '…'), 0, -1)).'…';
        }

        return $text;
    }

    private function width(string $text, string $face, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->font($face), $text);

        return $box[2] - $box[0];
    }

    /** [ascent, descent] in pixels for a face and size, measured on tall and deep glyphs. */
    private function metrics(string $face, int $size): array
    {
        $box = imagettfbbox($size, 0, $this->font($face), 'ÁÉHÔgjpqy|');

        return [abs(min($box[5], $box[7])), max($box[1], $box[3])];
    }

    private function inner(): int
    {
        return (int) config('slideshow.safe.right', 940) - (int) config('slideshow.safe.left', 140) - 2 * self::PAD;
    }

    private function font(string $face): string
    {
        return resource_path('fonts/'.['display' => 'Anton-Regular.ttf', 'body' => 'IBMPlexSans.ttf', 'mono' => 'IBMPlexMono-SemiBold.ttf'][$face]);
    }

    private function color(GdImage $img, array $rgb): int
    {
        return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
