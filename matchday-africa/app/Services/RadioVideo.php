<?php

namespace App\Services;

use App\Models\RadioBulletin;
use App\Services\Slideshow\SlideshowVideo;
use App\Support\SocialLink;
use App\Support\XText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The vertical video of a published Matchday Radio edition, for TikTok, Reels and Shorts:
 * 1080x1920, 30 fps, H.264 yuv420p + AAC, +faststart, as long as the published mp3.
 *
 * Each scene (a story slide from RadioVisuals with a short caption from the script) is drawn once
 * with GD and streamed as repeated JPEG frames into ffmpeg's image2pipe with the bulletin's own
 * audio (the Griot voice over the talking-drum bed; no other music). That needs only mjpeg, mp3,
 * libx264 and aac, so the small ffmpeg the reel renderer ships is enough.
 *
 * Content stays in the TikTok-safe column the slideshows use: x 140–940, y 150–1540.
 * The result is copied to public/generated/radio/{id}/radio.mp4 (and poster.jpg) so the host
 * serves byte ranges. A failure never touches the published audio.
 */
class RadioVideo
{
    public const NEEDS = ['encoder libx264', 'encoder aac', 'demuxer image2pipe', 'decoder mjpeg', 'decoder mp3'];

    private const LEFT = 140;
    private const WIDTH = 800;
    private const TOP = 150;
    private const BOTTOM = 1540;

    private const INK = [16, 20, 15];
    private const PANEL = [27, 32, 25];
    private const GOLD = [233, 185, 73];
    private const PAPER = [246, 239, 224];
    private const MUTED = [190, 182, 168];

    public function __construct(private RadioVisuals $visuals, private SlideshowVideo $encoder) {}

    /** On (RADIO_VIDEO_ENABLED), radio on and migrated. */
    public static function ready(): bool
    {
        return config('radio.video.enabled') && self::columnsReady();
    }

    public static function columnsReady(): bool
    {
        static $ready = null;

        return RadioBulletin::available() && ($ready ??= Schema::hasColumn('radio_bulletins', 'video_status'));
    }

    /** What this ffmpeg lacks for radio videos (empty when it can make them). */
    public function missing(): array
    {
        $have = $this->encoder->capabilities();
        if ($have === []) {
            return ['a working ffmpeg (tried: '.implode(', ', $this->encoder->candidates()).')'];
        }

        return array_values(array_diff(self::NEEDS, $have));
    }

    /** The next published edition that needs a video, newest first; marks short ones too_short on the way. */
    public function next(): ?RadioBulletin
    {
        $min = (int) config('radio.video.min_seconds', 60);
        RadioBulletin::where('video_status', 'rendering')->where('updated_at', '<', now()->subMinutes(30))
            ->update(['video_status' => 'failed', 'video_error' => 'The video worker stopped before finishing.']);
        $candidates = RadioBulletin::published()
            ->where('published_at', '>=', now()->subDays((int) config('radio.video.days', 3)))
            ->where(fn ($q) => $q->whereNull('video_status')
                ->orWhere(fn ($q) => $q->where('video_status', 'failed')->where('video_attempts', '<', (int) config('radio.video.max_attempts', 3))))
            ->orderByDesc('published_at')->get();
        foreach ($candidates as $bulletin) {
            if ((int) $bulletin->duration_seconds <= $min) {
                $bulletin->update(['video_status' => 'too_short', 'video_error' => "The audio is {$bulletin->duration_seconds} s; videos need more than {$min} s."]);

                continue;
            }
            if ($bulletin->audioFile()) {
                return $bulletin;
            }
        }

        return null;
    }

