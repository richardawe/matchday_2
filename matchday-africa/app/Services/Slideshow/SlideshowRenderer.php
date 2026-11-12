<?php

namespace App\Services\Slideshow;

use GdImage;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Draws slideshow slides as 1080×1920 PNGs with GD, ported from the hand pipeline
 * (matchday/slideshows/build_slideshows.py): the home side's colours top left and the away side's
 * bottom right on a steep diagonal with a white seam and secondary-colour pinstripes, faint rays,
 * and a stack of centred blocks (date pill with flags, big flags or club colour tiles, a round
 * player portrait, a title, cream panels with team-coloured borders, the match card) ending in the
 * matchday.africa pill. Club names are text only: no crests or logos anywhere.
 *
 * The whole stack is centred in the TikTok safe area (an 800 px column, y 150–1540, centre 845). Every
 * drawn element is measured; the render fails with UnsafeLayout if the content leaves the safe area,
 * is off centre by more than 20 px across or 25 px down, or touches the right-hand 140 px.
 * Drawn at twice the size and scaled down, for smooth edges.
 */
class SlideshowRenderer
{
    private const W = 1080;
    private const H = 1920;
    private const SAFE_TOP = 150;
    private const SAFE_BOTTOM = 1540;
    private const SAFE_LEFT = 140;
    private const SAFE_RIGHT = 940;
    private const CENTRE_Y = 845;
    private const COL = 790;   // panel width; + 10 px shadow = the 800 px column
    private const SH = 10;     // shadow offset
    private const PAD = 40;    // panel padding
    private const K = 2;       // supersampling

    private const BLACK = [11, 11, 11];
    private const CREAM = [255, 248, 231];
    private const WHITE = [255, 255, 255];
    private const RED = [255, 75, 62];

    /** Content boxes drawn on the slide being rendered (logical px): [[x1, y1, x2, y2], …]. */
    private array $boxes = [];

    private float $s = 1.0;

    private GdImage $img;

    /**
     * Draws every slide into $dir as 01.png, 02.png … Returns [['file', 'boxes', 'bbox' => [l, t, r, b]], …].
     *
     * @throws UnsafeLayout when the content leaves the safe area or is off centre
     */
    public function render(array $slides, string $dir): array
    {
        File::ensureDirectoryExists($dir);
        $out = [];
        foreach (array_values($slides) as $i => $slide) {
            $img = $this->draw($slide);
            $bbox = $this->check($i + 1, $this->boxes);
            $file = sprintf('%s/%02d.png', $dir, $i + 1);
            imagepng($img, $file, 6);
            imagedestroy($img);
            $out[] = ['file' => $file, 'boxes' => $this->boxes, 'bbox' => $bbox];
        }

        return $out;
    }

    public function draw(array $slide): GdImage
    {
        $colours = $slide['colours'] ?? [];
        $home = $colours['home'] ?? ['#0B0B0B', '#FFD23F'];
        $away = $colours['away'] ?? ['#FFD23F', '#0B0B0B'];
        $blocks = array_merge($slide['blocks'] ?? [], [['footer']]);
        $spacing = (int) ($slide['spacing'] ?? 30);

        // Shrink the whole stack until it fits the safe height (long names, many rows).
        $room = self::SAFE_BOTTOM - self::SAFE_TOP - 10;
        for ($this->s = 1.0; $this->s > 0.62; $this->s -= 0.04) {
            $measured = array_map(fn ($b) => $this->block($b), $blocks);
            $total = array_sum(array_column($measured, 0)) + $spacing * $this->s * (count($measured) - 1);
            if ($total <= $room) {
                break;
            }
        }

        $big = imagecreatetruecolor(self::W * self::K, self::H * self::K);
        imagealphablending($big, true);
        $this->img = $big;
        $this->boxes = [];
        $this->background($home, $away);
        $y = (int) round(self::CENTRE_Y - $total / 2);
        foreach ($measured as [$h, $draw]) {
            $draw($y);
            $y += (int) round($h + $spacing * $this->s);
        }

        $img = imagecreatetruecolor(self::W, self::H);
        imagecopyresampled($img, $big, 0, 0, 0, 0, self::W, self::H, self::W * self::K, self::H * self::K);
        imagedestroy($big);

        return $img;
    }

