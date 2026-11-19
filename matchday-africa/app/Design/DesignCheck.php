<?php

namespace App\Design;

use App\Services\Slideshow\SlideshowVideo;
use GdImage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The design check: samples a video every N seconds (default 5), draws a contact sheet and flags
 *
 *  - text inside the unsafe zones (tokens grid.unsafe: top, bottom 380, right 140),
 *  - frames more than grid.max_empty bare backdrop inside the safe zone,
 *  - repeated cards,
 *  - photos shown without their credit,
 *  - fonts, sizes or colours that are not tokens (or the match's team colours), and banned copy.
 *
 * Text, fonts, credits and repeats are read from the render's manifest (Film::manifest, saved as
 * design.json next to the work files). Emptiness, colours and near-identical frames are measured
 * on the pixels, so a video without a manifest still gets those checks.
 */
class DesignCheck
{
    private const CELL = 40;

    public function __construct(private SlideshowVideo $video) {}

    /**
     * @return array{ok: bool, video: string, seconds: float, frames: list<array>, problems: list<string>, sheet: ?string}
     */
    public function run(string $file, ?array $manifest = null, ?string $sheet = null, float $every = 5.0): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("No video at {$file}.");
        }
        $seconds = $this->duration($file);
        $problems = [];
        if ($manifest !== null) {
            $problems = array_merge($problems, $this->manifestProblems($manifest));
        }
        $frames = [];
        $hashes = [];
        for ($t = 0.0; $t < $seconds - 0.05; $t += $every) {
            $img = $this->frameAt($file, $t);
            $cut = $manifest !== null ? $this->cutAt($manifest, $t) : null;
            $row = ['t' => round($t, 2), 'component' => $cut['component'] ?? null, 'problems' => []];
            [$empty, $checkedAt] = [$this->emptiness($img, $cut), $t];
            if ($empty > (float) Tokens::get('grid.max_empty') && $cut && $t - $cut['start'] < $cut['settled'] + 0.05) {
                // Sampled mid-entrance: measure the same cut at rest as well.
                $checkedAt = min($cut['start'] + $cut['settled'] + 0.05, $cut['start'] + $cut['seconds'] - 0.05);
                $rest = $this->frameAt($file, $checkedAt);
                $empty = $this->emptiness($rest, $cut);
                imagedestroy($rest);
            }
            $row['empty'] = round($empty, 3);
            $row['empty_checked_at'] = round($checkedAt, 2);
            if ($empty > (float) Tokens::get('grid.max_empty')) {
                $row['problems'][] = sprintf('%.0f%% of the safe zone is empty (max %.0f%%)', $empty * 100, (float) Tokens::get('grid.max_empty') * 100);
            }
            foreach ($this->offPalette($img, $cut) as $colour) {
                $row['problems'][] = "colour {$colour} is not a token or a team colour";
            }
            $hash = $this->hash($img);
            foreach ($hashes as [$ot, $oh, $ocut, $ocutData]) {
                if ($ocut !== null && $cut !== null && $ocut === ($cut['group'] ?? $cut['key'] ?? null)) {
                    continue; // the same card sampled twice (or a radio story still being read out)
                }
                // Same look and (with a manifest) mostly the same words, team names aside: a repeat. Two goals
                // or two pages of results share a layout but not their content.
                if ($this->distance($hash, $oh) < 0.02 && ($cut === null || $ocutData === null || $this->overlap($cut, $ocutData) >= 0.7)) {
                    $row['problems'][] = "looks the same as the frame at {$ot}s (repeated card)";
                }
            }
            $hashes[] = [$row['t'], $hash, $cut['group'] ?? $cut['key'] ?? null, $cut];
            $frames[] = $row + ['image' => $img];
        }
        foreach ($frames as $row) {
            foreach ($row['problems'] as $p) {
                $problems[] = "{$row['t']}s: {$p}";
            }
        }
        $sheetFile = $sheet ? $this->sheet($frames, $sheet, basename($file)) : null;
        foreach ($frames as $i => $row) {
            imagedestroy($row['image']);
            unset($frames[$i]['image']);
        }

        return ['ok' => $problems === [], 'video' => $file, 'seconds' => round($seconds, 2), 'frames' => array_values($frames),
            'problems' => array_values(array_unique($problems)), 'sheet' => $sheetFile, 'manifest' => $manifest !== null];
    }

    /** Checks that need the layout: unsafe text, fonts, sizes, colours, contrast, copy, credits, repeats. */
    public function manifestProblems(array $manifest): array
    {
        $problems = [];
        $safe = Tokens::safe();
        $brand = array_map(fn ($c) => Canvas::hex($c), Tokens::colours());
        $fonts = collect((array) Tokens::get('font'))->mapWithKeys(fn ($f, $k) => [$k => basename($f['file'])]);
        $seen = [];
        foreach ($manifest as $i => $cut) {
            $where = sprintf('cut %d (%s, %.1fs)', $i + 1, $cut['component'], $cut['start']);
            if (isset($seen[$cut['key']])) {
                $problems[] = "{$where}: repeats cut {$seen[$cut['key']]}";
            }
            $seen[$cut['key']] ??= $i + 1;
            $team = array_map(fn ($c) => Canvas::hex($c), (array) ($cut['team_colours'] ?? []));
            foreach ($cut['photos'] as $photo) {
                if (!$photo['own'] && trim((string) ($cut['credit'] ?? '')) === '') {
                    $problems[] = "{$where}: photo {$photo['file']} has no credit on screen";
                }
                if (!$photo['own'] && trim((string) ($photo['credit'] ?? '')) === '') {
                    $problems[] = "{$where}: photo {$photo['file']} is not licensed with a credit";
                }
            }
            foreach ($cut['text'] as $t) {
                [$x1, $y1, $x2, $y2] = $t['box'];
                $label = '"'.mb_substr($t['text'], 0, 30).'"';
                if ($x1 < $safe['left'] - 1 || $x2 > $safe['right'] + 1 || $y1 < $safe['top'] - 1 || $y2 > $safe['bottom'] + 1) {
                    $problems[] = "{$where}: text {$label} is in an unsafe zone";
                }
                if (($fonts[$t['font_token']] ?? null) !== $t['font']) {
                    $problems[] = "{$where}: text {$label} uses {$t['font']}, not a font token";
                }
                $type = (array) Tokens::get("type.{$t['style']}", []);
                if (!$type || $t['font_token'] !== $type['font'] || $t['size'] > $type['size'] || $t['size'] < min($type['min'], $type['size'])) {
                    $problems[] = "{$where}: text {$label} at {$t['size']}px is off the {$t['style']} type scale";
                }
                if (!in_array($t['colour'], $brand, true) && !in_array($t['colour'], $team, true)) {
                    $problems[] = "{$where}: text {$label} is {$t['colour']}, not a colour token";
                }
                $allowed = (array) Tokens::get("contrast.{$t['on']}", []);
                if (!in_array($t['tone'], $allowed, true)) {
                    $problems[] = "{$where}: {$t['tone']} text {$label} on {$t['on']} breaks the contrast rules";
                }
                if ($bad = CopyRules::violations($t['text'])) {
                    $problems[] = "{$where}: text {$label} breaks the copy rules (".implode(', ', $bad).')';
                }
            }
        }

        return $problems;
    }

    // ---------------------------------------------------------------- pixels

    /**
     * Share of the safe zone that is bare backdrop: 40 px cells that are flat (no text, photo or
     * graphic) and the backdrop's colour.
     */
    public function emptiness(GdImage $img, ?array $cut = null): float
    {
        $safe = Tokens::safe();
        $backdrop = Canvas::rgb($cut['backdrop'] ?? $this->dominant($img));
        $small = imagecreatetruecolor(intdiv(imagesx($img), 4), intdiv(imagesy($img), 4));
        imagecopyresized($small, $img, 0, 0, 0, 0, imagesx($small), imagesy($small), imagesx($img), imagesy($img));
        $cell = intdiv(self::CELL, 4);
        [$empty, $total] = [0, 0];
        for ($y = intdiv($safe['top'], 4); $y + $cell <= intdiv($safe['bottom'], 4); $y += $cell) {
            for ($x = intdiv($safe['left'], 4); $x + $cell <= intdiv($safe['right'], 4); $x += $cell) {
                $total++;
                [$sum, $sq, $n] = [[0, 0, 0], 0.0, 0];
                $lum = [];
                for ($yy = $y; $yy < $y + $cell; $yy++) {
                    for ($xx = $x; $xx < $x + $cell; $xx++) {
                        $c = imagecolorat($small, $xx, $yy);
                        $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                        $sum = [$sum[0] + $rgb[0], $sum[1] + $rgb[1], $sum[2] + $rgb[2]];
                        $lum[] = 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2];
                        $n++;
                    }
                }
                $mean = array_map(fn ($v) => $v / $n, $sum);
                $avg = array_sum($lum) / $n;
                $sd = sqrt(array_sum(array_map(fn ($l) => ($l - $avg) ** 2, $lum)) / $n);
                $dist = sqrt(($mean[0] - $backdrop[0]) ** 2 + ($mean[1] - $backdrop[1]) ** 2 + ($mean[2] - $backdrop[2]) ** 2);
                if ($sd < 4.0 && $dist < 14) {
                    $empty++;
                }
            }
        }
        imagedestroy($small);

        return $total ? $empty / $total : 0.0;
    }

    /**
     * Colours covering more than 1.5% of the frame outside photos that are not within reach of a
     * brand token or one of the cut's team colours (JPEG and anti-aliasing allowed for).
     */
    public function offPalette(GdImage $img, ?array $cut): array
    {
        $allowed = array_map(fn ($c) => Canvas::rgb($c), array_merge(array_values(Tokens::colours()), (array) ($cut['team_colours'] ?? [])));
        if ($cut === null) {
            return []; // without the manifest photos cannot be told apart from drawn colour
        }
        $boxes = array_map(fn ($p) => $p['box'], $cut['photos'] ?? []);
        $counts = [];
        $n = 0;
        for ($y = 0; $y < imagesy($img); $y += 6) {
            for ($x = 0; $x < imagesx($img); $x += 6) {
                foreach ($boxes as [$bx, $by, $bw, $bh]) {
                    if ($x >= $bx && $x < $bx + $bw && $y >= $by && $y < $by + $bh) {
                        continue 2;
                    }
                }
                $c = imagecolorat($img, $x, $y);
                $key = ((($c >> 16) & 255) >> 4).','.((($c >> 8) & 255) >> 4).','.(($c & 255) >> 4);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $n++;
            }
        }
        $bad = [];
        foreach ($counts as $key => $count) {
            if ($count / max(1, $n) < 0.015) {
                continue;
            }
            $rgb = array_map(fn ($v) => (int) $v * 16 + 8, explode(',', $key));
            $near = min(array_map(fn ($a) => sqrt(($a[0] - $rgb[0]) ** 2 + ($a[1] - $rgb[1]) ** 2 + ($a[2] - $rgb[2]) ** 2), $allowed));
            if ($near > 40) {
                $bad[] = sprintf('#%02X%02X%02X', ...$rgb);
            }
        }

        return $bad;
    }

    /** A 27x48 grey thumbnail for spotting near-identical frames. */
    private function hash(GdImage $img): array
    {
        $small = imagecreatetruecolor(27, 48);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 27, 48, imagesx($img), imagesy($img));
        $out = [];
        for ($y = 0; $y < 48; $y++) {
            for ($x = 0; $x < 27; $x++) {
                $c = imagecolorat($small, $x, $y);
                $out[] = (0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255)) / 255;
            }
        }
        imagedestroy($small);

        return $out;
    }

    /** Share of the smaller card's words the other card also shows (team names and punctuation left out). */
    private function overlap(array $a, array $b): float
    {
        $words = function (array $cut) {
            $teams = collect($cut['teams'] ?? [])->flatMap(fn ($n) => preg_split('/\s+/u', mb_strtolower((string) $n)))->filter()->all();
            $all = collect($cut['text'] ?? [])->flatMap(fn ($t) => preg_split('/\s+/u', mb_strtolower((string) $t['text'])))
                ->map(fn ($w) => trim($w, " \t.,:;!?·–-'’()"))->filter(fn ($w) => $w !== '' && !in_array($w, $teams, true));

            return $all->unique()->values()->all();
        };
        [$x, $y] = [$words($a), $words($b)];
        if (!$x || !$y) {
            return 1.0;
        }

        return count(array_intersect($x, $y)) / min(count($x), count($y));
    }

    private function distance(array $a, array $b): float
    {
        return array_sum(array_map(fn ($x, $y) => abs($x - $y), $a, $b)) / count($a);
    }

    private function dominant(GdImage $img): string
    {
        $counts = [];
        for ($y = 0; $y < imagesy($img); $y += 8) {
            for ($x = 0; $x < imagesx($img); $x += 8) {
                $c = imagecolorat($img, $x, $y) & 0xF0F0F0;
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        arsort($counts);
        $c = (int) array_key_first($counts) | 0x080808;

        return sprintf('#%06X', $c);
    }

    private function cutAt(array $manifest, float $t): ?array
    {
        foreach ($manifest as $cut) {
            if ($t >= $cut['start'] - 0.001 && $t < $cut['start'] + $cut['seconds']) {
                return $cut;
            }
        }

        return end($manifest) ?: null;
    }

    // ---------------------------------------------------------------- video

    public function frameAt(string $file, float $t): GdImage
    {
        $p = new Process([$this->video->ffmpeg(), '-v', 'error', '-ss', sprintf('%.3f', $t), '-i', $file, '-frames:v', '1', '-f', 'image2pipe', '-c:v', 'png', '-']);
        $p->setTimeout(60)->mustRun();
        $img = @imagecreatefromstring($p->getOutput());
        if (!$img) {
            throw new RuntimeException(sprintf('Could not read the frame at %.2fs.', $t));
        }

        return $img;
    }

    public function duration(string $file): float
    {
        $seconds = \App\Services\SocialQueue::mp4Seconds($file);
        if ($seconds === null) {
            throw new RuntimeException("Could not read the length of {$file}.");
        }

        return $seconds;
    }

    /** The contact sheet: one tile per sampled frame, its time, card and problems; red border when it fails. */
    private function sheet(array $frames, string $out, string $title): string
    {
        [$tw, $th] = [270, 480];
        $cols = min(6, max(1, count($frames)));
        $rows = (int) ceil(count($frames) / $cols);
        $pad = 24;
        $label = 92;
        $head = 90;
        $W = $cols * ($tw + $pad) + $pad;
        $H = $head + $rows * ($th + $label + $pad) + $pad;
        $sheet = imagecreatetruecolor($W, $H);
        imagefill($sheet, 0, 0, Canvas::col($sheet, 'black'));
        $bold = Tokens::font('bold');
        $reg = Tokens::font('regular');
        $fails = count(array_filter($frames, fn ($f) => $f['problems']));
        Canvas::text($sheet, "Design check: {$title}", Tokens::font('display'), 40, $pad, 56, 'yellow');
        Canvas::text($sheet, count($frames).' frames, one every 5 s · '.($fails ? "{$fails} with problems" : 'all pass'), $reg, 20, $pad, 82, 'grey');
        foreach ($frames as $i => $f) {
            $x = $pad + ($i % $cols) * ($tw + $pad);
            $y = $head + intdiv($i, $cols) * ($th + $label + $pad);
            $ok = $f['problems'] === [];
            Canvas::rect($sheet, $x - 4, $y - 4, $x + $tw + 4, $y + $th + 4, $ok ? 'surface' : 'red');
            imagecopyresampled($sheet, $f['image'], $x, $y, 0, 0, $tw, $th, imagesx($f['image']), imagesy($f['image']));
            // The unsafe zones, outlined.
            $k = $tw / imagesx($f['image']);
            $safe = Tokens::safe();
            $zone = Canvas::col($sheet, 'yellow', 70);
            imagerectangle($sheet, $x + (int) ($safe['left'] * $k), $y + (int) ($safe['top'] * $k), $x + (int) ($safe['right'] * $k), $y + (int) ($safe['bottom'] * $k), $zone);
            $line = sprintf('%05.1fs  %s', $f['t'], $f['component'] ?? '');
            Canvas::text($sheet, $line, $bold, 20, $x, $y + $th + 30, $ok ? 'white' : 'red');
            $detail = $ok ? sprintf('ok · %d%% empty', round($f['empty'] * 100)) : implode('; ', $f['problems']);
            foreach (array_slice(Canvas::wrap($detail, $reg, 16, $tw), 0, 3) as $j => $part) {
                Canvas::text($sheet, $part, $reg, 16, $x, $y + $th + 54 + $j * 20, 'grey');
            }
        }
        @mkdir(dirname($out), 0777, true);
        imagepng($sheet, $out, 6);
        imagedestroy($sheet);

        return $out;
    }
}
