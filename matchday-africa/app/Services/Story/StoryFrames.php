<?php

namespace App\Services\Story;

use App\Services\Slideshow\UnsafeLayout;

/**
 * Draws match-story and radio-video frames with GD: black and #FFD23F with each side's two
 * colours (never crests), a Ken Burns push on every cut's background, kinetic captions that slide
 * and fade in line by line, and a score counter that ticks on goals.
 *
 * Every text box is measured once in its resting place and must sit inside the TikTok safe area
 * (config story.safe: top 150, bottom 1540, right 940); otherwise UnsafeLayout is thrown and the
 * render fails rather than publish text under the app's buttons.
 */
class StoryFrames
{
    private const ANTON = 'fonts/Anton-Regular.ttf';
    private const BLACK = 'fonts/AsapCondensed-Black.ttf';
    private const BOLD = 'fonts/AsapCondensed-Bold.ttf';
    private const SEMI = 'fonts/AsapCondensed-SemiBold.ttf';

    private int $w;
    private int $h;
    private array $safe;
    private array $palette;
    private int $quality = 84;

    /** Where a scene's stack starts (the safe top, moved down to centre the stack). */
    private int $top = 150;

    public function __construct()
    {
        [$this->w, $this->h] = config('story.size', [1080, 1920]);
        $this->safe = config('story.safe');
        $this->palette = config('story.colours');
    }

    /**
     * One JPEG per frame for the whole timeline (exactly round(total * fps) frames). The zoomed
     * background is redrawn every config story.zoom_every frames, and a frame identical to the
     * previous one (no text moving, background unchanged) reuses its JPEG bytes, which keeps a
     * 70-second story near real time on one core.
     */
    public function frames(array $beats, int $fps, ?string &$cover = null): \Generator
    {
        $total = (int) round(array_sum(array_column($beats, 'seconds')) * $fps);
        $every = max(1, (int) config('story.zoom_every', 2));
        $frame = 0;
        foreach ($beats as $i => $beat) {
            $end = $i === count($beats) - 1 ? $total : (int) round(($beat['start'] + $beat['seconds']) * $fps);
            $count = max(0, $end - $frame);
            if ($count === 0) {
                continue;
            }
            [$bg, $items] = $this->scene($beat);
            $zoomIn = $i % 2 === 0;
            $still = $beat['type'] === 'radio'; // long radio editions: captions move, the background stays
            $layer = null;
            $layerKey = null;
            $last = null;
            $lastKey = null;
            for ($f = 0; $f < $count; $f++, $frame++) {
                // Entrances start a little way in, so the first frame of a cut is never empty.
                $t = $f / $fps + 0.08;
                $step = $still ? 0 : intdiv($f, $every);
                if ($step !== $layerKey) {
                    if ($layer) {
                        imagedestroy($layer);
                    }
                    $steps = max(1, intdiv($count - 1, $every));
                    $p = min(1.0, $step / $steps);
                    $layer = $this->kenBurns($bg, $still ? 0.0 : ($zoomIn ? $p : 1 - $p), $beat['type']);
                    $layerKey = $step;
                }
                $flash = $beat['type'] === 'goal' && $t < 0.1;
                $key = $step.'|'.($flash ? $f : '-').'|'.$this->state($items, $t);
                if ($last !== null && $key === $lastKey) {
                    yield $last;

                    continue;
                }
                $img = imagecreatetruecolor($this->w, $this->h);
                imagecopy($img, $layer, 0, 0, 0, 0, $this->w, $this->h);
                imagealphablending($img, true);
                $this->drawItems($img, $items, $t);
                // A brief yellow flash on the cut into a goal.
                if ($flash) {
                    imagefilledrectangle($img, 0, 0, $this->w, $this->h, imagecolorallocatealpha($img, 255, 210, 63, (int) (127 * min(1, $t / 0.1))));
                }
                ob_start();
                imagejpeg($img, null, $this->quality);
                $last = (string) ob_get_clean();
                $lastKey = $key;
                imagedestroy($img);
                if ($cover === null && $beat['type'] === 'hook' && $t >= min(1.4, ($count - 1) / $fps)) {
                    $cover = $last;
                }
                yield $last;
            }
            if ($cover === null && $beat['type'] === 'radio' && $last !== null) {
                $cover = $last;
            }
            if ($layer) {
                imagedestroy($layer);
            }
            imagedestroy($bg);
        }
    }

    /** What the text looks like at $t: each item's entrance progress (0 hidden, 1 at rest). */
    private function state(array $items, float $t): string
    {
        $out = '';
        foreach ($items as $item) {
            $p = $item['animate'] ? max(0.0, min(1.0, ($t - $item['delay']) / max(0.01, $item['fade']))) : 1.0;
            $out .= $p <= 0.0 ? '0' : ($p >= 1.0 ? '1' : sprintf('%.3f', $p)).',';
        }

        return $out;
    }