    /**
     * Fails unless the content sits inside the safe area, centred: equal side margins within 20 px,
     * vertical centre within 25 px of 845, nothing in the right-hand 140 px. Returns the content box.
     */
    public function check(int $slide, array $boxes): array
    {
        if (!$boxes) {
            throw new UnsafeLayout("Slide {$slide} has no content.");
        }
        $l = min(array_column($boxes, 0));
        $t = min(array_column($boxes, 1));
        $r = max(array_column($boxes, 2));
        $b = max(array_column($boxes, 3));
        $problems = array_filter([
            $l < self::SAFE_LEFT ? "left edge {$l} < ".self::SAFE_LEFT : null,
            $r > self::SAFE_RIGHT + 1 ? "right edge {$r} > ".self::SAFE_RIGHT : null,
            $t < self::SAFE_TOP ? "top {$t} < ".self::SAFE_TOP : null,
            $b > self::SAFE_BOTTOM ? "bottom {$b} > ".self::SAFE_BOTTOM : null,
            abs($l - (self::W - $r)) > 20 ? "margins {$l}/".(self::W - $r).' differ by more than 20' : null,
            abs(($t + $b) / 2 - self::CENTRE_Y) > 25 ? 'vertical centre '.(($t + $b) / 2).' is more than 25 from '.self::CENTRE_Y : null,
        ]);
        if ($problems) {
            throw new UnsafeLayout("Slide {$slide}: ".implode('; ', $problems).'.');
        }

        return [(int) $l, (int) $t, (int) $r, (int) $b];
    }

    public function boxes(): array
    {
        return $this->boxes;
    }

    // ---------------------------------------------------------------- blocks: [height, draw(y)]

    private function block(array $b): array
    {
        return match ($b[0]) {
            'pill' => $this->pill((string) $b[1], $b['flags'] ?? [], $b['swatches'] ?? []),
            'teams' => $this->teams($b['home'], $b['away'], (string) ($b['middle'] ?? 'v')),
            'portrait' => $this->portrait($b),
            'title' => $this->title((string) $b[1], (int) ($b['size'] ?? 84)),
            'panel' => $this->panel($b['items'] ?? [], (string) ($b['border'] ?? '#0B0B0B'), (int) ($b['gap'] ?? 26)),
            'image' => $this->image((string) $b[1], (string) ($b['border'] ?? '#FFFFFF')),
            'footer' => $this->footer(),
            default => throw new RuntimeException('Unknown slide block: '.$b[0]),
        };
    }

    /** A cream pill with a small flag (or colour swatch) at each end. */
    private function pill(string $text, array $flags, array $swatches): array
    {
        $fw = $this->n(78);
        $fh = (int) round($fw * 3 / 4);
        $pad = $this->n(22);
        for ($fs = 40; ; $fs -= 2) {
            [$tw, $th] = $this->measure($text, 'bold', $this->n($fs));
            $tot = $fw * 2 + $tw + 4 * $pad;
            if ($tot <= self::COL + self::SH || $fs <= 26) {
                break;
            }
        }
        $size = $this->n($fs);
        $h = max($fh, $th) + 2 * $this->n(14);

        return [$h, function (int $y) use ($text, $flags, $swatches, $fw, $fh, $pad, $tot, $tw, $th, $h, $size) {
            $x = (int) round((self::W - $tot) / 2);
            $this->roundRect($x, $y, $x + $tot, $y + $h, $h / 2, self::CREAM, self::BLACK, 4);
            foreach ([0, 1] as $i) {
                $fx = $i === 0 ? $x + $pad : $x + $tot - $pad - $fw;
                $fy = (int) round($y + ($h - $fh) / 2);
                $this->flagOrSwatch($flags[$i] ?? null, $swatches[$i] ?? null, $fx, $fy, $fw, $fh, 2);
            }
            $this->textAt($text, 'bold', $size, self::BLACK, $x + 2 * $pad + $fw + (int) round(($tot - 2 * $fw - 4 * $pad - $tw) / 2), (int) round($y + ($h - $th) / 2));
        }];
    }

