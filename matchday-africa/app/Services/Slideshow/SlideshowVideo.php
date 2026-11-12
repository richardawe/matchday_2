<?php

namespace App\Services\Slideshow;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Puts the slides together with ffmpeg: 3.6 s a slide, 0.4 s crossfades, 30 fps, a slow Ken Burns
 * push on slide 01 (from 1.0, capped so no text leaves the safe zone), and the music bed trimmed,
 * faded in 0.3 s and out 1.5 s (volume expressions) and normalised to −14 LUFS in two loudnorm
 * passes. H.264 yuv420p, AAC, +faststart.
 *
 * Two ways to make the picture, chosen by what the ffmpeg build has:
 *   filters  xfade and zoompan in one filter graph (a full ffmpeg);
 *   frames   PHP/GD draws every frame (zoom and crossfades) and streams them as JPEGs into ffmpeg's
 *            image2pipe. Needs only mjpeg, libx264, aac and loudnorm, so it
 *            works with the small ffmpeg the reel renderer ships (reel/node_modules/@remotion/compositor-*).
 * Processes are made through run() so tests can fake ffmpeg.
 */
class SlideshowVideo
{
    /** Filters and encoders every render needs; xfade and zoompan only for the filters mode. */
    public const NEEDS = ['filter loudnorm', 'filter volume', 'filter atrim', 'filter aresample', 'filter scale', 'filter format',
        'encoder libx264', 'encoder aac', 'demuxer image2pipe', 'decoder mjpeg', 'decoder png'];

    /** Decoder each music file type needs. */
    private const AUDIO_DECODERS = ['mp3' => 'decoder mp3', 'm4a' => 'decoder aac', 'aac' => 'decoder aac', 'wav' => 'decoder pcm_s16le', 'flac' => 'decoder flac', 'ogg' => 'decoder vorbis'];

    /** @var (callable(array): Process)|null */
    private $factory = null;

    private ?string $mode = null;