    /** Renders one edition's video. Records ready or failed on the row; never changes the audio or the publish state. */
    public function render(RadioBulletin $bulletin): bool
    {
        $bulletin->update(['video_status' => 'rendering', 'video_attempts' => $bulletin->video_attempts + 1, 'video_error' => null]);
        $tmp = storage_path('app/radio/'.$bulletin->id.'/video-'.Str::uuid().'.mp4');
        try {
            $audio = $bulletin->audioFile() ?? throw new RuntimeException('The published audio file is missing.');
            $min = (int) config('radio.video.min_seconds', 60);
            $seconds = $this->audioSeconds($audio) ?? (float) $bulletin->duration_seconds;
            if ($seconds <= $min) {
                throw new RuntimeException("The audio is {$seconds} s; videos need more than {$min} s.");
            }
            File::ensureDirectoryExists(dirname($tmp));
            $scenes = $this->scenes($bulletin, $seconds);
            $poster = null;
            $this->encoder->pipe($this->args($audio, $tmp), $this->frames($bulletin, $scenes, $seconds, $poster), (int) config('radio.video.timeout', 900));

            $length = SocialQueue::mp4Seconds($tmp);
            if ($length === null || $length <= $min || abs($length - $seconds) > 1.5) {
                throw new RuntimeException('The video length ('.($length === null ? 'unreadable' : round($length, 1).' s').") does not match the audio ({$seconds} s).");
            }
            $public = $bulletin->publicVideoPath();
            File::ensureDirectoryExists(dirname($public));
            File::copy($tmp, $public.'.part');
            rename($public.'.part', $public);
            if ($poster) {
                File::put($bulletin->publicPosterPath().'.part', $poster);
                rename($bulletin->publicPosterPath().'.part', $bulletin->publicPosterPath());
            }
            $bulletin->update(['video_status' => 'ready', 'video_error' => null, 'video_seconds' => round($length, 2),
                'video_bytes' => filesize($public), 'video_rendered_at' => now()]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Radio video failed', ['bulletin' => $bulletin->id, 'error' => $e->getMessage()]);
            $bulletin->update(['video_status' => 'failed', 'video_error' => Str::limit($e->getMessage(), 1000)]);

            return false;
        } finally {
            File::delete($tmp);
        }
    }

    /**
     * The scenes in order, covering 0 to $seconds without gaps: [start, end, slide, caption].
     * Intro and outro use the edition card; each voiced segment uses its story slide. Captions are
     * short chunks of what is being said, timed by length within the paragraph's measured cue.
     */
    public function scenes(RadioBulletin $bulletin, float $seconds): array
    {
        $script = (string) $bulletin->script;
        $intro = trim((string) strstr($script, "\n\n", true));
        $outro = str_contains($script, "\n\n") ? trim(substr($script, strrpos($script, "\n\n") + 2)) : '';
        $card = ['title' => $bulletin->title, 'label' => "Today's bulletin", 'image' => null];
        $blocks = [];
        $cues = array_values(array_filter((array) $bulletin->audio_cues, fn ($c) => is_numeric($c['start'] ?? null) && is_numeric($c['end'] ?? null)));
        if ($cues) {
            $blocks[] = [0.0, (float) $cues[0]['start'], $card, $intro];
            foreach ((array) $bulletin->audio_cues as $index => $cue) {
                if (!is_numeric($cue['start'] ?? null) || !is_numeric($cue['end'] ?? null)) {
                    continue;
                }
                $slide = $this->visuals->segment($bulletin, $index) ?? $card;
                $blocks[] = [(float) $cue['start'], (float) $cue['end'], $slide, (string) ($bulletin->segments[$index]['text'] ?? '')];
            }
            $blocks[] = [(float) end($cues)['end'], $seconds, $card, $outro];
        } else {
            $blocks[] = [0.0, $seconds, $card, $script];
        }

        // Close the gaps: each block runs until the next starts; the last until the end.
        $scenes = [];
        foreach ($blocks as $i => [$start, $end, $slide, $text]) {
            $end = isset($blocks[$i + 1]) ? $blocks[$i + 1][0] : $seconds;
            if ($end - $start < 0.05) {
                continue;
            }
            $chunks = self::chunks($text);
            if (!$chunks) {
                $scenes[] = ['start' => $start, 'end' => $end, 'slide' => $slide, 'caption' => null];

                continue;
            }
            $total = array_sum(array_map('mb_strlen', $chunks));
            $at = $start;
            foreach ($chunks as $j => $chunk) {
                $to = $j === count($chunks) - 1 ? $end : $at + ($end - $start) * mb_strlen($chunk) / $total;
                $scenes[] = ['start' => $at, 'end' => $to, 'slide' => $slide, 'caption' => $chunk];
                $at = $to;
            }
        }
        if ($scenes) {
            $scenes[0]['start'] = 0.0;
        }

        return $scenes;
    }

    /** Splits a paragraph into readable caption chunks of up to $max characters, at sentence or clause breaks where possible. */
    public static function chunks(string $text, int $max = 84): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }
        $chunks = [];
        $current = '';
        foreach (preg_split('/(?<=[.!?;:,])\s+/u', $text) as $piece) {
            foreach (self::wordsUpTo($piece, $max) as $part) {
                if ($current !== '' && mb_strlen($current.' '.$part) > $max) {
                    $chunks[] = $current;
                    $current = $part;
                } else {
                    $current = $current === '' ? $part : $current.' '.$part;
                }
                if (preg_match('/[.!?]$/u', $current) && mb_strlen($current) > $max / 2) {
                    $chunks[] = $current;
                    $current = '';
                }
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** ffmpeg: JPEG frames on stdin plus the edition's mp3, encoded for social video. */
    public function args(string $audio, string $out): array
    {
        $fps = (string) config('radio.video.fps', 30);

        return [$this->encoder->ffmpeg(), '-hide_banner', '-y', '-f', 'image2pipe', '-framerate', $fps, '-c:v', 'mjpeg', '-i', '-',
            '-i', $audio, '-map', '0:v', '-map', '1:a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24', '-pix_fmt', 'yuv420p', '-r', $fps,
            '-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-movflags', '+faststart', '-f', 'mp4', $out];
    }

    /** The mp3's exact length from ffmpeg's own reading of the file (any build prints "Duration:"), or null. */
    public function audioSeconds(string $audio): ?float
    {
        try {
            $process = new \Symfony\Component\Process\Process([$this->encoder->ffmpeg(), '-hide_banner', '-i', $audio], null, null, null, 60);
            $process->run(); // exits 1 without an output file; the header is on stderr
            if (preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $process->getErrorOutput(), $m)) {
                $seconds = $m[1] * 3600 + $m[2] * 60 + (float) $m[3];

                return $seconds > 0 ? $seconds : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Yields one JPEG per video frame, exactly as many as the audio lasts (ffmpeg stops reading once it
     * has them all). Each scene is drawn once and its bytes repeated; $poster gets the first story scene.
     */
    public function frames(RadioBulletin $bulletin, array $scenes, float $seconds, ?string &$poster = null): \Generator
    {
        $fps = (int) config('radio.video.fps', 30);
        $total = max(1, (int) round($seconds * $fps));
        $frame = 0;
        foreach ($scenes as $i => $scene) {
            $last = $i === count($scenes) - 1 ? $total : min($total, (int) round($scene['end'] * $fps));
            if ($last <= $frame) {
                continue;
            }
            $jpeg = $this->draw($bulletin, $scene['slide'], $scene['caption']);
            if ($poster === null && ($scene['slide']['image'] ?? null)) {
                $poster = $jpeg;
            }
            for (; $frame < $last; $frame++) {
                yield $jpeg;
            }
        }
        if ($poster === null && isset($jpeg)) {
            $poster = $jpeg;
        }
    }

    /** One 1080x1920 frame as JPEG bytes. */
    public function draw(RadioBulletin $bulletin, array $slide, ?string $caption): string
    {
        [$w, $h] = config('radio.video.size', [1080, 1920]);
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        $c = fn (array $rgb, int $alpha = 0) => imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $alpha);
        imagefill($img, 0, 0, $c(self::INK));
        imagefilledellipse($img, (int) ($w * 0.82), 300, 760, 760, $c(self::GOLD, 118));

        $anton = resource_path('fonts/Anton-Regular.ttf');
        $bold = resource_path('fonts/AsapCondensed-Bold.ttf');
        $semi = resource_path('fonts/AsapCondensed-SemiBold.ttf');

        // Header: brand and edition.
        $y = self::TOP + 70;
        imagettftext($img, 54, 0, self::LEFT, $y, $c(self::GOLD), $anton, 'Matchday Radio');
        $y += 58;
        imagettftext($img, 30, 0, self::LEFT, $y, $c(self::MUTED), $semi, $bulletin->editionLabel().' · '.$bulletin->duration_label);

        // Label pill and title.
        $y += 40;
        $label = (string) ($slide['label'] ?? 'Matchday Radio');
        $box = imagettfbbox(28, 0, $bold, $label);
        $pillW = ($box[2] - $box[0]) + 48;
        imagefilledrectangle($img, self::LEFT, $y, self::LEFT + $pillW, $y + 56, $c(self::GOLD));
        imagettftext($img, 28, 0, self::LEFT + 24, $y + 40, $c(self::INK), $bold, $label);
        $y += 56 + 30;
        $titleLines = array_slice(self::wrap((string) ($slide['title'] ?? ''), $anton, 60, self::WIDTH), 0, 3);
        foreach ($titleLines as $line) {
            $y += 86; // GD sizes are points: 60 pt is 80 px
            imagettftext($img, 60, 0, self::LEFT, $y, $c(self::PAPER), $anton, $line);
        }

        // Caption panel and footer pill sit at the bottom of the safe column.
        $footerTop = self::BOTTOM - 64;
        $captionBottom = $footerTop - 28;
        $captionTop = $captionBottom - 290;

        // Artwork between the title and the caption.
        $artTop = $y + 36;
        $artBottom = $captionTop - 30;
        if ($artBottom - $artTop > 160) {
            imagefilledrectangle($img, self::LEFT, $artTop, self::LEFT + self::WIDTH, $artBottom, $c(self::PANEL));
            $this->art($img, $slide['image'] ?? null, self::LEFT + 20, $artTop + 20, self::WIDTH - 40, $artBottom - $artTop - 40);
        }

        if ($caption !== null && $caption !== '') {
            imagefilledrectangle($img, self::LEFT, $captionTop, self::LEFT + self::WIDTH, $captionBottom, $c(self::PAPER));
            imagefilledrectangle($img, self::LEFT, $captionTop, self::LEFT + 10, $captionBottom, $c(self::GOLD));
            $size = 46;
            $lines = self::wrap($caption, $bold, $size, self::WIDTH - 80);
            while (count($lines) > 4 && $size > 32) {
                $size -= 4;
                $lines = self::wrap($caption, $bold, $size, self::WIDTH - 80);
            }
            $lines = array_slice($lines, 0, 4);
            $lineH = (int) round($size * 1.32);
            $ty = $captionTop + (int) ((($captionBottom - $captionTop) - count($lines) * $lineH) / 2) + $size;
            foreach ($lines as $line) {
                imagettftext($img, $size, 0, self::LEFT + 44, $ty, $c(self::INK), $bold, $line);
                $ty += $lineH;
            }
        }

        $pill = 'matchday.africa';
        $box = imagettfbbox(30, 0, $bold, $pill);
        $pw = ($box[2] - $box[0]) + 60;
        $px = (int) (self::LEFT + (self::WIDTH - $pw) / 2);
        imagefilledrectangle($img, $px, $footerTop, $px + $pw, self::BOTTOM, $c(self::GOLD));
        imagettftext($img, 30, 0, $px + 30, $footerTop + 44, $c(self::INK), $bold, $pill);

        ob_start();
        imagejpeg($img, null, 86);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Fits the slide's rights-safe image (public path) into the box; draws a pitch when there is none or GD cannot read it. */
    private function art(\GdImage $img, ?string $path, int $x, int $y, int $w, int $h): void
    {
        $src = null;
        $file = $path ? public_path($path) : null;
        if ($file && is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $src = match ($ext) {
                'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : null,
                'png' => @imagecreatefrompng($file),
                'jpg', 'jpeg' => @imagecreatefromjpeg($file),
                default => null,
            } ?: null;
        }
        if (!$src) {
            $this->pitch($img, $x, $y, $w, $h);

            return;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min($w / $sw, $h / $sh);
        $dw = (int) round($sw * $scale);
        $dh = (int) round($sh * $scale);
        imagecopyresampled($img, $src, $x + (int) (($w - $dw) / 2), $y + (int) (($h - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);
    }

    /** A plain pitch drawing (the listen page's football.svg is not readable by GD). */
    private function pitch(\GdImage $img, int $x, int $y, int $w, int $h): void
    {
        $green = imagecolorallocate($img, 46, 125, 74);
        $stripe = imagecolorallocate($img, 52, 138, 82);
        $line = imagecolorallocate($img, 236, 244, 236);
        imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $green);
        for ($i = 0; $i < 8; $i += 2) {
            imagefilledrectangle($img, $x, $y + (int) ($h * $i / 8), $x + $w, $y + (int) ($h * ($i + 1) / 8), $stripe);
        }
        imagesetthickness($img, 5);
        $m = 24;
        imagerectangle($img, $x + $m, $y + $m, $x + $w - $m, $y + $h - $m, $line);
        imageline($img, $x + $m, $y + (int) ($h / 2), $x + $w - $m, $y + (int) ($h / 2), $line);
        $r = (int) min($w, $h) / 4;
        imageellipse($img, $x + (int) ($w / 2), $y + (int) ($h / 2), $r * 2, $r * 2, $line);
        imagesetthickness($img, 1);
    }

    /** Word-wraps $text to lines no wider than $width at $size in $font. */
    private static function wrap(string $text, string $font, int $size, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            if ($word === '') {
                continue;
            }
            $try = $line === '' ? $word : $line.' '.$word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($line !== '' && ($box[2] - $box[0]) > $width) {
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

    /** A piece longer than $max broken into word runs of up to $max characters. */
    private static function wordsUpTo(string $piece, int $max): array
    {
        if (mb_strlen($piece) <= $max) {
            return [$piece];
        }
        $out = [];
        $run = '';
        foreach (explode(' ', $piece) as $word) {
            if ($run !== '' && mb_strlen($run.' '.$word) > $max) {
                $out[] = $run;
                $run = $word;
            } else {
                $run = $run === '' ? $word : $run.' '.$word;
            }
        }
        if ($run !== '') {
            $out[] = $run;
        }

        return $out;
    }

    // ---- Social queue and export ----------------------------------------------------------------

    /** The headlines the edition covers, in running order (up to $limit). */
    public static function headlines(RadioBulletin $bulletin, int $limit = 3): Collection
    {
        $facts = collect($bulletin->facts)->keyBy('key');

        return collect($bulletin->segments)->flatMap(fn ($s) => (array) ($s['fact_keys'] ?? []))
            ->map(fn ($k) => trim((string) data_get($facts->get($k), 'headline')))
            ->filter()->unique()->take($limit)->values();
    }

    /** Per-channel landing links to the edition's page, tagged utm_campaign=radio. */
    public static function landingUrls(RadioBulletin $bulletin): array
    {
        $page = SocialQueue::https(route('radio.show', $bulletin));

        return collect(SocialLink::CHANNELS)->mapWithKeys(fn ($c) => [$c => SocialLink::to($page, $c, 'radio', "radio-{$bulletin->id}")])->all();
    }

    /**
     * Captions per platform. Instagram, TikTok and YouTube carry the full AI and Kokoro disclosure;
     * X carries a short form of it and fits 280 weighted characters.
     */
    public static function captions(RadioBulletin $bulletin): array
    {
        $links = self::landingUrls($bulletin);
        $edition = 'Matchday Radio, '.$bulletin->editionLabel();
        $headlines = self::headlines($bulletin);
        $list = $headlines->isNotEmpty() ? $headlines->join('. ').'.' : '';
        $disclosure = (string) config('radio.disclosure');
        $long = fn (string $channel) => SocialQueue::plain(trim($edition.".\n\n".($list !== '' ? $list."\n\n" : '')
            .'Listen and read the sources: '.$links[$channel]."\n\n".$disclosure."\n\n#MatchdayRadio #AfricanFootball"));

        return [
            'x' => SocialQueue::plain(XText::compose($edition.'. Written with AI, voiced with Kokoro. '.$list, $links['x'])),
            'instagram' => $long('instagram'),
            'tiktok' => $long('tiktok'),
            'youtube' => $long('youtube'),
        ];
    }
}