    /** Both sides big: flags for national teams, colour tiles for clubs, name tags underneath, "v" or the score between. */
    private function teams(array $home, array $away, string $middle): array
    {
        $w = $this->n(270);
        $fh = (int) round($w * 3 / 4);
        $vSize = $this->n($middle === 'v' ? 110 : 96);
        [$vw, $vh] = $this->measure($middle, 'display', $vSize);
        $gap = $this->n(36);
        // A name tag is never wider than its flag: the text steps down, then is shortened.
        $tagMax = $w + self::SH - $this->n(36);
        $tagSize = function (string $name) use ($tagMax) {
            for ($size = 56; $size > 28; $size -= 2) {
                if ($this->measure(mb_strtoupper($name), 'display', $this->n($size))[0] <= $tagMax) {
                    break;
                }
            }

            return $this->n($size);
        };
        $sizes = [$tagSize($home['name']), $tagSize($away['name'])];
        $home['name'] = $this->fit(mb_strtoupper($home['name']), 'display', $sizes[0], $tagMax);
        $away['name'] = $this->fit(mb_strtoupper($away['name']), 'display', $sizes[1], $tagMax);
        $tagH = max($this->measure('A', 'display', $sizes[0])[1], $this->measure('A', 'display', $sizes[1])[1]) + $this->n(22);
        $tot = 2 * ($w + self::SH) + 2 * $gap + $vw;

        return [$fh + $this->n(24) + $tagH + self::SH, function (int $y) use ($home, $away, $middle, $w, $fh, $vSize, $vw, $vh, $gap, $tot, $sizes, $tagH) {
            $x = (int) round((self::W - $tot) / 2);
            foreach ([$home, $away] as $i => $side) {
                $fx = $i === 0 ? $x : $x + $w + self::SH + 2 * $gap + $vw;
                $this->rect($fx + self::SH, $y + self::SH, $fx + $w + self::SH, $y + $fh + self::SH, self::BLACK);
                if (!empty($side['flag']) && is_file($side['flag'])) {
                    $this->flagOrSwatch($side['flag'], null, $fx, $y, $w, $fh, 0);
                } else {
                    $this->tile($side['colours'] ?? ['#0B0B0B', '#FFFFFF'], $fx, $y, $w, $fh);
                }
                $this->frame($fx, $y, $fx + $w, $y + $fh, self::WHITE, 6);
                $name = mb_strtoupper($side['name']);
                [$tw, $th] = $this->measure($name, 'display', $sizes[$i]);
                $ty = $y + $fh + $this->n(24);
                $cx = $fx + $w / 2;
                $this->roundRect((int) round($cx - $tw / 2 - $this->n(18)), $ty, (int) round($cx + $tw / 2 + $this->n(18)), $ty + $tagH, 8, self::CREAM, self::BLACK, 4);
                $this->textAt($name, 'display', $sizes[$i], self::BLACK, (int) round($cx - $tw / 2), (int) round($ty + ($tagH - $th) / 2));
            }
            $this->textAt($middle, 'display', $vSize, self::WHITE, $x + $w + self::SH + $gap, (int) round($y + $fh / 2 - $vh / 2), 6);
        }];
    }

