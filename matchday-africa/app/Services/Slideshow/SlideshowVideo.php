<?php

namespace App\Services\Slideshow;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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
 *   frames   PHP/GD draws the zoom and crossfade frames into a numbered PNG sequence (held frames are
 *            hard links) that ffmpeg reads with image2. Needs only png, libx264, aac and loudnorm, so it
 *            works with the small ffmpeg the reel renderer ships (reel/node_modules/@remotion/compositor-*).
 * Processes are made through run() so tests can fake ffmpeg.
 */
class SlideshowVideo
{
    /** Filters and encoders every render needs; xfade and zoompan only for the filters mode. */
    public const NEEDS = ['filter loudnorm', 'filter volume', 'filter atrim', 'filter aresample', 'filter scale', 'filter format',
        'encoder libx264', 'encoder aac', 'demuxer image2', 'decoder png'];

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
     * The largest zoom (about the frame's centre) at which every text box stays inside the safe zone,
     * no more than config slideshow.zoom_max.
     */
    public static function zoomCap(array $boxes): float
    {
        [$w, $h] = config('slideshow.size', [1080, 1920]);
        $safe = config('slideshow.safe');
        [$cx, $cy] = [$w / 2, $h / 2];
        $zoom = (float) config('slideshow.zoom_max', 1.04);
        foreach ($boxes as [$x1, $y1, $x2, $y2]) {
            foreach ([[$x1, $safe['left']], [$x2, $safe['right']]] as [$x, $edge]) {
                if (abs($x - $cx) > 0.5 && ($edge - $cx) / ($x - $cx) > 0) {
                    $zoom = min($zoom, ($edge - $cx) / ($x - $cx));
                }
            }
            foreach ([[$y1, $safe['top']], [$y2, $safe['bottom']]] as [$y, $edge]) {
                if (abs($y - $cy) > 0.5 && ($edge - $cy) / ($y - $cy) > 0) {
                    $zoom = min($zoom, ($edge - $cy) / ($y - $cy));
                }
            }
        }

        return max(1.0, floor($zoom * 1000) / 1000);
    }

    /**
     * Renders $slides (['file' => png, 'boxes' => […]] in order) with the music at $music into $out.
     * Returns ['seconds', 'zoom', 'mode', 'loudness' => measured pass-one values].
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
        $zoom = self::zoomCap($slides[0]['boxes'] ?? []);
        $mode = $this->mode();
        $measured = $this->measure($music, $musicStart, $seconds);
        $part = "{$out}.part.mp4";
        if ($mode === 'filters') {
            $this->run($this->renderArgs($slides, $music, $musicStart, $seconds, $zoom, $measured, $part));
        } else {
            $dir = dirname($out).'/frames';
            try {
                $list = $this->frames($slides, $zoom, $dir);
                $this->run($this->sequenceArgs($list, $music, $musicStart, $seconds, $measured, $part));
            } finally {
                File::deleteDirectory($dir);
            }
        }
        if (!is_file($part) || filesize($part) < 10_000) {
            @unlink($part);
            throw new RuntimeException('ffmpeg finished without a usable video.');
        }
        rename($part, $out);

        return ['seconds' => $seconds, 'zoom' => $zoom, 'mode' => $mode, 'loudness' => $measured];
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

    /** Pass two in the filters mode: the slides through xfade and zoompan, with the normalised music. */
    public function renderArgs(array $slides, string $music, float $start, float $seconds, float $zoom, array $measured, string $out): array
    {
        $fps = (int) config('slideshow.fps', 30);
        $slide = (float) config('slideshow.slide_seconds', 3.6);
        $fade = (float) config('slideshow.crossfade_seconds', 0.4);
        [$w, $h] = config('slideshow.size', [1080, 1920]);
        $args = [$this->ffmpeg(), '-hide_banner', '-y'];
        foreach ($slides as $s) {
            $args = array_merge($args, ['-loop', '1', '-framerate', (string) $fps, '-t', self::num($slide), '-i', $s['file']]);
        }
        $args = array_merge($args, ['-i', $music]);

        $frames = (int) round($slide * $fps);
        $graph = [];
        foreach ($slides as $i => $s) {
            $chain = "[{$i}:v]scale={$w}:{$h},setsar=1,format=yuv420p";
            if ($i === 0 && $zoom > 1.0) {
                // Ken Burns: from 1.0 to $zoom over the slide, centred (the cap keeps text in the safe zone).
                $step = self::num(($zoom - 1) / max(1, $frames - 1), 6);
                $chain = "[0:v]scale={$w}:{$h},setsar=1,zoompan=z='min(1+{$step}*on,".self::num($zoom, 3)
                    .")':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s={$w}x{$h}:fps={$fps},format=yuv420p";
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
     * The frames mode's pictures: slide 01's zoom frame by frame, each crossfade frame by frame,
     * and every slide's still middle held, as a numbered sequence covering exactly the deck's length.
     * Returns ['pattern' => …/s%04d.png, 'frames' => count, 'entries' => [[file, seconds], …]].
     */
    public function frames(array $slides, float $zoom, string $dir): array
    {
        File::ensureDirectoryExists($dir);
        $fps = (int) config('slideshow.fps', 30);
        $slide = (float) config('slideshow.slide_seconds', 3.6);
        $fade = (float) config('slideshow.crossfade_seconds', 0.4);
        $frame = 1 / $fps;
        $slideFrames = (int) round($slide * $fps);
        $fadeFrames = (int) round($fade * $fps);
        $images = array_map(fn ($s) => imagecreatefrompng($s['file']), $slides);
        $count = count($slides);
        $list = [];
        $n = 0;
        $save = function ($img) use ($dir, &$n) {
            $file = sprintf('%s/f%04d.png', $dir, ++$n);
            imagepng($img, $file, 1);

            return $file;
        };
        // Slide 01's zoomed frame $f (0-based) of its whole showing.
        $zoomed = function (int $f) use ($images, $zoom, $slideFrames) {
            $z = 1 + ($zoom - 1) * min(1, $f / max(1, $slideFrames - 1));

            return $z > 1.0005 ? $this->zoomFrame($images[0], $z) : null;
        };

        try {
            foreach ($images as $i => $img) {
                // Frames this slide shows alone: after the fade in, before the fade out.
                $from = $i === 0 ? 0 : $fadeFrames;
                $to = $i === $count - 1 ? $slideFrames : $slideFrames - $fadeFrames;
                if ($i === 0 && $zoom > 1.0) {
                    for ($f = $from; $f < $to; $f++) {
                        $z = $zoomed($f);
                        $list[] = [$z ? $save($z) : $slides[0]['file'], $frame];
                        $z && imagedestroy($z);
                    }
                } else {
                    $list[] = [$slides[$i]['file'], ($to - $from) / $fps];
                }
                if ($i < $count - 1) {
                    for ($f = 0; $f < $fadeFrames; $f++) {
                        $base = $i === 0 && $zoom > 1.0 ? ($zoomed($to + $f) ?? $img) : $img;
                        $mix = imagecreatetruecolor(imagesx($img), imagesy($img));
                        imagecopy($mix, $base, 0, 0, 0, 0, imagesx($img), imagesy($img));
                        imagecopymerge($mix, $images[$i + 1], 0, 0, 0, 0, imagesx($img), imagesy($img), (int) round(100 * ($f + 1) / ($fadeFrames + 1)));
                        $list[] = [$save($mix), $frame];
                        imagedestroy($mix);
                        $base !== $img && imagedestroy($base);
                    }
                }
            }
        } finally {
            foreach ($images as $img) {
                imagedestroy($img);
            }
        }
        // One numbered file per output frame (an exact frame count): drawn frames are renamed into place,
        // held frames are hard links to their slide (no drawing, almost no disk).
        $seq = 0;
        foreach ($list as [$file, $seconds]) {
            for ($k = 0, $copies = (int) round($seconds * $fps); $k < $copies; $k++) {
                $target = sprintf('%s/s%04d.png', $dir, ++$seq);
                if (str_starts_with($file, $dir.'/f') && $k === 0 && $copies === 1) {
                    rename($file, $target);
                } elseif (!@link($file, $target)) {
                    copy($file, $target);
                }
            }
        }

        return ['pattern' => "{$dir}/s%04d.png", 'frames' => $seq, 'entries' => $list];
    }

    /** Pass two in the frames mode: the numbered frames, the music, the encoder. */
    public function sequenceArgs(array $frames, string $music, float $start, float $seconds, array $measured, string $out): array
    {
        $fps = (int) config('slideshow.fps', 30);
        [$w, $h] = config('slideshow.size', [1080, 1920]);

        return array_merge([$this->ffmpeg(), '-hide_banner', '-y', '-framerate', (string) $fps, '-start_number', '1', '-i', $frames['pattern'], '-i', $music,
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

    /** The slide scaled by $zoom about its centre, cropped back to full size. */
    private function zoomFrame(\GdImage $img, float $zoom): \GdImage
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        $sw = (int) round($w / $zoom);
        $sh = (int) round($h / $zoom);
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $img, 0, 0, (int) round(($w - $sw) / 2), (int) round(($h - $sh) / 2), $w, $h, $sw, $sh);

        return $out;
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
