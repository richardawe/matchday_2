<?php

namespace App\Services\Slideshow;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Puts the slides together with ffmpeg: 3.6 s a slide, 0.4 s crossfades, 30 fps, a slow Ken Burns
 * push on slide 01 (from 1.0, capped so no text leaves the safe zone), and the music bed trimmed,
 * faded in 0.3 s and out 1.5 s and normalised to −14 LUFS in two loudnorm passes. H.264 yuv420p,
 * AAC, +faststart. Processes are made through process() so tests can fake ffmpeg.
 */
class SlideshowVideo
{
    /** @var (callable(array): Process)|null */
    private $factory = null;

    /** Swaps the process maker (tests). */
    public function usingProcess(callable $factory): self
    {
        $this->factory = $factory;

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
            foreach ([[$x1, $safe['left'], 'min'], [$x2, $safe['right'], 'max']] as [$x, $edge]) {
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
     * Returns ['seconds' => float, 'zoom' => float, 'loudness' => measured pass-one values].
     */
    public function make(array $slides, string $music, float $musicStart, string $out): array
    {
        if (!$slides) {
            throw new RuntimeException('No slides to put together.');
        }
        if (!is_file($music)) {
            throw new RuntimeException('The music file is missing (storage/app/'.config('slideshow.music.tracks.'.config('slideshow.music.track').'.path').').');
        }
        $seconds = self::duration(count($slides));
        $zoom = self::zoomCap($slides[0]['boxes'] ?? []);
        $measured = $this->measure($music, $musicStart, $seconds);
        $this->run($this->renderArgs($slides, $music, $musicStart, $seconds, $zoom, $measured, "{$out}.part.mp4"));
        if (!is_file("{$out}.part.mp4") || filesize("{$out}.part.mp4") < 10_000) {
            @unlink("{$out}.part.mp4");
            throw new RuntimeException('ffmpeg finished without a usable video.');
        }
        rename("{$out}.part.mp4", $out);

        return ['seconds' => $seconds, 'zoom' => $zoom, 'loudness' => $measured];
    }

    /** The audio chain before loudnorm: trim from the start point, fade in and out. */
    public function audioChain(float $start, float $seconds): string
    {
        $in = (float) config('slideshow.music.fade_in', 0.3);
        $out = (float) config('slideshow.music.fade_out', 1.5);

        return sprintf('atrim=start=%s:duration=%s,asetpts=PTS-STARTPTS,afade=t=in:st=0:d=%s,afade=t=out:st=%s:d=%s',
            self::num($start), self::num($seconds), self::num($in), self::num(max(0, $seconds - $out)), self::num($out));
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

    /** Pass two and the video: the full ffmpeg command. */
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
        $audio = count($slides);
        $graph[] = "[{$audio}:a]".$this->audioChain($start, $seconds).','.$this->loudnorm()
            .':measured_I='.($measured['input_i'] ?? '-14').':measured_TP='.($measured['input_tp'] ?? '-1.5')
            .':measured_LRA='.($measured['input_lra'] ?? '7').':measured_thresh='.($measured['input_thresh'] ?? '-24')
            .':offset='.($measured['target_offset'] ?? '0').':linear=true,aresample=48000[a]';

        return array_merge($args, [
            '-filter_complex', implode(';', $graph),
            '-map', '[v]', '-map', '[a]',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-r', (string) $fps,
            '-c:a', 'aac', '-b:a', '160k', '-ar', '48000',
            '-t', self::num($seconds), '-movflags', '+faststart', $out,
        ]);
    }

    /** Filters and encoders this build lacks (empty when it can make slideshows). */
    public function missing(): array
    {
        try {
            $filters = $this->run([$this->ffmpeg(), '-hide_banner', '-filters'])->getOutput();
            $encoders = $this->run([$this->ffmpeg(), '-hide_banner', '-encoders'])->getOutput();
        } catch (\Throwable $e) {
            return ['ffmpeg ('.$e->getMessage().')'];
        }
        $missing = [];
        foreach (['xfade', 'zoompan', 'loudnorm', 'afade', 'atrim', 'aresample'] as $filter) {
            if (!preg_match('/\s'.$filter.'\s/', $filters)) {
                $missing[] = "filter {$filter}";
            }
        }
        foreach (['libx264', 'aac'] as $encoder) {
            if (!preg_match('/\s'.$encoder.'\s/', $encoders)) {
                $missing[] = "encoder {$encoder}";
            }
        }

        return $missing;
    }

    private function loudnorm(): string
    {
        $l = (array) config('slideshow.music.loudness');

        return 'loudnorm=I='.($l['i'] ?? -14).':TP='.($l['tp'] ?? -1.5).':LRA='.($l['lra'] ?? 11);
    }

    private function ffmpeg(): string
    {
        return (string) (config('slideshow.ffmpeg') ?: config('radio.ffmpeg', 'ffmpeg'));
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