    /** The featured player's photo in a round frame of the side's colours, a name tag, a line and the credit. */
    private function portrait(array $b): array
    {
        // No free-licence photo: the name tag alone, never an empty frame.
        $hasPhoto = !empty($b['file']) && is_file($b['file']);
        $size = $hasPhoto ? $this->n(270) : 0;
        $ring = $hasPhoto ? $this->n(36) : 0;
        $r = $size + $ring;
        $colours = $b['colours'] ?? ['#0B0B0B', '#FFD23F'];
        $nameSize = $this->fitSize((string) ($b['name'] ?? ''), 'display', 54, 36, self::COL - $this->n(40));
        $b['name'] = $this->fit((string) ($b['name'] ?? ''), 'display', $nameSize, self::COL - $this->n(40));
        [, $nh] = $this->measure('A', 'display', $nameSize);
        $line = trim((string) ($b['line'] ?? ''));
        $credit = trim((string) ($b['credit'] ?? ''));
        $lineSize = $this->fitSize($line, 'bold', 32, 22, self::COL);
        $creditSize = $this->fitSize($credit, 'reg', 24, 16, self::COL);
        $line = $this->fit($line, 'bold', $lineSize, self::COL - 12);
        $credit = $this->fit($credit, 'reg', $creditSize, self::COL - 8);
        $lineH = $line !== '' ? $this->measure('Ag', 'bold', $lineSize)[1] + $this->n(10) : 0;
        $creditH = $credit !== '' ? $this->measure('Ag', 'reg', $creditSize)[1] + $this->n(10) : 0;
        $h = ($hasPhoto ? $r + self::SH + $this->n(18) : 0) + $nh + $this->n(22) + $lineH + $creditH;

        return [$h, function (int $y) use ($b, $hasPhoto, $size, $ring, $r, $colours, $nameSize, $nh, $line, $credit, $lineSize, $creditSize, $lineH) {
            if ($hasPhoto) {
                $x = (int) round((self::W - $r) / 2);
                $this->ellipse($x + self::SH, $y + self::SH, $r, self::BLACK);
                $this->ellipse($x, $y, $r, $this->rgb($colours[0]));
                $this->ellipse($x + 10, $y + 10, $r - 20, $this->rgb($colours[1] ?? $colours[0]));
                $this->roundPhoto($b['file'], $b['crop'] ?? null, $x + (int) round($ring / 2), $y + (int) round($ring / 2), $size);
                $y += $r + self::SH;
            } else {
                $y -= $this->n(18);
            }
            $name = (string) ($b['name'] ?? '');
            [$tw, $th] = $this->measure($name, 'display', $nameSize);
            $ny = $y + $this->n(18);
            $this->roundRect((int) round(self::W / 2 - $tw / 2 - $this->n(20)), $ny, (int) round(self::W / 2 + $tw / 2 + $this->n(20)), $ny + $nh + $this->n(22), 8, self::CREAM, $this->rgb($colours[0]), 5);
            $this->textAt($name, 'display', $nameSize, self::BLACK, (int) round(self::W / 2 - $tw / 2), (int) round($ny + $this->n(11) + ($nh - $th) / 2));
            $cy = $ny + $nh + $this->n(22) + $this->n(10);
            if ($line !== '') {
                $this->centred($line, 'bold', $lineSize, self::WHITE, $cy, 3);
                $cy += $lineH;
            }
            if ($credit !== '') {
                $this->centred($credit, 'reg', $creditSize, self::WHITE, $cy, 2);
            }
        }];
    }

    private function title(string $text, int $px): array
    {
        $size = $this->n($px);
        $lines = $this->wrap($text, 'display', $size, self::COL);
        $lh = (int) round($this->ascent('display', $size) * 1.15);

        return [count($lines) * $lh, function (int $y) use ($lines, $size, $lh) {
            foreach ($lines as $i => $line) {
                $this->centred($line, 'display', $size, self::WHITE, $y + $i * $lh, 5);
            }
        }];
    }

    /** A cream panel with a team-coloured border holding badges, text and table rows. */
    private function panel(array $items, string $border, int $gap): array
    {
        $inner = self::COL - 2 * self::PAD;
        $parts = array_map(fn ($item) => $this->item($item, $inner), $items);
        $gapPx = $this->n($gap);
        $h = 2 * self::PAD + array_sum(array_column($parts, 0)) + $gapPx * max(0, count($parts) - 1);

        return [$h + self::SH, function (int $y) use ($parts, $border, $gapPx, $h) {
            $x = (int) round((self::W - (self::COL + self::SH)) / 2);
            $this->rect($x + self::SH, $y + self::SH, $x + self::COL + self::SH, $y + $h + self::SH, self::BLACK);
            $this->roundRect($x, $y, $x + self::COL, $y + $h, 10, self::CREAM, $this->rgb($border), 9);
            $yy = $y + self::PAD;
            foreach ($parts as [$ih, $draw]) {
                $draw($yy, $x, self::COL);
                $yy += $ih + $gapPx;
            }
        }];
    }