    /** Swaps the process maker (tests). */
    public function usingProcess(callable $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    /** Forces filters or frames (tests, or a known build). */
    public function usingMode(?string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public static function duration(int $slides): float
    {
        $slide = (float) config('slideshow.slide_seconds', 3.6);
        $fade = (float) config('slideshow.crossfade_seconds', 0.4);

        return round($slides * $slide - max(0, $slides - 1) * $fade, 2);
    }

    /**
     * The Ken Burns zoom a slide may reach (from 1.0, as the hand-built make_video.sh): no more than
     * config slideshow.zoom_max, and small enough that the content's height still fits the safe area.
     */
    public static function zoomFor(?array $bbox): float
    {
        $safe = config('slideshow.safe');
        $zoom = (float) config('slideshow.zoom_max', 1.04);
        if ($bbox) {
            $span = max(1, $bbox[3] - $bbox[1]);
            $zoom = min($zoom, ($safe['bottom'] - $safe['top'] - 2) / $span);
        }

        return max(1.0, floor($zoom * 10000) / 10000);
    }

    /**
     * The crop [x, y, w, h] for a zoom of $z: anchored on the safe column's right edge (x 940), so the
     * zoom grows to the left and the right-hand 140 px stay clear, and moved up or down so the content
     * stays between y 150 and 1540.
     */
    public static function cropFor(float $z, ?array $bbox): array
    {
        [$w, $h] = config('slideshow.size', [1080, 1920]);
        $safe = config('slideshow.safe');
        $cw = $w / $z;
        $ch = $h / $z;
        $x = $safe['right'] * (1 - 1 / $z);
        $y = ($h - $ch) / 2;
        if ($bbox) {
            $y = min($bbox[1] - $safe['top'] / $z, max($bbox[3] - $safe['bottom'] / $z, $y));
        }

        return [max(0.0, $x), max(0.0, min($h - $ch, $y)), $cw, $ch];
    }

    /**
     * Renders $slides (['file' => png, 'bbox' => [l, t, r, b]] in order) with the music into $out.
     * Returns ['seconds', 'zoom' => per slide, 'mode', 'loudness' => measured pass-one values].
     */
    public function make(array $slides, string $music, float $musicStart, string $out): array
    {
        if (!$slides) {
            throw new RuntimeException('No slides to put together.');
        }
        if (!is_file($music)) {
            throw new RuntimeException('The music file is missing ('.$music.'): upload it at /admin/slideshows.');
        }
        $seconds = self::duration(count($slides));
        $zooms = array_map(fn ($s) => self::zoomFor($s['bbox'] ?? null), $slides);
        $mode = $this->mode();
        $measured = $this->measure($music, $musicStart, $seconds);
        $part = "{$out}.part.mp4";
        if ($mode === 'filters') {
            $this->run($this->renderArgs($slides, $music, $musicStart, $seconds, $zooms, $measured, $part));
        } else {
            $this->pipe($this->sequenceArgs($music, $musicStart, $seconds, $measured, $part), $this->frames($slides, $zooms));
        }
        if (!is_file($part) || filesize($part) < 10_000) {
            @unlink($part);
            throw new RuntimeException('ffmpeg finished without a usable video.');
        }
        rename($part, $out);

        return ['seconds' => $seconds, 'zoom' => $zooms, 'mode' => $mode, 'loudness' => $measured];
    }

    /** The audio chain before loudnorm: trim from the start point, fade in and out with volume expressions. */
    public function audioChain(float $start, float $seconds): string
    {
        $in = (float) config('slideshow.music.fade_in', 0.3);
        $out = (float) config('slideshow.music.fade_out', 1.5);
        $fadeAt = max(0, $seconds - $out);
        $volume = sprintf("volume='if(lt(t,%s),t/%s,if(gt(t,%s),max(0,(%s-t)/%s),1))':eval=frame",
            self::num($in), self::num($in), self::num($fadeAt), self::num($seconds), self::num($out));

        return sprintf('atrim=start=%s:duration=%s,asetpts=PTS-STARTPTS,%s', self::num($start), self::num($seconds), $volume);
    }

    /** Pass one: loudnorm measures the faded clip and prints its numbers as JSON. */
    public function measureArgs(string $music, float $start, float $seconds): array
    {
        return [$this->ffmpeg(), '-hide_banner', '-nostats', '-i', $music, '-af', $this->audioChain($start, $seconds).','.$this->loudnorm().':print_format=json',
            '-f', 'null', '-'];
    }

    public function measure(string $music, float $start, float $seconds): array
    {
        $process = $this->run($this->measureArgs($music, $start, $seconds));
        $output = $process->getErrorOutput().$process->getOutput();
        if (!preg_match('/\{[^{}]*"input_i"[^{}]*\}/s', $output, $m) || !is_array($json = json_decode($m[0], true))) {
            throw new RuntimeException('loudnorm did not report its measurements.');
        }

        return array_map('strval', array_intersect_key($json, array_flip(['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'])));
    }

    /** Pass two in the filters mode: each slide through zoompan (Ken Burns), then xfade, with the normalised music. */
    public function renderArgs(array $slides, string $music, float $start, float $seconds, array $zooms, array $measured, string $out): array
    {
        $fps = (int) config('slideshow.fps', 30);
        $slide = (float) config('slideshow.slide_seconds', 3.6);
        $fade = (float) config('slideshow.crossfade_seconds', 0.4);
        [$w, $h] = config('slideshow.size', [1080, 1920]);
        $safe = config('slideshow.safe');
        $args = [$this->ffmpeg(), '-hide_banner', '-y'];
        foreach ($slides as $s) {
            $args = array_merge($args, ['-loop', '1', '-framerate', (string) $fps, '-t', self::num($slide), '-i', $s['file']]);
        }
        $args = array_merge($args, ['-i', $music]);

        $frames = (int) round($slide * $fps);
        $up = 2; // zoompan on a 2x picture moves smoothly
        $graph = [];
        foreach ($slides as $i => $s) {
            $z = $zooms[$i] ?? 1.0;
            [$t, $b] = [$s['bbox'][1] ?? $safe['top'], $s['bbox'][3] ?? $safe['bottom']];
            $chain = "[{$i}:v]scale={$w}:{$h},setsar=1,format=yuv420p";
            if ($z > 1.0) {
                $chain = "[{$i}:v]scale=".($w * $up).':'.($h * $up).':flags=lanczos,setsar=1,'
                    ."zoompan=z='1+".self::num($z - 1, 6)."*on/".max(1, $frames - 1)."'"
                    .":x='".($up * $safe['right'])."*(1-1/zoom)'"
                    .":y='min({$up}*{$t}-{$up}*{$safe['top']}/zoom,max({$up}*{$b}-{$up}*{$safe['bottom']}/zoom,(ih-ih/zoom)/2))'"
                    .":d=1:s={$w}x{$h}:fps={$fps},format=yuv420p";
            }
            $graph[] = "{$chain},trim=duration=".self::num($slide).',setpts=PTS-STARTPTS'."[s{$i}]";
        }
        $last = 's0';
        for ($i = 1; $i < count($slides); $i++) {
            $offset = self::num($i * ($slide - $fade));
            $graph[] = "[{$last}][s{$i}]xfade=transition=fade:duration=".self::num($fade).":offset={$offset}[x{$i}]";
            $last = "x{$i}";
        }
        $graph[] = "[{$last}]format=yuv420p[v]";
        $graph[] = '['.count($slides).':a]'.$this->audioChain($start, $seconds).','.$this->loudnormSecond($measured).'[a]';

        return array_merge($args, ['-filter_complex', implode(';', $graph), '-map', '[v]', '-map', '[a]'], $this->encode($seconds, $out));
    }

    /**
     * The frames mode's pictures, one JPEG per output frame (exactly the deck's length at 30 fps): every
     * slide zoomed frame by frame as in the filters mode, the two slides blended through each crossfade.
     * Streamed into ffmpeg, so no frame touches the disk.
     *
     * @return \Generator<int, string>
     */
    public function frames(array $slides, array $zooms): \Generator
    {
        $fps = (int) config('slideshow.fps', 30);
        $slideFrames = (int) round((float) config('slideshow.slide_seconds', 3.6) * $fps);
        $fadeFrames = (int) round((float) config('slideshow.crossfade_seconds', 0.4) * $fps);
        $step = $slideFrames - $fadeFrames;
        $count = count($slides);
        $total = ($count - 1) * $step + $slideFrames;
        $images = array_map(fn ($s) => imagecreatefrompng($s['file']), $slides);
        $at = function (int $i, int $f) use ($images, $zooms, $slides, $slideFrames) {
            $z = 1 + (($zooms[$i] ?? 1.0) - 1) * $f / max(1, $slideFrames - 1);

            return $z > 1.0002 ? $this->zoomFrame($images[$i], $z, $slides[$i]['bbox'] ?? null) : null;
        };
        try {
            for ($n = 0; $n < $total; $n++) {
                $i = min($count - 1, intdiv($n, $step));
                $f = $n - $i * $step;
                if ($f >= $slideFrames) { // only the last slide runs past its step
                    $f = $slideFrames - 1;
                }
                $frame = $at($i, $f) ?? $images[$i];
                $next = $i + 1 < $count && $f >= $step ? $i + 1 : null;
                if ($next !== null) {
                    $g = $f - $step;
                    $other = $at($next, $g) ?? $images[$next];
                    $mix = imagecreatetruecolor(imagesx($frame), imagesy($frame));
                    imagecopy($mix, $frame, 0, 0, 0, 0, imagesx($frame), imagesy($frame));
                    imagecopymerge($mix, $other, 0, 0, 0, 0, imagesx($frame), imagesy($frame), (int) round(100 * ($g + 1) / ($fadeFrames + 1)));
                    $other !== $images[$next] && imagedestroy($other);
                    $frame !== $images[$i] && imagedestroy($frame);
                    $frame = $mix;
                }
                ob_start();
                imagejpeg($frame, null, 94);
                yield ob_get_clean();
                $frame !== $images[$i] && imagedestroy($frame);
            }
        } finally {
            foreach ($images as $img) {
                imagedestroy($img);
            }
        }
    }

    /** Pass two in the frames mode: JPEG frames on stdin, the music, the encoder. */
    public function sequenceArgs(string $music, float $start, float $seconds, array $measured, string $out): array
    {
        $fps = (int) config('slideshow.fps', 30);
        [$w, $h] = config('slideshow.size', [1080, 1920]);

        return array_merge([$this->ffmpeg(), '-hide_banner', '-y', '-f', 'image2pipe', '-framerate', (string) $fps, '-c:v', 'mjpeg', '-i', '-', '-i', $music,
            '-filter_complex', "[0:v]scale={$w}:{$h},format=yuv420p[v];[1:a]".$this->audioChain($start, $seconds).','.$this->loudnormSecond($measured).'[a]',
            '-map', '[v]', '-map', '[a]'], $this->encode($seconds, $out));
    }

    /** filters when this ffmpeg has xfade and zoompan, frames otherwise (or as configured). */
    public function mode(): string
    {
        if ($this->mode) {
            return $this->mode;
        }
        $configured = (string) config('slideshow.video_mode', 'auto');
        if (in_array($configured, ['filters', 'frames'], true)) {
            return $configured;
        }
        $have = $this->capabilities();

        return in_array('filter xfade', $have, true) && in_array('filter zoompan', $have, true) ? 'filters' : 'frames';
    }

    /** What every render needs that this build lacks (empty when it can make slideshows). */
    public function missing(?string $music = null): array
    {
        $have = $this->capabilities();
        if ($have === []) {
            return ['a working ffmpeg (tried: '.implode(', ', $this->candidates()).')'];
        }
        $needs = self::NEEDS;
        $ext = strtolower(pathinfo((string) $music, PATHINFO_EXTENSION));
        if (isset(self::AUDIO_DECODERS[$ext])) {
            $needs[] = self::AUDIO_DECODERS[$ext];
        }

        return array_values(array_diff($needs, $have));
    }

    /** "filter xfade", "encoder libx264", "demuxer image2" … this build has; cached for an hour. */
    public function capabilities(): array
    {
        $binary = $this->ffmpeg();

        return Cache::remember('slideshow:ffmpeg:'.md5($binary.'|'.@filemtime($binary)), 3600, function () {
            try {
                $have = [];
                foreach (['filters' => 'filter', 'encoders' => 'encoder', 'decoders' => 'decoder', 'demuxers' => 'demuxer'] as $flag => $kind) {
                    $out = $this->run([$this->ffmpeg(), '-hide_banner', "-{$flag}"])->getOutput();
                    preg_match_all('/^\s*[A-Z.]{1,8}\s+([a-z0-9_,]+)\s/m', $out, $m);
                    foreach ($m[1] as $name) {
                        foreach (explode(',', $name) as $one) {
                            $have[] = "{$kind} {$one}";
                        }
                    }
                }

                return array_values(array_unique($have));
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /**
     * The ffmpeg to use: SLIDESHOW_FFMPEG / RADIO_FFMPEG when it runs, else the small build the reel
     * renderer ships with Remotion.
     */
    public function ffmpeg(): string
    {
        static $found = [];
        $candidates = $this->candidates();
        $key = implode('|', $candidates);
        if ($this->factory) {
            return $candidates[0];
        }

        return $found[$key] ??= (function () use ($candidates) {
            foreach ($candidates as $binary) {
                try {
                    (new Process([$binary, '-hide_banner', '-version'], null, null, null, 20))->mustRun();

                    return $binary;
                } catch (\Throwable) {
                    continue;
                }
            }

            return $candidates[0];
        })();
    }

    /** @return list<string> */
    public function candidates(): array
    {
        $list = [(string) (config('slideshow.ffmpeg') ?: config('radio.ffmpeg', 'ffmpeg'))];
        foreach (glob(base_path('reel/node_modules/@remotion/compositor-linux-*/ffmpeg')) ?: [] as $bundled) {
            $list[] = $bundled;
        }

        return array_values(array_unique($list));
    }

    private function encode(float $seconds, string $out): array
    {
        $fps = (int) config('slideshow.fps', 30);

        return ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-r', (string) $fps,
            '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-t', self::num($seconds), '-movflags', '+faststart', $out];
    }

    private function loudnorm(): string
    {
        $l = (array) config('slideshow.music.loudness');

        return 'loudnorm=I='.($l['i'] ?? -14).':TP='.($l['tp'] ?? -1.5).':LRA='.($l['lra'] ?? 11);
    }

    private function loudnormSecond(array $measured): string
    {
        return $this->loudnorm()
            .':measured_I='.($measured['input_i'] ?? '-14').':measured_TP='.($measured['input_tp'] ?? '-1.5')
            .':measured_LRA='.($measured['input_lra'] ?? '7').':measured_thresh='.($measured['input_thresh'] ?? '-24')
            .':offset='.($measured['target_offset'] ?? '0').':linear=true,aresample=48000';
    }

    /** The slide zoomed by $zoom with the safe-area crop (cropFor), scaled back to full size. */
    private function zoomFrame(\GdImage $img, float $zoom, ?array $bbox): \GdImage
    {
        [$x, $y, $cw, $ch] = self::cropFor($zoom, $bbox);
        $crop = imagecrop($img, ['x' => (int) round($x), 'y' => (int) round($y), 'width' => (int) round($cw), 'height' => (int) round($ch)]);
        imagesetinterpolation($crop, IMG_BILINEAR_FIXED);
        $out = imagescale($crop, imagesx($img), imagesy($img), IMG_BILINEAR_FIXED);
        imagedestroy($crop);

        return $out;
    }

    /**
     * Runs ffmpeg with $chunks written to its stdin. Blocking writes keep only one frame in memory at a
     * time; ffmpeg's log goes to a temporary file so a full stderr pipe can never stall it.
     */
    private function pipe(array $args, iterable $chunks): void
    {
        if ($this->factory) {
            $process = ($this->factory)($args);
            $input = new \Symfony\Component\Process\InputStream;
            $process->setInput($input);
            $process->start();
            foreach ($chunks as $chunk) {
                $input->write($chunk);
            }
            $input->close();
            $process->wait();
            if (!$process->isSuccessful()) {
                throw new RuntimeException('ffmpeg failed: '.$process->getErrorOutput());
            }

            return;
        }
        $log = tempnam(sys_get_temp_dir(), 'slideshow-ffmpeg');
        $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start ffmpeg.');
        }
        $deadline = microtime(true) + (int) config('slideshow.timeout', 300);
        try {
            foreach ($chunks as $chunk) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('ffmpeg took longer than the slideshow timeout.');
                }
                for ($written = 0; $written < strlen($chunk);) {
                    $n = @fwrite($pipes[0], substr($chunk, $written));
                    if ($n === false || $n === 0) {
                        throw new RuntimeException('ffmpeg stopped reading frames: '.trim((string) @file_get_contents($log, false, null, -2000)));
                    }
                    $written += $n;
                }
            }
        } finally {
            @fclose($pipes[0]);
            $code = proc_close($proc);
            $tail = trim((string) @file_get_contents($log, false, null, max(0, (int) @filesize($log) - 2000)));
            @unlink($log);
        }
        if ($code !== 0) {
            throw new RuntimeException("ffmpeg failed ({$code}): {$tail}");
        }
    }

    private function run(array $args): Process
    {
        $process = $this->factory ? ($this->factory)($args) : new Process($args, null, null, null, (int) config('slideshow.timeout', 300));
        $process->mustRun();

        return $process;
    }

    private static function num(float $n, int $places = 2): string
    {
        return rtrim(rtrim(number_format($n, $places, '.', ''), '0'), '.') ?: '0';
    }
}
