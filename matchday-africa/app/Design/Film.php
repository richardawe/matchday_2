<?php

namespace App\Design;

use App\Services\Slideshow\UnsafeLayout;
use GdImage;

/**
 * The frame engine every vertical video renders through (match stories, radio videos,
 * slideshows). It takes a running order of cuts — ['component' => name, 'start', 'seconds', ...data]
 * — lays each out with the component library and draws one JPEG per frame:
 *
 *  - entrances from the motion tokens: words rise one by one, scores punch in and tick, bars grow,
 *    chips pop; the standard easing curve everywhere (Motion::ease);
 *  - Ken Burns on every photo, pushing in on even cuts and out on odd ones;
 *  - a diagonal colour-band wipe (the next card's accent with a yellow edge) into every cut after the first;
 *  - the MD logo at its fixed place on every frame.
 *
 * Before drawing it enforces what can be enforced in code: text inside the safe zone, the copy
 * rules, contrast pairs, images (licensed with a credit, or our own), a credit on every card that
 * shows a photo, and no card twice in one video. A broken rule throws (UnsafeLayout or
 * DesignViolation) and the render fails rather than publish it.
 */
class Film
{
    protected int $w;
    protected int $h;
    protected array $safe;
    protected int $quality = 84;

    public function __construct(protected Components $kit)
    {
        [$this->w, $this->h] = Tokens::canvas();
        $this->safe = Tokens::safe();
    }

    /** The component a cut uses (subclasses map their own beat types). */
    protected function component(array $cut): string
    {
        return (string) ($cut['component'] ?? $cut['type']);
    }

    /** The component's data for a cut (subclasses translate their beats). */
    protected function data(array $cut): array
    {
        return $cut;
    }

    /** Lays out one cut and checks it. */
    public function build(array $cut): Scene
    {
        $scene = $this->kit->make($this->component($cut), $this->data($cut));
        $this->check($scene);

        return $scene;
    }

    /**
     * [background, items] of a cut at rest (kept for callers that measure layouts).
     *
     * @return array{0: GdImage, 1: array}
     */
    public function scene(array $cut): array
    {
        $scene = $this->build($cut);
        $bg = imagecreatetruecolor($this->w, $this->h);
        imagecopy($bg, $scene->bg, 0, 0, 0, 0, $this->w, $this->h);
        $items = array_map(fn ($i) => array_diff_key($i, ['src' => 1]), $scene->items);
        $scene->destroy();

        return [$bg, $items];
    }

    /**
     * One JPEG per frame for the whole running order (exactly round(total * fps) frames). Photos
     * are re-zoomed every motion.ken_burns.every frames; a frame identical to the one before reuses
     * its JPEG bytes.
     */
    public function frames(array $cuts, int $fps, ?string &$cover = null): \Generator
    {
        $this->assertNoRepeats($cuts);
        $total = (int) round(array_sum(array_column($cuts, 'seconds')) * $fps);
        $every = max(1, (int) Tokens::get('motion.ken_burns.every'));
        $wipe = (float) Tokens::motion('transition');
        $frame = 0;
        $previous = null;
        foreach (array_values($cuts) as $i => $cut) {
            $end = $i === count($cuts) - 1 ? $total : (int) round(($cut['start'] + $cut['seconds']) * $fps);
            $count = max(0, $end - $frame);
            if ($count === 0) {
                continue;
            }
            $scene = $this->build($cut);
            $zoomIn = $i % 2 === 0;
            $hasPhotos = collect($scene->items)->contains('kind', 'photo') && !$scene->still;
            $last = null;
            $lastKey = null;
            $lastImg = null;
            $layers = [];
            for ($f = 0; $f < $count; $f++, $frame++) {
                $t = $f / $fps;
                $step = $hasPhotos ? intdiv($f, $every) : 0;
                $p = $hasPhotos ? min(1.0, $step / max(1, intdiv($count - 1, $every))) : 0.0;
                $wiping = $previous !== null && $t < $wipe && ($cut['wipe'] ?? true) !== false;
                $flash = $scene->flash && $t < (float) Tokens::motion('flash') + ($previous ? $wipe : 0) && !$wiping;
                $key = $step.'|'.($wiping || $flash ? $f : '-').'|'.$this->state($scene->items, $t);
                if ($last !== null && $key === $lastKey) {
                    yield $last;

                    continue;
                }
                $img = imagecreatetruecolor($this->w, $this->h);
                imagecopy($img, $scene->bg, 0, 0, 0, 0, $this->w, $this->h);
                imagealphablending($img, true);
                $this->drawItems($img, $scene->items, $t, $zoomIn ? $p : 1 - $p, $step, $layers);
                if ($flash) {
                    $since = $t - ($previous ? $wipe : 0);
                    imagefilledrectangle($img, 0, 0, $this->w, $this->h, Canvas::col($img, 'yellow', (int) (127 * min(1, max(0, $since) / (float) Tokens::motion('flash')))));
                }
                if ($wiping) {
                    $this->wipe($img, $previous, Motion::ease($t / $wipe), (string) $scene->accent);
                }
                Canvas::logo($img);
                ob_start();
                imagejpeg($img, null, $this->quality);
                $last = (string) ob_get_clean();
                $lastKey = $key;
                if ($lastImg) {
                    imagedestroy($lastImg);
                }
                $lastImg = $img;
                if ($cover === null && $i === 0 && $t >= min(1.4, ($count - 1) / $fps)) {
                    $cover = $last;
                }
                yield $last;
            }
            if ($cover === null && $i === 0 && $last !== null) {
                $cover = $last;
            }
            if ($previous) {
                imagedestroy($previous);
            }
            $previous = $lastImg;
            foreach ($layers as $layer) {
                imagedestroy($layer);
            }
            $scene->destroy();
        }
        if ($previous) {
            imagedestroy($previous);
        }
    }