    private function item(array $item, int $inner): array
    {
        switch ($item[0]) {
            case 'badge':
                $size = $this->n(36);
                [$tw, $th] = $this->measure((string) $item[1], 'black', $size);
                [$px, $py] = [$this->n(22), $this->n(10)];

                return [$th + 2 * $py + 5, function (int $y, int $x0, int $w) use ($item, $size, $tw, $th, $px, $py) {
                    $bw = $tw + 2 * $px;
                    $bx = (int) round($x0 + ($w - $bw - 5) / 2);
                    $this->rect($bx + 5, $y + 5, $bx + $bw + 5, $y + $th + 2 * $py + 5, self::BLACK);
                    $this->rect($bx, $y, $bx + $bw, $y + $th + 2 * $py, $this->rgb((string) ($item['colour'] ?? '#FF4B3E')));
                    $this->frame($bx, $y, $bx + $bw, $y + $th + 2 * $py, self::BLACK, 4);
                    $this->textAt((string) $item[1], 'black', $size, self::WHITE, $bx + $px, $y + $py, 2);
                }];
            case 'text':
                $face = (string) ($item['face'] ?? 'med');
                $size = $this->n((int) ($item['size'] ?? 50));
                $lines = $this->wrap((string) $item[1], $face, $size, $inner);
                $lh = (int) round($this->ascent($face, $size) * (float) ($item['lead'] ?? 1.22));
                $rgb = isset($item['colour']) ? $this->rgb((string) $item['colour']) : self::BLACK;

                return [count($lines) * $lh, function (int $y, int $x0, int $w) use ($lines, $face, $size, $lh, $rgb) {
                    foreach ($lines as $i => $line) {
                        [$tw] = $this->measure($line, $face, $size);
                        $this->textAt($line, $face, $size, $rgb, (int) round($x0 + ($w - $tw) / 2), $y + $i * $lh);
                    }
                }];
            case 'table':
                $rows = array_slice($item['rows'] ?? [], 0, 6);
                $rh = $this->n(84);
                $size = $this->n(46);

                return [count($rows) * $rh, function (int $y, int $x0, int $w) use ($rows, $rh, $size) {
                    foreach ($rows as $i => $row) {
                        $ry = $y + $i * $rh;
                        $inset = $this->n(20);
                        if (!empty($row['colour'])) {
                            $c = $this->rgb($row['colour']);
                            $this->roundRect($x0 + $inset, $ry, $x0 + $w - $inset, $ry + $rh - $this->n(12), 8, $this->mix($c, self::WHITE, 0.78), $c, 5);
                            $this->rect($x0 + $inset, $ry, $x0 + $inset + $this->n(14), $ry + $rh - $this->n(12), $c);
                        }
                        $label = trim(($row['pos'] !== null && $row['pos'] !== '' ? $row['pos'].'  ' : '').$row['name']);
                        $label = $this->fit($label, 'bold', $size, $w - $this->n(200));
                        [, $th] = $this->measure('Ag', 'bold', $size);
                        $ty = (int) round($ry + ($rh - $this->n(12) - $th) / 2);
                        $this->textAt($label, 'bold', $size, self::BLACK, $x0 + $this->n(50), $ty);
                        $value = (string) ($row['value'] ?? '');
                        if ($value !== '') {
                            [$vw] = $this->measure($value, 'black', $size);
                            $this->textAt($value, 'black', $size, self::BLACK, $x0 + $w - $this->n(44) - $vw, $ty);
                        }
                    }
                }];
        }

        throw new RuntimeException('Unknown panel item: '.$item[0]);
    }

    /** An image (the site's crest-free match card) at the column's width, framed. */
    private function image(string $file, string $border): array
    {
        $src = is_file($file) ? @imagecreatefromstring((string) file_get_contents($file)) : false;
        if (!$src) {
            return [0, fn () => null];
        }
        $w = self::COL;
        $h = (int) round(imagesy($src) * $w / imagesx($src));

        return [$h + self::SH, function (int $y) use ($src, $w, $h, $border) {
            $x = (int) round((self::W - ($w + self::SH)) / 2);
            $this->rect($x + self::SH, $y + self::SH, $x + $w + self::SH, $y + $h + self::SH, self::BLACK);
            imagecopyresampled($this->img, $src, $x * self::K, $y * self::K, 0, 0, $w * self::K, $h * self::K, imagesx($src), imagesy($src));
            $this->boxes[] = [$x, $y, $x + $w, $y + $h];
            $this->frame($x, $y, $x + $w, $y + $h, $this->rgb($border), 6);
            imagedestroy($src);
        }];
    }