    /** A still of a beat at rest (all text in), for previews and the cover. */
    public function still(array $beat): string
    {
        [$bg, $items] = $this->scene($beat);
        $img = $this->kenBurns($bg, 0.0, $beat['type']);
        $this->drawItems($img, $items, 99.0);
        imagedestroy($bg);
        ob_start();
        imagejpeg($img, null, 88);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * Lays out a beat: [background image, text items]. Card scenes are laid out twice: once to
     * measure the stack, then moved down so it sits in the middle of the safe area. Checks every
     * item against the safe area.
     */
    public function scene(array $beat): array
    {
        $method = 'scene'.str_replace('_', '', ucwords((string) $beat['type'], '_'));
        if (!method_exists($this, $method)) {
            $method = 'sceneLines';
        }
        $this->top = $this->safe['top'];
        if (!in_array($beat['type'], ['hook', 'radio'], true)) {
            $scratch = imagecreatetruecolor($this->w, $this->h);
            $probe = [];
            $this->$method($scratch, $beat, $probe);
            imagedestroy($scratch);
            [$top, $bottom] = $this->extent($probe);
            if ($top !== null) {
                $mid = ($this->safe['top'] + $this->safe['bottom']) / 2;
                $shift = (int) max(0, min($mid - ($top + $bottom) / 2, $this->safe['bottom'] - $bottom));
                $this->top += $shift;
            }
        }
        $bg = imagecreatetruecolor($this->w, $this->h);
        imagealphablending($bg, true);
        $items = [];
        $this->$method($bg, $beat, $items);
        foreach ($items as $item) {
            $this->assertSafe($item, $beat['type']);
        }

        return [$bg, $items];
    }

    /** [top, bottom] of a laid-out stack: text boxes, shapes and marked areas. */
    private function extent(array $items): array
    {
        $top = null;
        $bottom = null;
        foreach ($items as $item) {
            $boxes = [];
            if (($item['text'] ?? '') !== '') {
                $boxes[] = [$item['top'], $item['bottom']];
            }
            if ($shape = $item['shape'] ?? null) {
                $boxes[] = [$shape[2], $shape[4]];
            }
            if (isset($item['mark'])) {
                $boxes[] = $item['mark'];
            }
            foreach ($boxes as [$a, $b]) {
                $top = $top === null ? $a : min($top, $a);
                $bottom = $bottom === null ? $b : max($bottom, $b);
            }
        }

        return [$top, $bottom];
    }

    /** An invisible item that only marks space a scene drew into the background (photos, bars). */
    private function mark(int $y1, int $y2): array
    {
        return ['text' => '', 'mark' => [$y1, $y2], 'animate' => false, 'delay' => 0.0, 'fade' => 0.35, 'flag' => null, 'shadow' => false, 'pop' => false];
    }

    // ------------------------------------------------------------------ scenes

    private function sceneHook(\GdImage $bg, array $b, array &$items): void
    {
        [$home, $away] = [$b['sides']['home'], $b['sides']['away']];
        $this->backdrop($bg, $home['colours'][0], $away['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 10;
        // Score strip with both flags (national teams) or kit swatches (clubs).
        $items[] = $this->scoreStrip($b['sides'], (string) $b['score'], $y, 0.0, StoryText::for($b['lang'] ?? 'en')->t('full_time'));
        $y += 120;
        $photoBox = 640;
        $px = (int) ($L + ($R - $L - $photoBox) / 2);
        $side = ($b['player'] && ($b['player']['club'] ?? null) === $away['name']) ? $away : $home;
        imagefilledrectangle($bg, $px - 14, $y - 14, $px + $photoBox + 14, $y + $photoBox + 14, $this->col($bg, $this->palette['yellow']));
        imagefilledrectangle($bg, $px - 6, $y - 6, $px + $photoBox + 6, $y + $photoBox + 6, $this->col($bg, $side['colours'][0]));
        if (!$this->photo($bg, $b['photo']['file'] ?? null, $b['photo']['crop'] ?? null, $px, $y, $photoBox, $photoBox)) {
            $this->monogram($bg, $b['player']['name'] ?? $home['name'], $b['player']['flag'] ?? $home['flag'], $px, $y, $photoBox, $side['colours']);
        }
        $y += $photoBox + 40 + 72;
        if ($b['player']) {
            $items[] = $this->text($b['player']['name'], self::ANTON, 54, $L, $y, $this->palette['paper'], 0.25, $R - $L - ($b['player']['flag'] ? 110 : 0), flag: $b['player']['flag'], flagRight: $R);
            $y += 74;
            $items[] = $this->text(implode(' · ', array_filter([$b['player']['label'], $b['player']['club']])), self::SEMI, 30, $L, $y, $this->palette['muted'], 0.35, $R - $L);
            $y += 64;
        }
        foreach ($this->fit($b['line'], self::ANTON, 72, 52, $R - $L, 3) as [$line, $size]) {
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y + (int) ($size * 1.25), $this->palette['yellow'], 0.45 + 0.12 * count($items), $R - $L, shadow: true);
            $y += (int) ($size * 1.42);
        }
        if (!empty($b['photo']['credit'])) {
            $items[] = $this->text('Photo: '.$b['photo']['credit'], self::SEMI, 18, $L, $this->safe['bottom'] - 6, $this->palette['muted'], 0.0, $R - $L);
        }
    }

    private function sceneBuildup(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 150;
        $items[] = $this->pill(mb_strtoupper($b['title']), $L, $y, 0.0);
        $y += 120;
        foreach ($this->fit($b['competition'], self::ANTON, 96, 60, $R - $L, 2) as [$line, $size]) {
            $y += (int) ($size * 1.3);
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y, $this->palette['paper'], 0.15, $R - $L);
        }
        if (!empty($b['stage'])) {
            $y += 80;
            $items[] = $this->text($b['stage'], self::BOLD, 44, $L, $y, $this->palette['yellow'], 0.3, $R - $L);
        }
        $y += 60;
        $y = $this->versus($bg, $b['sides'], $y + 20, $items, 0.45);
        foreach ($b['lines'] as $i => $line) {
            foreach ($this->fit($line, self::BOLD, 42, 32, $R - $L, 2) as [$part, $size]) {
                $y += (int) ($size * 1.45);
                $items[] = $this->text($part, self::BOLD, $size, $L, $y, $this->palette['paper'], 0.7 + 0.2 * $i, $R - $L);
            }
            $y += 16;
        }
    }

    private function sceneGoal(\GdImage $bg, array $b, array &$items): void
    {
        $t = StoryText::for($b['lang'] ?? 'en');
        $side = $b['sides'][$b['side']];
        $this->backdrop($bg, $side['colours'][0], $side['colours'][1] ?? $this->palette['ink'], focus: $b['side']);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 120;
        $kind = $b['own_goal'] ? $t->t('own_goal') : ($b['penalty'] ? $t->t('goal').' · '.$t->t('penalty') : $t->t('goal'));
        $items[] = $this->text($kind.'!', self::ANTON, 150, $L, $y + 170, $this->palette['yellow'], 0.0, $R - $L, shadow: true, pop: true);
        $y += 250;
        if ($b['minute_label']) {
            $items[] = $this->pill($b['minute_label'], $L, $y, 0.2);
            $y += 110;
        }
        $who = $b['player'] ?: $side['name'];
        foreach ($this->fit($who, self::ANTON, 96, 60, $R - $L, 2) as [$line, $size]) {
            $y += (int) ($size * 1.3);
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y, $this->palette['paper'], 0.35, $R - $L, shadow: true);
        }
        $y += 20;
        $line = $b['own_goal'] ? $t->t('own_goal_for', ['name' => (string) $b['player'], 'club' => $side['name']]) : $side['full'];
        foreach ($this->fit($line, self::BOLD, 42, 32, $R - $L, 2) as [$part, $size]) {
            $y += (int) ($size * 1.4);
            $items[] = $this->text($part, self::BOLD, $size, $L, $y, $this->palette['paper'], 0.5, $R - $L);
        }
        if ($b['assist'] && !$b['own_goal']) {
            $y += 64;
            $items[] = $this->text($t->t('assist', ['name' => $b['assist']]), self::SEMI, 38, $L, $y, $this->palette['yellow'], 0.65, $R - $L);
        }
        // The score counter: the old score, then the new one pops in.
        $items[] = $this->counter($b['sides'], $b['before'], $b['after'], $b['side'], min($y + 90, $this->safe['bottom'] - 300), 0.9);
    }

