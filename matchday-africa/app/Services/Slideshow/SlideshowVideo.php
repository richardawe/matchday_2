<?php

namespace App\Services\Slideshow;

use App\Design\Film;
use App\Design\Tokens;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Puts a slideshow together with ffmpeg: the cuts are drawn frame by frame by the shared design
 * engine (App\Design\Film: kinetic text, Ken Burns on photos, colour-band wipes, the logo) and
 * streamed as JPEGs into ffmpeg's image2pipe, with the music bed (design tokens music: our own
 * track) trimmed, faded and normalised to -14 LUFS in two loudnorm passes. H.264 yuv420p, AAC,
 * +faststart. Needs only mjpeg, libx264, aac and loudnorm, so the small ffmpeg the reel renderer
 * ships (reel/node_modules/@remotion/compositor-*) is enough. Processes are made through run() so
 * tests can fake ffmpeg.
 */
class SlideshowVideo
{
    /** Filters and encoders every render needs. */
    public const NEEDS = ['filter loudnorm', 'filter volume', 'filter atrim', 'filter aresample', 'filter scale', 'filter format',
        'encoder libx264', 'encoder aac', 'demuxer image2pipe', 'decoder mjpeg', 'decoder png'];

    /** Decoder each music file type needs. */
    private const AUDIO_DECODERS = ['mp3' => 'decoder mp3', 'm4a' => 'decoder aac', 'aac' => 'decoder aac', 'wav' => 'decoder pcm_s16le', 'flac' => 'decoder flac', 'ogg' => 'decoder vorbis'];

    /** @var (callable(array): Process)|null */
    private $factory = null;

    /** Swaps the process maker (tests). */
    public function usingProcess(callable $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    public function __construct(private ?Film $film = null) {}

    /** Seconds for a deck of $cuts cuts (design tokens motion.cut.default each). */
    public static function duration(int $cuts): float
    {
        return round($cuts * (float) Tokens::get('motion.cut.default'), 2);
    }

    /**
     * Renders the cuts (component data for App\Design\Film) with the music into $out.
     * Returns ['seconds', 'loudness' => measured pass-one values, 'mode' => 'frames'].
     */
    public function make(array $cuts, string $music, float $musicStart, string $out): array
    {
        if (!$cuts) {
            throw new RuntimeException('No slides to put together.');
        }
        if (!is_file($music)) {
            throw new RuntimeException('The music file is missing ('.$music.').');
        }
        $per = (float) Tokens::get('motion.cut.default');
        $at = 0.0;
        foreach ($cuts as $i => $cut) {
            $cuts[$i] += ['start' => $at, 'seconds' => $per];
            $at += $per;
        }
        $seconds = self::duration(count($cuts));
        $measured = $this->measure($music, $musicStart, $seconds);
        $part = "{$out}.part.mp4";
        $film = $this->film ?? app(Film::class);
        $this->pipe($this->sequenceArgs($music, $musicStart, $seconds, $measured, $part), $film->frames(array_values($cuts), (int) config('slideshow.fps', 30)));
        if (!is_file($part) || filesize($part) < 10_000) {
            @unlink($part);
            throw new RuntimeException('ffmpeg finished without a usable video.');
        }
        rename($part, $out);

        return ['seconds' => $seconds, 'mode' => $this->mode(), 'loudness' => $measured];
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

    /** Pass two: JPEG frames on stdin, the music, the encoder. */
    public function sequenceArgs(string $music, float $start, float $seconds, array $measured, string $out): array
    {
        $fps = (int) config('slideshow.fps', 30);
        [$w, $h] = config('slideshow.size', [1080, 1920]);

        return array_merge([$this->ffmpeg(), '-hide_banner', '-y', '-f', 'image2pipe', '-framerate', (string) $fps, '-c:v', 'mjpeg', '-i', '-', '-i', $music,
            '-filter_complex', "[0:v]scale={$w}:{$h},format=yuv420p[v];[1:a]".$this->audioChain($start, $seconds).','.$this->loudnormSecond($measured).'[a]',
            '-map', '[v]', '-map', '[a]'], $this->encode($seconds, $out));
    }

    /** Frames are always drawn by the design engine now (no xfade or zoompan needed). */
    public function mode(): string
    {
        return 'frames';
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

    /**
     * Runs ffmpeg with $chunks written to its stdin. Blocking writes keep only one frame in memory at a
     * time; ffmpeg's log goes to a temporary file so a full stderr pipe can never stall it.
     */
    public function pipe(array $args, iterable $chunks, ?int $timeout = null): void
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
        $deadline = microtime(true) + ($timeout ?? (int) config('slideshow.timeout', 300));
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