    private function footer(): array
    {
        $size = $this->n(36);
        [$tw, $th] = $this->measure('matchday.africa', 'bold', $size);
        $h = $th + $this->n(24);

        return [$h, function (int $y) use ($size, $tw, $th, $h) {
            $x = (int) round((self::W - $tw - $this->n(48)) / 2);
            $this->roundRect($x, $y, $x + $tw + $this->n(48), $y + $h, $h / 2, self::BLACK, null, 0);
            $this->textAt('matchday.africa', 'bold', $size, self::WHITE, $x + $this->n(24), (int) round($y + ($h - $th) / 2));
        }];
    }

    // ---------------------------------------------------------------- background

    /** Home colour top left, away bottom right, a white seam with secondary pinstripes, faint rays. */
    private function background(array $home, array $away): void
    {
        $k = self::K;
        [$w, $h] = [self::W * $k, self::H * $k];
        imagefilledrectangle($this->img, 0, 0, $w, $h, $this->c($this->rgb($home[0])));
        imagefilledpolygon($this->img, [$w, (int) ($h * 0.30), $w, $h, 0, $h, 0, (int) ($h * 0.70)], $this->c($this->rgb($away[0])));
        $line = function (int $offset, int $width, array $rgb) use ($w, $h, $k) {
            imagesetthickness($this->img, $width * $k);
            imageline($this->img, 0, (int) ($h * 0.70) + $offset * $k, $w, (int) ($h * 0.30) + $offset * $k, $this->c($rgb));
            imagesetthickness($this->img, 1);
        };
        $line(0, 26, self::WHITE);
        $line(-40, 10, $this->rgb($home[1] ?? $home[0]));
        $line(40, 10, $this->rgb($away[1] ?? $away[0]));
        $ray = imagecolorallocatealpha($this->img, 255, 255, 255, 118);
        [$cx, $cy] = [$w / 2, self::CENTRE_Y * $k];
        for ($i = 0; $i < 24; $i++) {
            [$a1, $a2] = [deg2rad($i * 15 - 3), deg2rad($i * 15 + 3)];
            imagefilledpolygon($this->img, [(int) $cx, (int) $cy, (int) ($cx + 3400 * cos($a1)), (int) ($cy + 3400 * sin($a1)),
                (int) ($cx + 3400 * cos($a2)), (int) ($cy + 3400 * sin($a2))], $ray);
        }
    }

    // ---------------------------------------------------------------- drawing helpers (logical px in, supersampled out)

    private function n(float $px): int
    {
        return (int) round($px * $this->s);
    }

    private function c(array $rgb): int
    {
        return imagecolorallocate($this->img, $rgb[0], $rgb[1], $rgb[2]);
    }

    private function rect(int $x1, int $y1, int $x2, int $y2, array $rgb): void
    {
        imagefilledrectangle($this->img, $x1 * self::K, $y1 * self::K, $x2 * self::K - 1, $y2 * self::K - 1, $this->c($rgb));
        $this->boxes[] = [$x1, $y1, $x2, $y2];
    }

    /** An outline of $width px inside the box. */
    private function frame(int $x1, int $y1, int $x2, int $y2, array $rgb, int $width): void
    {
        $k = self::K;
        $c = $this->c($rgb);
        foreach ([[$x1, $y1, $x2, $y1 + $width], [$x1, $y2 - $width, $x2, $y2], [$x1, $y1, $x1 + $width, $y2], [$x2 - $width, $y1, $x2, $y2]] as [$a, $b, $cc, $d]) {
            imagefilledrectangle($this->img, $a * $k, $b * $k, $cc * $k - 1, $d * $k - 1, $c);
        }
        $this->boxes[] = [$x1, $y1, $x2, $y2];
    }

    /** A rounded rectangle filled with $fill and, when $border is given, an outline $width px wide. */
    private function roundRect(int $x1, int $y1, int $x2, int $y2, float $radius, array $fill, ?array $border, int $width): void
    {
        if ($border && $width > 0) {
            $this->roundFill($x1, $y1, $x2, $y2, $radius, $border);
            $this->roundFill($x1 + $width, $y1 + $width, $x2 - $width, $y2 - $width, max(0, $radius - $width), $fill);
        } else {
            $this->roundFill($x1, $y1, $x2, $y2, $radius, $fill);
        }
        $this->boxes[] = [$x1, $y1, $x2, $y2];
    }

