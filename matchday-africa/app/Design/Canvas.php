<?php

namespace App\Design;

use GdImage;

/**
 * GD drawing primitives shared by every renderer: colours from tokens, text measured in pixels
 * (GD sizes are points at 96 dpi), wrapping and fitting on the type scale, the contrast rules,
 * licence-checked photos, flags, kit swatches and the MD logo.
 */
final class Canvas
{
    private static array $logos = [];

    // ---------------------------------------------------------------- colour

    /** '#RRGGBB' for a token name or a hex value. */
    public static function hex(string $colour): string
    {
        if ($colour !== '' && $colour[0] !== '#') {
            return Tokens::colour($colour);
        }
        $hex = ltrim($colour, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.strtoupper(substr($hex, 0, 6));
    }

    /** [r, g, b] of a token or hex. */
    public static function rgb(string $colour): array
    {
        $hex = ltrim(self::hex($colour), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** A GD colour (alpha 0 = opaque, 127 = clear). */
    public static function col(GdImage $img, string $colour, int $alpha = 0): int
    {
        [$r, $g, $b] = self::rgb($colour);

        return imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $alpha)));
    }

    /** WCAG relative luminance. */
    public static function luminance(string $colour): float
    {
        $c = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($colour));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** The text colour token for a team colour background: black or white, whichever contrasts more. */
    public static function ink(string $background): string
    {
        return self::contrast($background, 'black') >= self::contrast($background, 'white') ? 'black' : 'white';
    }

    /**
     * Applies the contrast rules: $text (a colour token) may sit on $on (a colour token, 'team' or
     * 'photo'). Throws DesignViolation otherwise. On a team colour the text must be the ink() of it.
     */
    public static function assertPair(string $text, string $on, ?string $teamHex = null): void
    {
        $allowed = (array) Tokens::get("contrast.{$on}", []);
        $ok = in_array($text, $allowed, true) && ($on !== 'team' || $teamHex === null || self::ink($teamHex) === $text);
        if (!$ok) {
            throw new DesignViolation("Contrast rule: {$text} text is not allowed on {$on}".($teamHex ? " ({$teamHex})" : '').'.');
        }
    }

    /**
     * A team's two colours, made safe to show: a missing pair falls back to the token pair, and a
     * second colour equal to the first becomes black or white.
     */
    public static function teamColours(?array $colours, string $side = 'home'): array
    {
        $colours = array_values(array_filter((array) $colours, fn ($c) => is_string($c) && preg_match('/^#?[0-9a-f]{3,6}$/i', $c)));
        if (!$colours) {
            $colours = Tokens::teamFallback($side);
        }
        $a = self::hex($colours[0]);
        $b = self::hex($colours[1] ?? self::hex(self::ink($a)));
        if ($a === $b) {
            $b = self::hex(self::ink($a));
        }

        return [$a, $b];
    }

    // ---------------------------------------------------------------- type

    /** GD point size for a pixel size (GD renders at 96 dpi). */
    public static function pt(float $px): float
    {
        return $px * 0.75;
    }

    /** [width, top offset from baseline (negative), bottom offset] of $text in $file at $px. */
    public static function measure(string $text, string $file, float $px): array
    {
        if ($text === '') {
            return [0, 0, 0];
        }
        $bb = imagettfbbox(self::pt($px), 0, $file, $text);

        return [$bb[2] - $bb[0], min($bb[5], $bb[7]), max($bb[1], $bb[3])];
    }

    /** Draws text with its baseline at $y. */
    public static function text(GdImage $img, string $text, string $file, float $px, int $x, int $y, string $colour, int $alpha = 0): void
    {
        imagettftext($img, self::pt($px), 0, $x, $y, self::col($img, $colour, $alpha), $file, $text);
    }

    /** The largest size from $max down to $min (steps of 2 px) at which a single line fits $width. */
    public static function shrink(string $text, string $file, int $max, int $min, int $width): int
    {
        for ($px = $max; $px > $min; $px -= 2) {
            if (self::measure($text, $file, $px)[0] <= $width) {
                return $px;
            }
        }

        return $min;
    }

