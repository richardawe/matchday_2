<?php

namespace App\Services;

use App\Models\RadioBulletin;
use App\Services\Slideshow\SlideshowVideo;
use App\Services\Story\StoryFrames;
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
 * Each scene (a story slide from RadioVisuals with a short caption from the script) is a radio
 * cover cut drawn by the shared design engine (App\Design: tokens, components, motion) and streamed
 * as JPEG frames into ffmpeg's image2pipe with the bulletin's own audio (the Griot voice over the
 * talking-drum bed; no other music). That needs only mjpeg, mp3, libx264 and aac, so the small
 * ffmpeg the reel renderer ships is enough. Text stays in the design tokens' safe zone.
 * The result is copied to public/generated/radio/{id}/radio.mp4 (and poster.jpg) so the host
 * serves byte ranges. A failure never touches the published audio.
 */
class RadioVideo
{
    public const NEEDS = ['encoder libx264', 'encoder aac', 'demuxer image2pipe', 'decoder mjpeg', 'decoder mp3'];

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
     * The scenes as radio cover cuts for the shared frame engine: the edition label, the segment's
     * licensed photo or our own art, its headline, and the words being spoken. A new segment wipes
     * in; caption changes within a segment do not.
     */
    public static function cuts(RadioBulletin $bulletin, array $scenes, ?array $photo = null, string $lang = 'en'): array
    {
        $cuts = [];
        $previous = null;
        $stories = array_values(array_unique(array_filter(array_map(fn ($sc) => !empty($sc['slide']['label']) && $sc['slide']['label'] !== "Today's bulletin"
            ? ($sc['slide']['title'] ?? null) : null, $scenes))));
        $last = count($scenes) - 1;
        foreach ($scenes as $i => $scene) {
            $title = $scene['slide']['title'] ?? $bulletin->title;
            $n = array_search($title, $stories, true);
            $story = $n !== false ? $scene['slide']['label'].' · '.($n + 1).' of '.count($stories) : null;
            if ($story === null && $i === $last && $i > 0) {
                // The sign-off gets its own card, so it never repeats the opening one.
                $title = 'Every edition at matchday.africa/listen';
            }
            $art = !empty($scene['slide']['image']) ? public_path($scene['slide']['image']) : null;
            // GD cannot draw the SVG pitch: the crest-free Matchday Radio cover stands in for it.
            if (!$art || !is_file($art) || !preg_match('/\.(png|jpe?g|webp)$/i', $art)) {
                $art = public_path('images/radio/cover.png');
            }
            $cuts[] = ['type' => 'radio', 'start' => $scene['start'], 'seconds' => $scene['end'] - $scene['start'],
                'caption' => $scene['caption'], 'title' => $title, 'art' => $art, 'story' => $story,
                'edition' => $bulletin->editionLabel().' · '.$bulletin->duration_label,
                'photo' => $photo && $story === null ? ['file' => $photo['file'], 'crop' => $photo['crop'] ?? null, 'credit' => $photo['credit'] ?? null] : null,
                'lang' => $lang, 'sides' => null, 'wipe' => $previous !== $title.'|'.$story, 'group' => $title.'|'.$story];
            $previous = $title.'|'.$story;
        }

        return $cuts;
    }

    /**
     * Yields one JPEG per video frame, exactly as many as the audio lasts, drawn by the shared design
     * engine (App\Design\Film through StoryFrames); $poster gets an early frame.
     */
    public function frames(RadioBulletin $bulletin, array $scenes, float $seconds, ?string &$poster = null): \Generator
    {
        $cuts = self::cuts($bulletin, $scenes);
        if ($cuts) {
            $cuts[count($cuts) - 1]['seconds'] = $seconds - $cuts[count($cuts) - 1]['start'];
        }
        yield from app(StoryFrames::class)->frames($cuts, (int) config('radio.video.fps', 30), $poster);
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