    private function roundFill(int $x1, int $y1, int $x2, int $y2, float $radius, array $rgb): void
    {
        $k = self::K;
        [$x1, $y1, $x2, $y2] = [$x1 * $k, $y1 * $k, $x2 * $k, $y2 * $k];
        $r = (int) min($radius * $k, ($x2 - $x1) / 2, ($y2 - $y1) / 2);
        $c = $this->c($rgb);
        if ($r < 2) {
            imagefilledrectangle($this->img, $x1, $y1, $x2 - 1, $y2 - 1, $c);

            return;
        }
        imagefilledrectangle($this->img, $x1 + $r, $y1, $x2 - $r - 1, $y2 - 1, $c);
        imagefilledrectangle($this->img, $x1, $y1 + $r, $x2 - 1, $y2 - $r - 1, $c);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r - 1, $y1 + $r], [$x1 + $r, $y2 - $r - 1], [$x2 - $r - 1, $y2 - $r - 1]] as [$cx, $cy]) {
            imagefilledellipse($this->img, $cx, $cy, 2 * $r, 2 * $r, $c);
        }
    }

    private function ellipse(int $x, int $y, int $d, array $rgb): void
    {
        $k = self::K;
        imagefilledellipse($this->img, (int) (($x + $d / 2) * $k), (int) (($y + $d / 2) * $k), $d * $k, $d * $k, $this->c($rgb));
        $this->boxes[] = [$x, $y, $x + $d, $y + $d];
    }

    /** A flag (flag-icons PNG) or, for a club, a kit-colour swatch, with an outline. */
    private function flagOrSwatch(?string $flag, ?array $swatch, int $x, int $y, int $w, int $h, int $outline): void
    {
        if ($flag && is_file($flag) && ($src = @imagecreatefrompng($flag))) {
            imagecopyresampled($this->img, $src, $x * self::K, $y * self::K, 0, 0, $w * self::K, $h * self::K, imagesx($src), imagesy($src));
            imagedestroy($src);
            $this->boxes[] = [$x, $y, $x + $w, $y + $h];
        } else {
            $this->tile($swatch ?? ['#0B0B0B', '#FFFFFF'], $x, $y, $w, $h);
        }
        if ($outline > 0) {
            $this->frame($x, $y, $x + $w, $y + $h, self::BLACK, $outline);
        }
    }

    /** A club's colours as a tile: the main colour with the second as a diagonal band. Never a crest. */
    private function tile(array $colours, int $x, int $y, int $w, int $h): void
    {
        $k = self::K;
        $this->rect($x, $y, $x + $w, $y + $h, $this->rgb($colours[0]));
        $band = (int) round($h * 0.28);
        imagefilledpolygon($this->img, [$x * $k, ($y + $h - $band) * $k, ($x + $w) * $k, ($y + $h - 2 * $band) * $k,
            ($x + $w) * $k, ($y + $h - $band) * $k, $x * $k, ($y + $h) * $k], $this->c($this->rgb($colours[1] ?? '#FFFFFF')));
    }

    /** A square crop of the photo inside a circle of $size px at ($x, $y). */
    private function roundPhoto(string $file, ?array $crop, int $x, int $y, int $size): void
    {
        $src = @imagecreatefromstring((string) file_get_contents($file));
        if (!$src) {
            return;
        }
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        $side = (int) min($crop['w'] ?? min($sw, $sh), $crop['h'] ?? min($sw, $sh), $sw, $sh);
        $cx = (int) (isset($crop['cx']) ? $crop['cx'] * $sw : ($crop['x'] ?? 0) + ($crop['w'] ?? $sw) / 2);
        $cy = (int) (isset($crop['cy']) ? $crop['cy'] * $sh : ($crop['y'] ?? 0) + $side / 2);
        $sx = max(0, min($sw - $side, $cx - intdiv($side, 2)));
        $sy = max(0, min($sh - $side, $cy - intdiv($side, 2)));
        $d = $size * self::K;
        $face = imagecreatetruecolor($d, $d);
        imagecopyresampled($face, $src, 0, 0, $sx, $sy, $d, $d, $side, $side);
        imagedestroy($src);
        // Corners outside the circle become a key colour that the copy leaves out.
        $mask = imagecreatetruecolor($d, $d);
        $key = imagecolorallocate($mask, 1, 254, 3);
        imagefill($mask, 0, 0, $key);
        imagefilledellipse($mask, intdiv($d, 2), intdiv($d, 2), $d, $d, imagecolorallocate($mask, 0, 0, 0));
        imagecolortransparent($mask, imagecolorallocate($mask, 0, 0, 0));
        imagecopy($face, $mask, 0, 0, 0, 0, $d, $d);
        imagecolortransparent($face, imagecolorallocate($face, 1, 254, 3));
        imagecopy($this->img, $face, $x * self::K, $y * self::K, 0, 0, $d, $d);
        imagedestroy($face);
        imagedestroy($mask);
        $this->boxes[] = [$x, $y, $x + $size, $y + $size];
    }

    /** Text with its top-left at ($x, $y) (logical px), optionally outlined in black $stroke px wide. */
    private function textAt(string $text, string $face, int $size, array $rgb, int $x, int $y, int $stroke = 0): void
    {
        $k = self::K;
        $pt = $size * 0.75 * $k;
        $box = imagettfbbox($pt, 0, $this->font($face), $text);
        $bx = $x * $k - $box[6];
        $by = $y * $k - $box[7];
        if ($stroke > 0) {
            $black = $this->c(self::BLACK);
            $r = $stroke * $k;
            for ($a = 0; $a < 360; $a += 20) {
                imagettftext($this->img, $pt, 0, (int) round($bx + $r * cos(deg2rad($a))), (int) round($by + $r * sin(deg2rad($a))), $black, $this->font($face), $text);
            }
        }
        imagettftext($this->img, $pt, 0, (int) $bx, (int) $by, $this->c($rgb), $this->font($face), $text);
        [$tw, $th] = $this->measure($text, $face, $size);
        $this->boxes[] = [$x - $stroke, $y - $stroke, $x + $tw + $stroke, $y + $th + $stroke];
    }

    private function centred(string $text, string $face, int $size, array $rgb, int $y, int $stroke = 0): void
    {
        [$tw] = $this->measure($text, $face, $size);
        $this->textAt($text, $face, $size, $rgb, (int) round((self::W - $tw) / 2), $y, $stroke);
    }

    /** [width, height] in logical px of $text at $size px. */
    private function measure(string $text, string $face, int $size): array
    {
        $box = imagettfbbox($size * 0.75, 0, $this->font($face), $text === '' ? ' ' : $text);

        return [(int) ceil(max($box[2], $box[4]) - min($box[0], $box[6])), (int) ceil(max($box[1], $box[3]) - min($box[5], $box[7]))];
    }

    private function ascent(string $face, int $size): int
    {
        $box = imagettfbbox($size * 0.75, 0, $this->font($face), 'ÁHgjy');

        return (int) ceil(max($box[1], $box[3]) - min($box[5], $box[7]));
    }

    private function wrap(string $text, string $face, int $size, int $width): array
    {
        $rows = [];
        $row = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $row === '' ? $word : "{$row} {$word}";
            if ($row !== '' && $this->measure($try, $face, $size)[0] > $width) {
                $rows[] = $row;
                $row = $word;
            } else {
                $row = $try;
            }
        }

        return array_map(fn ($r) => $this->fit($r, $face, $size, $width), array_merge($rows, [$row]));
    }

    private function fit(string $text, string $face, int $size, int $width): string
    {
        while (mb_strlen($text) > 2 && $this->measure($text, $face, $size)[0] > $width) {
            $text = rtrim(mb_substr(rtrim($text, '…'), 0, -1)).'…';
        }

        return $text;
    }

    /** The largest size from $max down to $min (logical px, before scaling) at which $text fits one line. */
    private function fitSize(string $text, string $face, int $max, int $min, int $width): int
    {
        for ($px = $max; $px > $min; $px -= 2) {
            if ($this->measure($text, $face, $this->n($px))[0] <= $width) {
                break;
            }
        }

        return $this->n($px);
    }

    private function font(string $face): string
    {
        return resource_path('fonts/'.[
            'display' => 'Anton-Regular.ttf', 'reg' => 'AsapCondensed-Regular.ttf', 'med' => 'AsapCondensed-SemiBold.ttf',
            'bold' => 'AsapCondensed-Bold.ttf', 'black' => 'AsapCondensed-Black.ttf',
        ][$face]);
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function mix(array $a, array $b, float $t): array
    {
        return array_map(fn ($x, $y) => (int) round($x * (1 - $t) + $y * $t), $a, $b);
    }
}