    /** Word-wraps $text at $px into lines no wider than $width. */
    public static function wrap(string $text, string $file, float $px, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $try = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && self::measure($try, $file, $px)[0] > $width) {
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

    /**
     * Lines of $text in a type style that fit $width in at most $maxLines lines, stepping down from
     * the style's size (or $max) to its minimum; the last line ends in an ellipsis if it still does
     * not fit. Returns [[line, px], ...].
     */
    public static function fit(string $text, string $style, int $width, int $maxLines, ?int $max = null, ?int $min = null): array
    {
        $type = Tokens::type($style);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $max ??= (int) $type['size'];
        $min ??= (int) $type['min'];
        for ($px = $max; $px >= $min; $px -= 2) {
            $lines = self::wrap($text, $type['file'], $px, $width);
            $widest = max(array_map(fn ($l) => self::measure($l, $type['file'], $px)[0], $lines));
            if (count($lines) <= $maxLines && $widest <= $width) {
                return array_map(fn ($l) => [$l, $px], $lines);
            }
        }
        $lines = array_slice(self::wrap($text, $type['file'], $min, $width), 0, $maxLines);
        $last = count($lines) - 1;
        while ($last >= 0 && self::measure($lines[$last].'…', $type['file'], $min)[0] > $width && str_contains($lines[$last], ' ')) {
            $lines[$last] = preg_replace('/\s+\S+$/u', '', $lines[$last]);
        }
        $lines[$last] = rtrim($lines[$last], ' ,.;:').'…';

        return array_map(fn ($l) => [$l, $min], $lines);
    }

    /**
     * Like fit(), but also no taller than $height: the largest size on the style's range whose
     * lines fit, then fewer lines (ending in an ellipsis) at the minimum size.
     */
    public static function fitBox(string $text, string $style, int $width, int $height, int $maxLines = 6, ?int $max = null): array
    {
        $type = Tokens::type($style);
        $lead = (float) $type['leading'];
        $max ??= (int) $type['size'];
        for ($px = $max; $px >= (int) $type['min']; $px -= 2) {
            $lines = self::fit($text, $style, $width, $maxLines, $px, $px);
            $fits = !str_ends_with((string) end($lines)[0], '…') || count(self::wrap($text, $type['file'], $px, $width)) <= $maxLines;
            if ($fits && count($lines) * round($px * $lead) <= $height) {
                return $lines;
            }
        }
        $rows = max(1, (int) floor($height / round($type['min'] * $lead)));

        return self::fit($text, $style, $width, min($maxLines, $rows), (int) $type['min']);
    }

    // ---------------------------------------------------------------- shapes

    public static function rect(GdImage $img, int $x1, int $y1, int $x2, int $y2, string $colour, int $alpha = 0): void
    {
        if ($x2 > $x1 && $y2 > $y1) {
            imagefilledrectangle($img, $x1, $y1, $x2 - 1, $y2 - 1, self::col($img, $colour, $alpha));
        }
    }

    public static function roundRect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, string $colour, int $alpha = 0): void
    {
        $r = max(0, min($r, intdiv($x2 - $x1, 2), intdiv($y2 - $y1, 2)));
        if ($r < 2) {
            self::rect($img, $x1, $y1, $x2, $y2, $colour, $alpha);

            return;
        }
        // Non-overlapping parts, so a translucent panel blends evenly while it fades in.
        $c = self::col($img, $colour, $alpha);
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r - 1, $y2 - 1, $c);
        imagefilledrectangle($img, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r - 1, $c);
        imagefilledrectangle($img, $x2 - $r, $y1 + $r, $x2 - 1, $y2 - $r - 1, $c);
        $d = 2 * $r;
        imagefilledarc($img, $x1 + $r, $y1 + $r, $d, $d, 180, 270, $c, IMG_ARC_PIE);
        imagefilledarc($img, $x2 - $r - 1, $y1 + $r, $d, $d, 270, 360, $c, IMG_ARC_PIE);
        imagefilledarc($img, $x2 - $r - 1, $y2 - $r - 1, $d, $d, 0, 90, $c, IMG_ARC_PIE);
        imagefilledarc($img, $x1 + $r, $y2 - $r - 1, $d, $d, 90, 180, $c, IMG_ARC_PIE);
    }

    /** A slanted band (parallelogram) across the frame; $y1/$y2 at the left edge, rising by $rise to the right. */
    public static function band(GdImage $img, int $y1, int $y2, int $rise, string $colour, int $alpha = 0): void
    {
        $w = imagesx($img);
        imagefilledpolygon($img, [0, $y1, $w, $y1 - $rise, $w, $y2 - $rise, 0, $y2], self::col($img, $colour, $alpha));
    }

    // ---------------------------------------------------------------- images

    /**
     * The images rule: a file may be shown if it is one of our own renders (tokens images.own_render_dirs),
     * or a licensed photo with a credit. Never anything under images.banned_dirs (crests, agency photos).
     */
    public static function assertImage(string $file, ?string $credit): void
    {
        $real = realpath($file) ?: $file;
        foreach ((array) Tokens::get('images.banned_dirs') as $dir) {
            if (str_starts_with($real, base_path($dir))) {
                throw new DesignViolation('Image rule: '.basename($file)." comes from {$dir} (crests or unlicensed photos).");
            }
        }
        foreach ((array) Tokens::get('images.own_render_dirs') as $dir) {
            if (str_starts_with($real, base_path($dir))) {
                return;
            }
        }
        if (trim((string) $credit) === '') {
            throw new DesignViolation('Image rule: '.basename($file).' is not one of our renders and has no licence credit.');
        }
    }

    /** Loads a PNG, JPEG or WebP. */
    public static function load(?string $file): ?GdImage
    {
        if (!$file || !is_file($file)) {
            return null;
        }
        $img = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png' => @imagecreatefrompng($file),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            'jpg', 'jpeg' => @imagecreatefromjpeg($file),
            default => @imagecreatefromstring((string) file_get_contents($file)),
        };

        return $img ?: null;
    }

    /**
     * The part of $src to show in a $w x $h box: the crop ['x','y','w','h'] (or the whole image)
     * trimmed to the box's aspect ratio. Returns [x, y, w, h] in source pixels.
     */
    public static function cover(GdImage $src, ?array $crop, int $w, int $h): array
    {
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        $c = $crop && isset($crop['w'], $crop['h']) ? $crop : null;
        [$cx, $cy, $cw, $ch] = $c ? [(int) $c['x'], (int) $c['y'], (int) $c['w'], (int) $c['h']] : [0, 0, $sw, $sh];
        $ratio = $w / max(1, $h);
        if ($cw / max(1, $ch) > $ratio) {
            $nw = (int) round($ch * $ratio);
            $cx += intdiv($cw - $nw, 2);
            $cw = $nw;
        } else {
            $nh = (int) round($cw / $ratio);
            // Keep heads in frame: trim mostly from the bottom.
            $cy += (int) round(($ch - $nh) * 0.2);
            $ch = $nh;
        }
        $cx = max(0, min($cx, $sw - 1));
        $cy = max(0, min($cy, $sh - 1));

        return [$cx, $cy, max(1, min($cw, $sw - $cx)), max(1, min($ch, $sh - $cy))];
    }

    /**
     * The part of $src that fills a $w x $h frame (crop to cover) with the face placed high: the
     * head-and-shoulders crop ['x','y','w','h'] (CommonsPhotos::crop) marks the face, whose centre
     * lands at $faceAt of the frame height where the image allows. Returns [[x, y, w, h] in source
     * pixels, [top, bottom] of the face in frame pixels].
     */
    public static function coverFace(GdImage $src, ?array $crop, int $w, int $h, float $faceAt = 0.3): array
    {
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        $c = $crop && isset($crop['w'], $crop['h']) ? $crop : \App\Services\Slideshow\CommonsPhotos::crop($sw, $sh);
        // The face: the upper half of the head-and-shoulders square.
        $faceX = (int) $c['x'] + intdiv((int) $c['w'], 2);
        [$faceTop, $faceBottom] = [(int) $c['y'], (int) $c['y'] + intdiv((int) $c['h'], 2)];
        $ratio = $w / max(1, $h);
        if ($sw / max(1, $sh) > $ratio) {
            [$cw, $ch] = [(int) round($sh * $ratio), $sh];
        } else {
            [$cw, $ch] = [$sw, (int) round($sw / $ratio)];
        }
        $cx = max(0, min($sw - $cw, $faceX - intdiv($cw, 2)));
        $cy = max(0, min($sh - $ch, (int) round(($faceTop + $faceBottom) / 2 - $faceAt * $ch)));
        $k = $h / max(1, $ch);

        return [[$cx, $cy, $cw, $ch], [(int) round(($faceTop - $cy) * $k), (int) round(($faceBottom - $cy) * $k)]];
    }

    /**
     * fitBox() with even line lengths: the same size and line count, wrapped at the narrowest width
     * that keeps that count, and never a single word alone on the last line when it can be avoided.
     */
    public static function balanced(string $text, string $style, int $width, int $height, int $maxLines = 6): array
    {
        $lines = self::fitBox($text, $style, $width, $height, $maxLines);
        $n = count($lines);
        if ($n < 2 || str_ends_with((string) end($lines)[0], '…')) {
            return $lines;
        }
        $px = (int) $lines[0][1];
        $file = Tokens::type($style)['file'];
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        [$lo, $hi] = [intdiv($width, $n), $width];
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $try = self::wrap($text, $file, $px, $mid);
            $widest = max(array_map(fn ($l) => self::measure($l, $file, $px)[0], $try));
            if (count($try) <= $n && $widest <= $mid) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }
        $out = self::wrap($text, $file, $px, $hi);
        $last = count($out) - 1;
        if ($last > 0 && !str_contains($out[$last], ' ') && substr_count($out[$last - 1], ' ') >= 1) {
            $moved = preg_replace('/^.*\s(\S+)$/u', '$1', $out[$last - 1]).' '.$out[$last];
            if (self::measure($moved, $file, $px)[0] <= $width) {
                $out[$last - 1] = preg_replace('/\s\S+$/u', '', $out[$last - 1]);
                $out[$last] = $moved;
            }
        }

        return array_map(fn ($l) => [$l, $px], $out);
    }

    /** A flag PNG $w wide at (x, y); returns the drawn height. */
    public static function flag(GdImage $img, string $file, int $x, int $y, int $w, int $alpha = 0): int
    {
        $f = self::flagImage($file);
        if (!$f) {
            return 0;
        }
        $h = (int) round($w * imagesy($f) / imagesx($f));
        if ($alpha >= 127) {
            return $h;
        }
        $tmp = imagecreatetruecolor($w, $h);
        imagecopyresampled($tmp, $f, 0, 0, 0, 0, $w, $h, imagesx($f), imagesy($f));
        imagecopymerge($img, $tmp, $x, $y, 0, 0, $w, $h, (int) round(100 * (1 - $alpha / 127)));
        imagedestroy($tmp);

        return $h;
    }

    private static array $flags = [];

    private static function flagImage(string $file): ?GdImage
    {
        if (!array_key_exists($file, self::$flags)) {
            self::$flags[$file] = self::load($file);
        }

        return self::$flags[$file];
    }

    /** A side's mark: its flag (national teams) or a two-colour kit swatch (clubs: never crests). */
    public static function swatch(GdImage $img, array $side, int $x, int $y, int $size, int $alpha = 0): void
    {
        if (!empty($side['flag']) && is_file($side['flag'])) {
            $h = (int) round($size * 0.75);
            self::flag($img, $side['flag'], $x, $y + intdiv($size - $h, 2), $size, $alpha);

            return;
        }
        [$a, $b] = self::teamColours($side['colours'] ?? null);
        imagefilledrectangle($img, $x, $y, $x + $size - 1, $y + $size - 1, self::col($img, $a, $alpha));
        imagefilledpolygon($img, [$x, $y + $size, $x + $size, $y, $x + $size, $y + $size], self::col($img, $b, $alpha));
    }

    /** The MD square mark at its fixed place and size (tokens grid.logo), on every frame. */
    public static function logo(GdImage $img, ?int $size = null, ?int $x = null, ?int $y = null): void
    {
        $size ??= (int) Tokens::get('grid.logo.size');
        $x ??= (int) Tokens::get('grid.logo.x');
        $y ??= (int) Tokens::get('grid.logo.y');
        if (!isset(self::$logos[$size])) {
            $src = self::load(base_path((string) Tokens::get('grid.logo.file')));
            $mark = imagecreatetruecolor($size, $size);
            if ($src) {
                imagecopyresampled($mark, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
                imagedestroy($src);
            } else {
                imagefill($mark, 0, 0, self::col($mark, 'yellow'));
            }
            self::$logos[$size] = $mark;
        }
        imagecopy($img, self::$logos[$size], $x, $y, 0, 0, $size, $size);
    }

    /**
     * Both sides as our own render: home colour top left, away bottom right on a diagonal with a
     * yellow seam, and both flags (or kit swatches) large side by side in a band at the bottom,
     * $band px tall, so text can sit in the space above them.
     */
    public static function versus(GdImage $img, array $home, array $away, int $x, int $y, int $w, int $h, int $band = 300): void
    {
        [$ha] = self::teamColours($home['colours'] ?? null, 'home');
        [$aa] = self::teamColours($away['colours'] ?? null, 'away');
        self::rect($img, $x, $y, $x + $w, $y + $h, $ha);
        imagefilledpolygon($img, [$x, $y + (int) ($h * 0.78), $x + $w, $y + (int) ($h * 0.42), $x + $w, $y + $h, $x, $y + $h], self::col($img, $aa));
        $edge = (int) Tokens::get('motion.band.edge');
        imagefilledpolygon($img, [$x, $y + (int) ($h * 0.78), $x + $w, $y + (int) ($h * 0.42), $x + $w, $y + (int) ($h * 0.42) + $edge, $x, $y + (int) ($h * 0.78) + $edge], self::col($img, 'yellow'));
        $safe = Tokens::safe();
        $gap = 40;
        $size = (int) min(($safe['right'] - $safe['left'] - $gap) / 2, ($band - 40) / 0.75);
        $top = $y + $h - $band + intdiv($band - (int) round($size * 0.75), 2);
        $left = $safe['left'] + intdiv($safe['right'] - $safe['left'] - 2 * $size - $gap, 2);
        foreach ([[$home, $left], [$away, $left + $size + $gap]] as [$side, $fx]) {
            self::rect($img, $fx - 8, $top - 8, $fx + $size + 8, $top + (int) round($size * 0.75) + 8, 'black');
            if (!empty($side['flag']) && is_file($side['flag'])) {
                self::flag($img, $side['flag'], $fx, $top, $size);
            } else {
                [$a, $b] = self::teamColours($side['colours'] ?? null);
                self::rect($img, $fx, $top, $fx + $size, $top + (int) round($size * 0.75), $a);
                imagefilledpolygon($img, [$fx, $top + (int) round($size * 0.75), $fx + $size, $top, $fx + $size, $top + (int) round($size * 0.75)], self::col($img, $b));
            }
        }
    }

    /** Our own render for a player without a licensed photo: their colours on a diagonal and their flag, large. */
    public static function flagField(GdImage $img, ?string $flag, array $colours, int $x, int $y, int $w, int $h): void
    {
        [$a, $b] = self::teamColours($colours);
        self::rect($img, $x, $y, $x + $w, $y + $h, $a);
        imagefilledpolygon($img, [$x, $y + (int) ($h * 0.7), $x + $w, $y + (int) ($h * 0.35), $x + $w, $y + $h, $x, $y + $h], self::col($img, $b));
        if ($flag && is_file($flag)) {
            $fw = (int) min($w * 0.6, $h * 0.8 / 0.75);
            self::flag($img, $flag, $x + intdiv($w - $fw, 2), $y + intdiv($h - (int) ($fw * 0.75), 2), $fw);
        }
    }

    /** Initials on the side's colours with the flag: our own render when there is no licensed photo. */
    public static function monogram(GdImage $img, string $name, ?string $flag, int $x, int $y, int $w, int $h, array $colours): void
    {
        [$a, $b] = self::teamColours($colours);
        self::rect($img, $x, $y, $x + $w, $y + $h, $a);
        imagefilledpolygon($img, [$x, $y + $h, $x + $w, $y + (int) ($h * 0.5), $x + $w, $y + $h], self::col($img, $b));
        $initials = collect(preg_split('/\s+/u', trim($name)))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->join('');
        $file = Tokens::font('display');
        $px = (int) (min($w, $h) * 0.34);
        [$tw, $top, $bottom] = self::measure($initials, $file, $px);
        // Centred in the lower two thirds, clear of the scoreboard strip over full-bleed cards.
        $base = $y + (int) ($h * 0.62) - intdiv($top + $bottom, 2);
        self::text($img, $initials, $file, $px, $x + intdiv($w - $tw, 2), $base, self::ink($a));
        if ($flag && is_file($flag)) {
            $fw = (int) (min($w, $h) * 0.24);
            self::flag($img, $flag, $x + $w - $fw - 24, $y + 24, $fw);
        }
    }
}