    /** A cut at rest (all entrances done), as JPEG bytes: previews, posters, reference images. */
    public function still(array $cut, int $quality = 88): string
    {
        $img = $this->stillImage($cut);
        ob_start();
        imagejpeg($img, null, $quality);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function stillImage(array $cut, ?float $t = null): GdImage
    {
        $scene = $this->build($cut);
        $img = imagecreatetruecolor($this->w, $this->h);
        imagecopy($img, $scene->bg, 0, 0, 0, 0, $this->w, $this->h);
        imagealphablending($img, true);
        $layers = [];
        $this->drawItems($img, $scene->items, $t ?? 99.0, 0.0, 0, $layers);
        Canvas::logo($img);
        foreach ($layers as $layer) {
            imagedestroy($layer);
        }
        $scene->destroy();

        return $img;
    }

    /**
     * What the design check needs about each cut: component, card key, backdrop, every text box
     * with its font, size and colour, photos and their credits, the time it is on screen.
     */
    public function manifest(array $cuts): array
    {
        $out = [];
        foreach (array_values($cuts) as $cut) {
            $scene = $this->build($cut);
            $out[] = [
                'component' => $scene->component, 'key' => $scene->key, 'group' => (string) ($cut['group'] ?? $scene->key), 'start' => (float) $cut['start'], 'seconds' => (float) $cut['seconds'],
                'backdrop' => Canvas::hex($scene->backdrop), 'settled' => round($scene->settled, 2), 'credit' => $scene->credit,
                'photos' => array_map(fn ($p) => ['file' => $p['file'] ? basename((string) $p['file']) : null, 'credit' => $p['credit'], 'own' => $p['own'], 'box' => $p['box']], $scene->photos),
                'teams' => array_values(array_filter([data_get($cut, 'sides.home.name'), data_get($cut, 'sides.home.full'), data_get($cut, 'sides.away.name'), data_get($cut, 'sides.away.full')])),
                'team_colours' => array_values(array_unique(array_filter(array_merge(
                    (array) data_get($cut, 'sides.home.colours', []), (array) data_get($cut, 'sides.away.colours', []))))),
                'text' => collect($scene->items)->where('kind', 'text')->map(fn ($i) => [
                    'text' => $i['text'], 'font' => basename($i['font']), 'font_token' => $i['font_token'], 'style' => $i['style'], 'size' => $i['size'],
                    'colour' => $i['colour'], 'tone' => $i['tone'], 'on' => $i['on'], 'box' => [$i['x'], $i['top'], $i['x'] + $i['width'], $i['bottom']],
                ])->values()->all(),
            ];
            $scene->destroy();
        }

        return $out;
    }

    // ---------------------------------------------------------------- rules

    /** Safe zone for every text box and flag; a credit wherever a licensed photo shows. */
    protected function check(Scene $scene): void
    {
        $s = $this->safe;
        foreach ($scene->items as $item) {
            $boxes = [];
            if (($item['kind'] ?? '') === 'text' && $item['text'] !== '') {
                $boxes[] = [$item['x'], $item['top'], $item['x'] + $item['width'], $item['bottom']];
            }
            if ($item['flag'] ?? null) {
                $boxes[] = [$item['flag_x'], $item['y'] - 64, $item['flag_x'] + $item['flag_w'], $item['y']];
            }
            foreach ($boxes as [$x1, $y1, $x2, $y2]) {
                if ($x1 < $s['left'] - 1 || $x2 > $s['right'] + 1 || $y1 < $s['top'] - 1 || $y2 > $s['bottom'] + 1) {
                    throw new UnsafeLayout(sprintf('Text "%s" in the %s card leaves the safe area (%d,%d)-(%d,%d).',
                        mb_substr((string) $item['text'], 0, 40), $scene->component, $x1, $y1, $x2, $y2));
                }
            }
        }
        foreach ($scene->photos as $photo) {
            if (!$photo['own'] && trim((string) $scene->credit) === '') {
                throw new DesignViolation("The {$scene->component} card shows a photo without its credit line.");
            }
        }
    }

    /** The same card twice in one video is not allowed. */
    public function assertNoRepeats(array $cuts): void
    {
        $seen = [];
        foreach ($cuts as $i => $cut) {
            $key = Components::key($this->component($cut), $this->data($cut));
            if (isset($seen[$key])) {
                throw new DesignViolation("Cut {$i} repeats cut {$seen[$key]} ({$this->component($cut)}).");
            }
            $seen[$key] = $i;
        }
    }

    // ---------------------------------------------------------------- drawing

    /** A fingerprint of what moves at $t (entrance progress per item). */
    protected function state(array $items, float $t): string
    {
        $out = '';
        foreach ($items as $item) {
            if (!($item['animate'] ?? false)) {
                continue;
            }
            $p = Motion::progress($t, (float) $item['delay'], (float) $item['fade']);
            $out .= ($p <= 0.0 ? '0' : ($p >= 1.0 ? '1' : sprintf('%.3f', $p))).($item['kind'] === 'text' && isset($item['before']) && $t >= $item['delay'] ? 'n' : '').',';
        }

        return $out;
    }

    protected function drawItems(GdImage $img, array $items, float $t, float $zoom, int $step, array &$layers): void
    {
        foreach ($items as $n => $item) {
            $kind = $item['kind'] ?? 'text';
            if ($kind === 'photo') {
                $this->photo($img, $item, $zoom, $step, $layers, $n);

                continue;
            }
            $raw = ($item['animate'] ?? false) ? Motion::progress($t, (float) $item['delay'], (float) $item['fade']) : 1.0;
            $visible = $item['visible'] ?? false;
            if ($raw <= 0.0 && !$visible && !isset($item['before'])) {
                continue;
            }
            $e = Motion::ease($raw);
            $motion = (string) ($item['motion'] ?? 'rise');
            $alpha = in_array($motion, ['rise', 'fade', 'pop', 'punch'], true) && !$visible ? (int) round(127 * (1 - $e)) : 0;
            if ($kind === 'shape') {
                $this->shape($img, $item['shape'], $motion, $e, $alpha);
            } elseif ($kind === 'swatch') {
                Canvas::swatch($img, $item['side'], $item['x'], $item['y'], $item['size'], $alpha);
            } elseif ($kind === 'logo') {
                if ($e >= 0.5) {
                    Canvas::logo($img, $item['size'], $item['x'], $item['y']);
                }
            } else {
                $this->text($img, $item, $t, $motion, $e, $alpha);
            }
        }
    }

    protected function text(GdImage $img, array $item, float $t, string $motion, float $e, int $alpha): void
    {
        $text = (string) $item['text'];
        $size = (float) $item['size'];
        $x = (int) $item['x'];
        $dy = 0;
        if (isset($item['before'])) {
            // The score counter: the old score until its moment, then the new one punches in.
            if ($t < $item['delay']) {
                $text = (string) $item['before'];
                $e = 1.0;
            } elseif (!empty($item['pop'])) {
                $size = $size * (1 + ((float) Tokens::motion('punch') - 1) * (1 - $e));
            }
            $alpha = 0;
            [$tw] = Canvas::measure($text, $item['font'], $size);
            $x = (int) round(($this->safe['left'] + $this->safe['right'] - $tw) / 2);
        } elseif ($motion === 'punch' || $motion === 'pop') {
            // Visible-from-frame-0 text settles from slightly small, so it never leaves the safe zone.
            $scale = !empty($item['visible']) ? 1 - 0.08 * (1 - $e) : 1 + ((float) Tokens::motion('punch') - 1) * (1 - $e) * ($motion === 'pop' ? 0.5 : 1);
            $size *= $scale;
            [$tw] = Canvas::measure($text, $item['font'], $size);
            $x = (int) round($item['x'] + ($item['width'] - $tw) / 2);
            $dy = (int) round(($size - (float) $item['size']) * 0.3);
        } elseif ($motion === 'rise') {
            $dy = (int) round((1 - $e) * (float) Tokens::motion('lift'));
        }
        Canvas::text($img, $text, $item['font'], $size, $x, (int) $item['y'] + $dy, $item['colour'], $alpha);
        if (!empty($item['flag']) && is_file($item['flag']) && $e >= 1.0) {
            Canvas::flag($img, $item['flag'], (int) $item['flag_x'], (int) $item['y'] - 60, (int) $item['flag_w']);
        }
    }

    protected function shape(GdImage $img, array $s, string $motion, float $e, int $alpha): void
    {
        if ($s[0] === 'round') {
            [, $x1, $y1, $x2, $y2, $colour, $r] = $s;
            if ($motion === 'grow') {
                $x2 = (int) round($x1 + ($x2 - $x1) * $e);
                $alpha = 0;
            } elseif ($motion === 'pop') {
                $cx = ($x1 + $x2) / 2;
                $cy = ($y1 + $y2) / 2;
                $k = 0.6 + 0.4 * $e;
                [$x1, $x2] = [(int) round($cx - ($cx - $x1) * $k), (int) round($cx + ($x2 - $cx) * $k)];
                [$y1, $y2] = [(int) round($cy - ($cy - $y1) * $k), (int) round($cy + ($y2 - $cy) * $k)];
            }
            Canvas::roundRect($img, $x1, $y1, $x2, $y2, $r, $colour, $alpha);
        } elseif ($s[0] === 'bar') {
            [, $x1, $y1, $x2, $y2, $a, $b, $split] = $s;
            Canvas::rect($img, $x1, $y1, (int) round($x1 + ($split - $x1) * $e), $y2, $a);
            Canvas::rect($img, (int) round($x2 - ($x2 - $split) * $e), $y1, $x2, $y2, $b);
        }
    }

    /** Ken Burns: the photo's crop scaled into its box, pushed from motion.ken_burns.from to .to (eased). */
    protected function photo(GdImage $img, array $item, float $p, int $step, array &$layers, int $n): void
    {
        [$x, $y, $w, $h] = $item['box'];
        $key = "{$n}:{$step}";
        if (!isset($layers[$key])) {
            foreach (array_keys($layers) as $k) {
                if (str_starts_with((string) $k, "{$n}:") && $layers[$k]) {
                    imagedestroy($layers[$k]);
                    unset($layers[$k]);
                }
            }
            $from = (float) Tokens::get('motion.ken_burns.from');
            $to = (float) Tokens::get('motion.ken_burns.to');
            $zoom = $from + ($to - $from) * Motion::ease($p);
            $src = $item['src'];
            [$sw, $sh] = [imagesx($src), imagesy($src)];
            $cw = (int) round($sw / $zoom);
            $ch = (int) round($sh / $zoom);
            $crop = imagecrop($src, ['x' => intdiv($sw - $cw, 2), 'y' => (int) (($sh - $ch) * (float) Tokens::get('motion.ken_burns.anchor_y')), 'width' => $cw, 'height' => $ch]);
            $layers[$key] = imagescale($crop, $w, $h, IMG_BILINEAR_FIXED);
            imagedestroy($crop);
            if (!empty($item['shade'])) {
                imagealphablending($layers[$key], true);
                [$from, $to, $alpha] = $item['shade'];
                for ($row = max(0, $from - $y); $row < $h; $row++) {
                    $p = min(1.0, ($row + $y - $from) / max(1, $to - $from));
                    $a = (int) round(127 - 127 * $alpha * Motion::ease($p));
                    imageline($layers[$key], 0, $row, $w - 1, $row, imagecolorallocatealpha($layers[$key], 0, 0, 0, $a));
                }
            }
        }
        imagecopy($img, $layers[$key], $x, $y, 0, 0, $w, $h);
    }

    /**
     * The diagonal colour-band wipe: the previous card ahead of the band, the new card behind it,
     * a band of the accent colour with a yellow leading edge sweeping left to right.
     */
    protected function wipe(GdImage $img, GdImage $previous, float $p, string $accent): void
    {
        $band = (int) Tokens::get('motion.band.width');
        $slant = (float) Tokens::get('motion.band.slant');
        $edge = (int) Tokens::get('motion.band.edge');
        $reach = $this->w + $band + (int) ($this->h * $slant);
        $lead = (int) round(-$band + $p * ($reach + $band));
        // Leading edge at row y: x = lead - slant * (h - y) ... rows nearer the top are further behind.
        $strip = 16;
        for ($y = 0; $y < $this->h; $y += $strip) {
            $bx = (int) round($lead - $slant * ($y + $strip / 2));
            if ($bx < $this->w) {
                $from = max(0, $bx);
                imagecopy($img, $previous, $from, $y, $from, $y, $this->w - $from, min($strip, $this->h - $y));
            }
        }
        $top = [$lead, 0];
        $bottom = [$lead - (int) round($slant * $this->h), $this->h];
        $poly = fn (int $off, int $width) => [$top[0] - $off - $width, 0, $top[0] - $off, 0, $bottom[0] - $off, $this->h, $bottom[0] - $off - $width, $this->h];
        imagefilledpolygon($img, $poly($edge, $band - $edge), Canvas::col($img, $accent));
        imagefilledpolygon($img, $poly(0, $edge), Canvas::col($img, 'yellow'));
    }
}