    private function sceneRed(\GdImage $bg, array $b, array &$items): void
    {
        $t = StoryText::for($b['lang'] ?? 'en');
        $side = $b['sides'][$b['side']];
        $this->backdrop($bg, '#C8102E', $side['colours'][0], focus: $b['side']);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 120;
        $card = $this->text('', self::ANTON, 10, $L, $y + 300, '#FFFFFF', 0.0, 10);
        $card['shape'] = ['rect', $L, $y, $L + 150, $y + 210, '#E10600'];
        $items[] = $card;
        $items[] = $this->text($t->t('red_card'), self::ANTON, 96, $L + 190, $y + 150, $this->palette['paper'], 0.1, $R - $L - 190, shadow: true);
        $y += 300;
        if ($b['minute_label']) {
            $items[] = $this->pill($b['minute_label'], $L, $y, 0.25);
            $y += 110;
        }
        foreach ($this->fit((string) ($b['player'] ?: $side['name']), self::ANTON, 96, 60, $R - $L, 2) as [$line, $size]) {
            $y += (int) ($size * 1.3);
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y, $this->palette['paper'], 0.4, $R - $L, shadow: true);
        }
        $y += 70;
        $items[] = $this->text($side['full'], self::BOLD, 42, $L, $y, $this->palette['yellow'], 0.55, $R - $L);
        $items[] = $this->counter($b['sides'], $b['after'], $b['after'], null, min($y + 90, $this->safe['bottom'] - 300), 0.8);
    }

    private function sceneStats(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 130;
        $items[] = $this->pill(mb_strtoupper($b['title']), $L, $y, 0.0);
        $y += 150;
        $y = $this->versus($bg, $b['sides'], $y, $items, 0.1) + 40;
        foreach ($b['rows'] as $i => $row) {
            $delay = 0.3 + 0.25 * $i;
            $total = max(1, $row['home'] + $row['away']);
            $suffix = $row['percent'] ? '%' : '';
            $items[] = $this->text($row['label'], self::BOLD, 36, $L + 300, $y + 40, $this->palette['muted'], $delay, $R - $L - 600, align: 'center', box: [$L + 150, $R - 150]);
            $items[] = $this->text($row['home'].$suffix, self::ANTON, 60, $L, $y + 56, $this->palette['paper'], $delay, 150);
            $items[] = $this->text($row['away'].$suffix, self::ANTON, 60, $R - 150, $y + 56, $this->palette['paper'], $delay, 150, align: 'right', box: [$R - 150, $R]);
            $bar = $this->text('', self::BOLD, 10, $L, $y + 96, '#FFFFFF', $delay, 10);
            $split = (int) ($L + ($R - $L) * $row['home'] / $total);
            $bar['shape'] = ['bar', $L, $y + 76, $R, $y + 96, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0], $split];
            $items[] = $bar;
            $y += 150;
        }
    }

    private function scenePlayer(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $this->palette['yellow']);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 40;
        $box = 520;
        $px = (int) ($L + ($R - $L - $box) / 2);
        imagefilledrectangle($bg, $px - 10, $y - 10, $px + $box + 10, $y + $box + 10, $this->col($bg, $this->palette['yellow']));
        if (!$this->photo($bg, $b['photo']['file'] ?? null, $b['photo']['crop'] ?? null, $px, $y, $box, $box)) {
            $this->monogram($bg, $b['name'], $b['flag'], $px, $y, $box, $b['sides']['home']['colours']);
        }
        $items[] = $this->mark($y - 10, $y + $box + 10);
        $y += $box + 40;
        foreach ($this->fit($b['name'], self::ANTON, 80, 56, $R - $L - ($b['flag'] ? 110 : 0), 2) as [$line, $size]) {
            $y += (int) ($size * 1.25);
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y, $this->palette['paper'], 0.0, $R - $L - ($b['flag'] ? 110 : 0), flag: $b['flag'], flagRight: $R);
        }
        $y += 50;
        $items[] = $this->text(implode(' · ', array_filter([$b['label'], $b['club']])), self::SEMI, 34, $L, $y, $this->palette['muted'], 0.15, $R - $L);
        $y += 30;
        foreach ($b['lines'] as $i => $line) {
            $y += 70;
            $items[] = $this->text(mb_strtoupper($line['head']), self::BOLD, 32, $L, $y, $this->palette['yellow'], 0.35 + 0.3 * $i, $R - $L);
            foreach ($this->fit($line['text'], self::BOLD, 46, 34, $R - $L, 2) as [$part, $size]) {
                $y += (int) ($size * 1.35);
                $items[] = $this->text($part, self::BOLD, $size, $L, $y, $this->palette['paper'], 0.45 + 0.3 * $i, $R - $L);
            }
        }
    }

    private function sceneH2h(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 130;
        $items[] = $this->pill(mb_strtoupper($b['title']), $L, $y, 0.0);
        $y += 120;
        $items[] = $this->text($b['sub'], self::BOLD, 38, $L, $y + 40, $this->palette['muted'], 0.1, $R - $L);
        $y += 90;
        $col = (int) (($R - $L) / 3);
        foreach ($b['counts'] as $i => $c) {
            $x = $L + $i * $col;
            $colour = $c['side'] ? $b['sides'][$c['side']]['colours'][0] : $this->palette['paper'];
            $items[] = $this->text((string) $c['n'], self::ANTON, 150, $x, $y + 230, $this->palette['yellow'], 0.25 + 0.15 * $i, $col - 10, align: 'center', box: [$x, $x + $col]);
            foreach ($this->fit($c['label'], self::BOLD, 32, 24, $col - 16, 2) as $j => [$part, $size]) {
                $items[] = $this->text($part, self::BOLD, $size, $x, $y + 295 + $j * 40, $this->palette['paper'], 0.3 + 0.15 * $i, $col - 10, align: 'center', box: [$x, $x + $col]);
            }
            imagefilledrectangle($bg, $x + 20, $y + 375, $x + $col - 20, $y + 385, $this->col($bg, $colour));
        }
        $items[] = $this->mark($y + 375, $y + 385);
        $y += 450;
        foreach ($b['lines'] as $i => $line) {
            foreach ($this->fit($line, self::BOLD, 38, 28, $R - $L, 2) as [$part, $size]) {
                $y += (int) ($size * 1.5);
                $items[] = $this->text($part, self::BOLD, $size, $L, $y, $this->palette['paper'], 0.8 + 0.2 * $i, $R - $L);
            }
        }
    }

    private function sceneForm(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 130;
        $items[] = $this->pill(mb_strtoupper($b['title']), $L, $y, 0.0);
        $y += 130;
        foreach (['home', 'away'] as $k => $sideKey) {
            $side = $b['sides'][$sideKey];
            $y += 90;
            $items[] = $this->text(mb_strtoupper($side['name']), self::ANTON, 72, $L, $y, $this->palette['paper'], 0.15 + 0.4 * $k, $R - $L);
            $y += 30;
            $form = array_reverse($b['form'][$sideKey] ?? []); // oldest first, left to right
            $cell = 140;
            foreach ($form as $i => $r) {
                $x = $L + $i * ($cell + 20);
                $chip = $this->text($r['result'], self::ANTON, 64, $x + 40, $y + 108, $r['result'] === 'W' ? $this->palette['ink'] : $this->palette['paper'], 0.3 + 0.4 * $k + 0.08 * $i, $cell);
                $chip['shape'] = ['rect', $x, $y, $x + $cell, $y + $cell, ['W' => '#FFD23F', 'D' => '#5C5C5C', 'L' => '#C8102E'][$r['result']] ?? '#5C5C5C'];
                $items[] = $chip;
                $items[] = $this->text($r['score'], self::BOLD, 28, $x, $y + $cell + 40, $this->palette['muted'], 0.3 + 0.4 * $k + 0.08 * $i, $cell, align: 'center', box: [$x, $x + $cell]);
            }
            if (!$form) {
                $items[] = $this->text('-', self::BOLD, 40, $L, $y + 80, $this->palette['muted'], 0.3, 100);
            }
            $y += $cell + 90;
        }
    }

    private function sceneTable(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $t = StoryText::for($b['lang'] ?? 'en');
        $y = $this->top + 130;
        $items[] = $this->pill(mb_strtoupper($b['title']), $L, $y, 0.0);
        $y += 140;
        $items[] = $this->text($t->t('played_short'), self::BOLD, 30, $R - 230, $y + 30, $this->palette['muted'], 0.1, 80, align: 'right', box: [$R - 250, $R - 170]);
        $items[] = $this->text(mb_strtoupper($t->t('pts')), self::BOLD, 30, $R - 100, $y + 30, $this->palette['muted'], 0.1, 100, align: 'right', box: [$R - 100, $R]);
        $y += 50;
        foreach ($b['rows'] as $i => $row) {
            $delay = 0.2 + 0.15 * $i;
            $hl = $row['side'] !== null;
            $rowBox = $this->text('', self::BOLD, 10, $L, $y + 100, '#FFFFFF', $delay, 10);
            $rowBox['shape'] = ['rect', $L - 10, $y, $R, $y + 104, $hl ? $b['sides'][$row['side']]['colours'][0] : '#1C1C1C'];
            $items[] = $rowBox;
            $fg = $hl ? $this->readable($b['sides'][$row['side']]['colours'][0]) : $this->palette['paper'];
            $items[] = $this->text((string) $row['pos'], self::ANTON, 54, $L + 10, $y + 76, $hl ? $fg : $this->palette['yellow'], $delay, 80);
            $items[] = $this->text($row['name'], self::BOLD, 46, $L + 110, $y + 70, $fg, $delay, $R - $L - 380);
            if ($row['played'] !== null) {
                $items[] = $this->text((string) $row['played'], self::BOLD, 44, $R - 250, $y + 70, $fg, $delay, 80, align: 'right', box: [$R - 250, $R - 170]);
            }
            if ($row['pts'] !== null) {
                $items[] = $this->text((string) $row['pts'], self::ANTON, 54, $R - 100, $y + 76, $fg, $delay, 100, align: 'right', box: [$R - 110, $R - 10]);
            }
            $y += 124;
        }
    }

    private function sceneNext(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 160;
        $items[] = $this->pill(mb_strtoupper($b['title']), $L, $y, 0.0);
        $y += 120;
        foreach ($b['lines'] as $i => $line) {
            $side = $b['sides'][$line['side']];
            $y += 60;
            imagefilledrectangle($bg, $L, $y, $L + 16, $y + 230, $this->col($bg, $side['colours'][0]));
            foreach ($this->fit($line['text'], self::ANTON, 76, 50, $R - $L - 40, 2) as [$part, $size]) {
                $y += (int) ($size * 1.3);
                $items[] = $this->text(mb_strtoupper($part), self::ANTON, $size, $L + 40, $y, $this->palette['paper'], 0.2 + 0.4 * $i, $R - $L - 40);
            }
            foreach ($this->fit($line['when'], self::BOLD, 38, 28, $R - $L - 40, 2) as $j => [$part, $size]) {
                $y += (int) ($size * ($j === 0 ? 1.9 : 1.4));
                $items[] = $this->text($part, self::BOLD, $size, $L + 40, $y, $this->palette['yellow'], 0.35 + 0.4 * $i, $R - $L - 40);
            }
            $y += 70;
        }
    }

    private function sceneBanter(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $this->palette['yellow'], $b['sides']['home']['colours'][0], plain: true);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 260;
        $items[] = $this->pill(mb_strtoupper($b['title']), $L, $y, 0.0, invert: true);
        $y += 110;
        foreach ($this->fit($b['line'], self::ANTON, 88, 54, $R - $L, 6) as $i => [$line, $size]) {
            $y += (int) ($size * 1.3);
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y, $this->palette['ink'], 0.15 + 0.12 * $i, $R - $L);
        }
    }

    private function sceneClose(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 170;
        $t = StoryText::for($b['lang'] ?? 'en');
        $items[] = $this->pill(mb_strtoupper($t->t('question')), $L, $y, 0.0);
        $y += 110;
        foreach ($this->fit($b['question'], self::ANTON, 86, 54, $R - $L, 4) as $i => [$line, $size]) {
            $y += (int) ($size * 1.3);
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y, $this->palette['paper'], 0.1 + 0.12 * $i, $R - $L, shadow: true);
        }
        $y += 95;
        $items[] = $this->text($b['cta'], self::BOLD, 42, $L, $y, $this->palette['muted'], 0.5, $R - $L);
        $y += 150;
        $items[] = $this->text(mb_strtoupper($b['follow']), self::ANTON, 78, $L, $y, $this->palette['yellow'], 0.8, $R - $L, shadow: true, pop: true);
        $y += 60;
        $url = $this->pill($b['url'], $L, $y, 1.0, size: 38);
        $items[] = $url;
    }

    /** Recaps, match facts and any beat that is a title with lines. */
    private function sceneLines(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $b['sides']['home']['colours'][0], $b['sides']['away']['colours'][0]);
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 150;
        $items[] = $this->pill(mb_strtoupper((string) ($b['title'] ?? '')), $L, $y, 0.0);
        $y += 120;
        if (!empty($b['score'])) {
            $items[] = $this->counter($b['sides'], $b['score'], $b['score'], null, $y, 0.1);
            $y += 330;
        }
        foreach ((array) ($b['lines'] ?? []) as $i => $line) {
            foreach ($this->fit((string) $line, self::BOLD, 46, 32, $R - $L, 2) as [$part, $size]) {
                $y += (int) ($size * 1.45);
                if ($y > $this->safe['bottom'] - 10) {
                    break 2;
                }
                $items[] = $this->text($part, self::BOLD, $size, $L, $y, $this->palette['paper'], 0.3 + 0.12 * $i, $R - $L);
            }
            $y += 10;
        }
    }

    /** Radio videos: the edition's cover photo or art, the edition label and the caption being spoken. */
    private function sceneRadio(\GdImage $bg, array $b, array &$items): void
    {
        $this->backdrop($bg, $this->palette['yellow'], '#1B1B1B');
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $y = $this->top + 20;
        $items[] = $this->text('MATCHDAY RADIO', self::ANTON, 60, $L, $y + 70, $this->palette['yellow'], 0.0, $R - $L, animate: false);
        $items[] = $this->text((string) ($b['edition'] ?? ''), self::SEMI, 30, $L, $y + 120, $this->palette['muted'], 0.0, $R - $L, animate: false);
        $y += 160;
        $box = 600;
        $px = (int) ($L + ($R - $L - $box) / 2);
        imagefilledrectangle($bg, $px - 10, $y - 10, $px + $box + 10, $y + $box + 10, $this->col($bg, $this->palette['yellow']));
        if (!$this->photo($bg, $b['photo']['file'] ?? null, $b['photo']['crop'] ?? null, $px, $y, $box, $box)
            && !$this->photo($bg, $b['art'] ?? null, null, $px, $y, $box, $box, contain: true)) {
            $this->monogram($bg, 'Matchday Radio', null, $px, $y, $box, ['#0B0B0B', '#FFD23F']);
        }
        $y += $box + 30;
        foreach ($this->fit((string) ($b['title'] ?? ''), self::ANTON, 54, 40, $R - $L, 2) as [$line, $size]) {
            $y += (int) ($size * 1.3);
            $items[] = $this->text(mb_strtoupper($line), self::ANTON, $size, $L, $y, $this->palette['paper'], 0.0, $R - $L, animate: false);
        }
        if (!empty($b['caption'])) {
            // Word by word: each word appears as it is (roughly) spoken across the cue.
            $words = $this->fit((string) $b['caption'], self::BLACK, 50, 36, $R - $L - 40, 4);
            $top = $this->safe['bottom'] - 330;
            $panel = $this->text('', self::BOLD, 10, $L, $top + 250, '#FFFFFF', 0.0, 10, animate: false);
            $panel['shape'] = ['rect', $L - 10, $top, $R, $top + 270, '#FFFFFF'];
            $items[] = $panel;
            $count = max(1, array_sum(array_map(fn ($l) => count(preg_split('/\s+/u', $l[0])), $words)));
            $per = max(0.05, ((float) ($b['seconds'] ?? 3.0) * 0.85) / $count);
            $k = 0;
            $ly = $top + 20;
            foreach ($words as [$line, $size]) {
                $ly += (int) ($size * 1.3);
                $x = $L + 20;
                foreach (preg_split('/\s+/u', $line) as $word) {
                    $item = $this->text($word, self::BLACK, $size, $x, $ly, $this->palette['ink'], $k * $per, $R - $x, fade: 0.12);
                    $items[] = $item;
                    $x += $item['width'] + (int) ($size * 0.32);
                    $k++;
                }
            }
        }
        $items[] = $this->pill('matchday.africa', $L, $this->safe['bottom'] - 410, 0.0, size: 28, animate: false);
        if (!empty($b['photo']['credit'])) {
            $items[] = $this->text('Photo: '.$b['photo']['credit'], self::SEMI, 18, $L, $this->safe['bottom'] - 345, $this->palette['muted'], 0.0, $R - $L, animate: false);
        }
    }

    // ------------------------------------------------------------------ building blocks

    /** A text item at its resting place; the baseline is $y. */
    private function text(string $text, string $font, int $size, int $x, int $y, string $colour, float $delay, int $maxWidth,
        bool $shadow = false, bool $pop = false, string $align = 'left', ?array $box = null, ?string $flag = null, ?int $flagRight = null,
        bool $animate = true, float $fade = 0.35): array
    {
        $file = resource_path($font);
        $size = $this->shrink($text, $file, $size, $maxWidth);
        $bb = $text !== '' ? imagettfbbox($size, 0, $file, $text) : [0, 0, 0, 0, 0, 0, 0, 0];
        $width = $bb[2] - $bb[0];
        if ($align !== 'left' && $box) {
            $x = $align === 'center' ? (int) ($box[0] + ($box[1] - $box[0] - $width) / 2) : $box[1] - $width;
        }

        return ['text' => $text, 'font' => $file, 'size' => $size, 'x' => $x, 'y' => $y, 'colour' => $colour, 'delay' => $delay,
            'shadow' => $shadow, 'pop' => $pop, 'width' => $width, 'top' => $y + min($bb[5], $bb[7]), 'bottom' => $y + max($bb[1], $bb[3]),
            'flag' => $flag, 'flag_x' => $flag ? min(($flagRight ?? $x + $width + 110) - 90, $x + $width + 24) : null, 'animate' => $animate, 'fade' => $fade];
    }

    private function pill(string $label, int $x, int $y, float $delay, bool $invert = false, int $size = 34, bool $animate = true): array
    {
        $file = resource_path(self::BOLD);
        $bb = imagettfbbox($size, 0, $file, $label);
        $w = ($bb[2] - $bb[0]) + 50;
        $h = (int) ($size * 1.9);
        $item = $this->text($label, self::BOLD, $size, $x + 25, $y + (int) ($h * 0.7), $invert ? $this->palette['yellow'] : $this->palette['ink'], $delay, 2000, animate: $animate);
        $item['shape'] = ['rect', $x, $y, $x + $w, $y + $h, $invert ? $this->palette['ink'] : $this->palette['yellow']];

        return $item;
    }

    /** "HOME 2-1 AWAY" with both sides' flags or two-colour swatches; the text item carries the swatches. */
    private function scoreStrip(array $sides, string $score, int $y, float $delay, string $label): array
    {
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $line = $sides['home']['name'].'  '.$score.'  '.$sides['away']['name'];
        $item = $this->text(mb_strtoupper($line), self::ANTON, 54, $L + 90, $y + 70, $this->palette['paper'], $delay, $R - $L - 180,
            align: 'center', box: [$L + 90, $R - 90]);
        $item['swatches'] = [[$L, $y + 14, $sides['home']], [$R - 70, $y + 14, $sides['away']]];
        $item['label'] = $label;

        return $item;
    }

    /** Both club names stacked with a "v" between (used under a pill). Returns the next y. */
    private function versus(\GdImage $bg, array $sides, int $y, array &$items, float $delay): int
    {
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        foreach (['home', 'away'] as $i => $k) {
            $y += 84;
            imagefilledrectangle($bg, $L, $y - 62, $L + 14, $y + 6, $this->col($bg, $sides[$k]['colours'][0]));
            $items[] = $this->text(mb_strtoupper($sides[$k]['full']), self::ANTON, 64, $L + 34, $y, $this->palette['paper'], $delay + 0.12 * $i, $R - $L - 34);
            if ($i === 0) {
                $y += 50;
                $items[] = $this->text('v', self::BOLD, 36, $L + 34, $y, $this->palette['yellow'], $delay + 0.06, 60);
            }
        }

        return $y + 30;
    }

    /** The big score: the old digits, then the new score pops in at $delay. */
    private function counter(array $sides, string $before, string $after, ?string $side, int $y, float $delay): array
    {
        $L = $this->safe['left'];
        $R = $this->safe['right'];
        $item = $this->text(str_replace('-', ' - ', $after), self::ANTON, 190, $L, $y + 240, $this->palette['paper'], $delay, $R - $L - 40,
            align: 'center', box: [$L, $R], shadow: true, pop: $before !== $after);
        $item['before'] = str_replace('-', ' - ', $before);
        $item['shape'] = ['bar', $L, $y, $R, $y + 300, $sides['home']['colours'][0], $sides['away']['colours'][0], (int) (($L + $R) / 2), 'alpha' => 70];
        $item['top'] = min($item['top'], $y);

        return $item;
    }

    /** Lines that fit $width, shrinking from $size toward $min; at most $maxLines lines. Returns [[line, size], ...]. */
    private function fit(string $text, string $font, int $size, int $min, int $width, int $maxLines): array
    {
        $file = resource_path($font);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }
        for ($s = $size; $s >= $min; $s -= 4) {
            $lines = $this->wrap($text, $file, $s, $width);
            if (count($lines) <= $maxLines) {
                return array_map(fn ($l) => [$l, $s], $lines);
            }
        }
        $lines = array_slice($this->wrap($text, $file, $min, $width), 0, $maxLines);
        $last = count($lines) - 1;
        $lines[$last] = rtrim($lines[$last], ' ,.;:').'…';

        return array_map(fn ($l) => [$l, $min], $lines);
    }

    private function wrap(string $text, string $file, int $size, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            $bb = imagettfbbox($size, 0, $file, $try);
            if ($line !== '' && ($bb[2] - $bb[0]) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /** A single line shrunk until it fits. */
    private function shrink(string $text, string $file, int $size, int $width): int
    {
        while ($text !== '' && $size > 14) {
            $bb = imagettfbbox($size, 0, $file, $text);
            if (($bb[2] - $bb[0]) <= $width) {
                break;
            }
            $size -= 2;
        }

        return $size;
    }

    private function assertSafe(array $item, string $type): void
    {
        $s = $this->safe;
        $boxes = [];
        if ($item['text'] !== '') {
            $boxes[] = [$item['x'], $item['top'], $item['x'] + $item['width'], $item['bottom']];
        }
        if ($item['flag'] ?? null) {
            $boxes[] = [$item['flag_x'], $item['y'] - 60, $item['flag_x'] + 90, $item['y']];
        }
        foreach ($boxes as [$x1, $y1, $x2, $y2]) {
            if ($x1 < $s['left'] - 1 || $x2 > $s['right'] + 1 || $y1 < $s['top'] - 1 || $y2 > $s['bottom'] + 1) {
                throw new UnsafeLayout(sprintf('Text "%s" in the %s cut leaves the safe area (%d,%d)-(%d,%d).',
                    mb_substr($item['text'], 0, 40), $type, $x1, $y1, $x2, $y2));
            }
        }
    }

    // ------------------------------------------------------------------ drawing

    /**
     * Ink background with dots, a slanted band of the first colour across the top margin and of the
     * second across the bottom margin, each edged in yellow. The bands stay out of the text area.
     */
    private function backdrop(\GdImage $img, string $a, string $b, ?string $focus = null, bool $plain = false): void
    {
        $w = $this->w;
        $h = $this->h;
        if ($plain) {
            imagefill($img, 0, 0, $this->col($img, $a));
            imagefilledpolygon($img, [0, 1500, $w, 1200, $w, $h, 0, $h], $this->col($img, $this->palette['ink']));
            imagefilledpolygon($img, [0, 1460, $w, 1160, $w, 1200, 0, 1500], $this->col($img, $b));

            return;
        }
        imagefill($img, 0, 0, $this->col($img, $this->palette['ink']));
        // Faint halftone dots for texture.
        $dot = $this->col($img, '#FFFFFF', 116);
        for ($yy = 40; $yy < $h; $yy += 56) {
            for ($xx = ($yy / 56) % 2 ? 28 : 0; $xx < $w; $xx += 56) {
                imagefilledellipse($img, $xx, $yy, 6, 6, $dot);
            }
        }
        // A soft glow of the first colour behind the stack.
        imagefilledellipse($img, (int) ($w * 0.85), (int) ($h * 0.3), 900, 900, $this->col($img, $a, 112));
        $top = $this->safe['top'];
        $bottom = $this->safe['bottom'];
        imagefilledpolygon($img, [0, 0, $w, 0, $w, $top - 90, 0, $top - 10], $this->col($img, $a));
        imagefilledpolygon($img, [0, $top - 10, $w, $top - 90, $w, $top - 76, 0, $top + 4], $this->col($img, $this->palette['yellow']));
        imagefilledpolygon($img, [0, $h, $w, $h, $w, $bottom + 70, 0, $bottom + 170], $this->col($img, $b));
        imagefilledpolygon($img, [0, $bottom + 170, $w, $bottom + 70, $w, $bottom + 56, 0, $bottom + 156], $this->col($img, $this->palette['yellow']));
    }

    /** Copies a licensed photo's crop into the box (cover fit). False when there is no readable file. */
    private function photo(\GdImage $img, ?string $file, ?array $crop, int $x, int $y, int $w, int $h, bool $contain = false): bool
    {
        if (!$file || !is_file($file)) {
            return false;
        }
        $src = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png' => @imagecreatefrompng($file),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default => @imagecreatefromjpeg($file),
        };
        if (!$src) {
            return false;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($contain) {
            $scale = min($w / $sw, $h / $sh);
            $dw = (int) ($sw * $scale);
            $dh = (int) ($sh * $scale);
            imagecopyresampled($img, $src, $x + (int) (($w - $dw) / 2), $y + (int) (($h - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        } else {
            $c = $crop && isset($crop['w'], $crop['h']) ? $crop : null;
            [$cx, $cy, $cw, $ch] = $c ? [(int) $c['x'], (int) $c['y'], (int) $c['w'], (int) $c['h']] : [0, 0, $sw, $sh];
            // Cover: trim the crop to the box's aspect ratio.
            $ratio = $w / $h;
            if ($cw / max(1, $ch) > $ratio) {
                $nw = (int) ($ch * $ratio);
                $cx += (int) (($cw - $nw) / 2);
                $cw = $nw;
            } else {
                $nh = (int) ($cw / $ratio);
                $ch = $nh;
            }
            imagecopyresampled($img, $src, $x, $y, max(0, $cx), max(0, $cy), $w, $h, min($cw, $sw), min($ch, $sh));
        }
        imagedestroy($src);

        return true;
    }

    /** No photo: the side's colours with initials and the flag. */
    private function monogram(\GdImage $img, string $name, ?string $flag, int $x, int $y, int $box, array $colours): void
    {
        imagefilledrectangle($img, $x, $y, $x + $box, $y + $box, $this->col($img, $colours[0]));
        imagefilledpolygon($img, [$x, $y + $box, $x + $box, $y + (int) ($box * 0.55), $x + $box, $y + $box], $this->col($img, $colours[1] ?? '#0B0B0B'));
        $initials = collect(preg_split('/\s+/u', trim($name)))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->join('');
        $file = resource_path(self::ANTON);
        $size = (int) ($box * 0.32);
        $bb = imagettfbbox($size, 0, $file, $initials);
        imagettftext($img, $size, 0, $x + (int) (($box - ($bb[2] - $bb[0])) / 2), $y + (int) ($box * 0.62), $this->col($img, $this->readable($colours[0])), $file, $initials);
        if ($flag && is_file($flag) && ($f = @imagecreatefrompng($flag))) {
            $fw = (int) ($box * 0.3);
            $fh = (int) ($fw * imagesy($f) / imagesx($f));
            imagecopyresampled($img, $f, $x + $box - $fw - 24, $y + 24, 0, 0, $fw, $fh, imagesx($f), imagesy($f));
            imagedestroy($f);
        }
    }

    /** The background with a slow push (1.00 → 1.06, eased) anchored a little above centre. */
    private function kenBurns(\GdImage $bg, float $p, string $type): \GdImage
    {
        $zoom = 1.0 + 0.06 * (0.5 - 0.5 * cos(M_PI * max(0.0, min(1.0, $p))));
        $cw = (int) round($this->w / $zoom);
        $ch = (int) round($this->h / $zoom);
        if ($cw >= $this->w) {
            $out = imagecreatetruecolor($this->w, $this->h);
            imagecopy($out, $bg, 0, 0, 0, 0, $this->w, $this->h);

            return $out;
        }
        // Crop and bilinear scale: about twice as fast as imagecopyresampled at this size.
        $crop = imagecrop($bg, ['x' => (int) (($this->w - $cw) / 2), 'y' => (int) (($this->h - $ch) * 0.4), 'width' => $cw, 'height' => $ch]);
        $out = imagescale($crop, $this->w, $this->h, IMG_BILINEAR_FIXED);
        imagedestroy($crop);

        return $out;
    }

    private function drawItems(\GdImage $img, array $items, float $t): void
    {
        foreach ($items as $item) {
            $p = $item['animate'] ? max(0.0, min(1.0, ($t - $item['delay']) / max(0.01, $item['fade']))) : 1.0;
            if ($p <= 0.0) {
                continue;
            }
            $ease = 1 - (1 - $p) ** 3;
            $dy = (int) round((1 - $ease) * 46);
            $alpha = (int) round(127 * (1 - $ease));
            if ($shape = $item['shape'] ?? null) {
                $this->shape($img, $shape, $ease, $alpha);
            }
            foreach ($item['swatches'] ?? [] as [$sx, $sy, $side]) {
                $this->swatch($img, $sx, $sy, $side, $alpha);
            }
            if ($item['text'] === '') {
                continue;
            }
            $text = $item['text'];
            $size = $item['size'];
            $x = $item['x'];
            if (isset($item['before'])) {
                // Score counter: the old score until $delay, then the new one pops in.
                if ($t < $item['delay']) {
                    $text = $item['before'];
                    $alpha = 0;
                    $dy = 0;
                } elseif ($item['pop']) {
                    $size = (int) round($size * (1 + 0.35 * (1 - $ease)));
                    $dy = 0;
                }
                $bb = imagettfbbox($size, 0, $item['font'], $text);
                $x = (int) (($this->safe['left'] + $this->safe['right'] - ($bb[2] - $bb[0])) / 2);
            } elseif ($item['pop']) {
                $size = max(10, (int) round($size * (1 + 0.25 * (1 - $ease))));
                $dy = 0;
            }
            if ($item['shadow']) {
                imagettftext($img, $size, 0, $x + 5, $item['y'] + $dy + 6, $this->col($img, '#000000', min(127, 60 + $alpha)), $item['font'], $text);
            }
            imagettftext($img, $size, 0, $x, $item['y'] + $dy, $this->col($img, $item['colour'], $alpha), $item['font'], $text);
            if ($item['flag'] && is_file($item['flag']) && $p >= 1.0) {
                $this->flag($img, $item['flag'], $item['flag_x'], $item['y'] - 58, 90);
            }
        }
    }

    private function shape(\GdImage $img, array $s, float $ease, int $alpha): void
    {
        $extra = $s['alpha'] ?? 0;
        $a = min(127, $alpha + $extra);
        if ($s[0] === 'rect') {
            imagefilledrectangle($img, $s[1], $s[2], (int) ($s[1] + ($s[3] - $s[1]) * $ease), $s[4], $this->col($img, $s[5], $a));
        } elseif ($s[0] === 'bar') {
            [$x1, $y1, $x2, $y2, $ca, $cb, $split] = array_slice($s, 1, 7);
            $mid = (int) ($x1 + ($split - $x1) * $ease);
            $end = (int) ($x2 - ($x2 - $split) * $ease);
            imagefilledrectangle($img, $x1, $y1, $mid, $y2, $this->col($img, $ca, $a));
            imagefilledrectangle($img, $end, $y1, $x2, $y2, $this->col($img, $cb, $a));
        }
    }

    /** A side's mark: its flag (African national teams) or a split two-colour square (clubs, no crests). */
    private function swatch(\GdImage $img, int $x, int $y, array $side, int $alpha): void
    {
        if ($side['flag'] && is_file($side['flag'])) {
            $this->flag($img, $side['flag'], $x, $y, 70);

            return;
        }
        imagefilledrectangle($img, $x, $y, $x + 70, $y + 70, $this->col($img, $side['colours'][0], $alpha));
        imagefilledpolygon($img, [$x, $y + 70, $x + 70, $y, $x + 70, $y + 70], $this->col($img, $side['colours'][1] ?? '#0B0B0B', $alpha));
    }

    private function flag(\GdImage $img, string $file, int $x, int $y, int $w): void
    {
        $f = @imagecreatefrompng($file);
        if (!$f) {
            return;
        }
        $h = (int) round($w * imagesy($f) / imagesx($f));
        imagecopyresampled($img, $f, $x, $y, 0, 0, $w, $h, imagesx($f), imagesy($f));
        imagedestroy($f);
    }

    private function col(\GdImage $img, string $hex, int $alpha = 0): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return imagecolorallocatealpha($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), max(0, min(127, $alpha)));
    }

    /** Ink or white, whichever reads better on $hex. */
    private function readable(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $l = 0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2));

        return $l > 150 ? $this->palette['ink'] : $this->palette['paper'];
    }
}
