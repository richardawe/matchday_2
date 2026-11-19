<?php

namespace App\Services;

use App\Models\FeaturedItem;
use App\Models\RadioBulletin;
use App\Models\Slideshow;
use App\Models\SocialVideo;
use App\Services\Slideshow\SlideshowVideo;
use App\Support\SocialLink;
use App\Support\XText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Long videos for TikTok (SocialVideo): upload (admin page or the posting bot's API), captions and
 * tagged landing links for the social queue ("video:<id>"), and the "As seen on TikTok" row on /reels.
 *
 * The row: featured long videos first (sort order, then newest), then slideshows and radio editions an
 * editor pinned (featured_items), then, if there is room, the newest approved slideshows and ready
 * radio videos. Nothing qualifying means no row.
 */
class SocialVideos
{
    public const MAX_KB = 200 * 1024; // 200 MB, as /admin/social-media

    /** Longer than this is a long video; a clip this long or shorter needs short=1. */
    public const SHORT_SECONDS = 60;

    public const ROW_LIMIT = 8;

    /** Validation rules shared by the admin upload and the API. */
    public static function rules(bool $withFile = true): array
    {
        return array_filter([
            'file' => $withFile ? ['required', 'file', 'mimes:mp4', 'max:'.self::MAX_KB] : null,
            'poster' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'title' => ['required', 'string', 'max:150'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:2200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['nullable', Rule::in(SocialVideo::TYPES)],
            'campaign' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/i'],
            'credits' => ['nullable', 'string', 'max:1000'],
            'featured' => ['nullable', 'boolean'],
            'short' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'landing_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^/\s][^\s]*$#'],
            'tiktok_url' => ['nullable', 'url', 'max:500', 'regex:#^https://#i'],
            'youtube_url' => ['nullable', 'url', 'max:500', 'regex:#^https://#i'],
        ]);
    }

    public static function messages(): array
    {
        return [
            'file.mimes' => 'Upload an MP4 file.',
            'file.max' => 'The file is larger than 200 MB.',
            'file.uploaded' => 'The upload did not arrive. It may be larger than the server allows.',
            'landing_path.regex' => 'The landing page must be a path on this site, such as /beat-the-griot.',
        ];
    }

    /** Null when the length is fine, else the reason it is not. */
    public static function lengthProblem(?float $seconds, bool $short): ?string
    {
        if ($seconds === null) {
            return 'Could not read the video length. Upload an MP4 with a moov header.';
        }
        if ($seconds <= self::SHORT_SECONDS && !$short) {
            return 'This video is '.round($seconds, 1).' s long. Long videos must be over '.self::SHORT_SECONDS.' s; mark it as a short clip to upload it anyway.';
        }

        return null;
    }

    /**
     * Stores the video as public/generated/videos/{id}/video.mp4 (and a poster: the uploaded image,
     * else a frame from ffmpeg when it runs). The row is removed again if the file can't be placed.
     */
    public function create(UploadedFile $file, ?UploadedFile $poster, array $data, float $seconds, ?int $userId, string $source, string $status): SocialVideo
    {
        $video = SocialVideo::create([
            'title' => trim($data['title']),
            'caption' => self::clean($data['caption'] ?? null),
            'captions' => collect((array) ($data['captions'] ?? []))->only(SocialQueue::CHANNELS)->map(fn ($c) => self::clean($c))->filter()->all() ?: null,
            'description' => self::clean($data['description'] ?? null),
            'duration_seconds' => round($seconds, 1),
            'type' => $data['type'] ?? 'documentary',
            'campaign' => strtolower($data['campaign'] ?? '') ?: 'documentary',
            'featured' => (bool) ($data['featured'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'credits' => self::clean($data['credits'] ?? null),
            'landing_path' => $data['landing_path'] ?? null,
            'tiktok_url' => $data['tiktok_url'] ?? null,
            'youtube_url' => $data['youtube_url'] ?? null,
            'status' => 'off', // until the file is in place
            'source' => $source,
            'uploaded_by' => $userId,
        ]);
        try {
            File::ensureDirectoryExists($video->directory());
            $bytes = (int) $file->getSize();
            $file->move($video->directory(), 'video.mp4');
            $posterName = null;
            if ($poster) {
                $posterName = 'poster.'.(strtolower($poster->getClientOriginalExtension()) === 'png' ? 'png' : 'jpg');
                $poster->move($video->directory(), $posterName);
            } elseif ($this->framePoster($video->publicVideoPath(), $video->directory().'/poster.jpg', $seconds)) {
                $posterName = 'poster.jpg';
            }
            $video->update(['video_path' => "generated/videos/{$video->id}/video.mp4", 'poster_path' => $posterName,
                'video_bytes' => $bytes ?: @filesize($video->publicVideoPath()) ?: null,
                'status' => $status, 'queued_at' => $status === 'queued' ? now() : null]);
        } catch (Throwable $e) {
            File::deleteDirectory($video->directory());
            $video->delete();
            throw $e;
        }

        return $video->fresh();
    }

    /** Changes the status; putting a video on the queue stamps queued_at (it then sorts as new). */
    public function setStatus(SocialVideo $video, string $status): void
    {
        $video->update(['status' => $status, 'queued_at' => $status === 'queued' ? ($video->status === 'queued' ? $video->queued_at : now()) : $video->queued_at]);
    }

    /** A still from about a tenth of the way in, if ffmpeg runs here. */
    private function framePoster(string $video, string $poster, float $seconds): bool
    {
        try {
            $at = number_format(min(5.0, max(0.0, $seconds / 10)), 1, '.', '');
            $process = new Process([app(SlideshowVideo::class)->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y', '-ss', $at, '-i', $video,
                '-frames:v', '1', '-vf', 'scale=540:-2', '-q:v', '4', $poster]);
            $process->setTimeout(30)->run();

            return $process->isSuccessful() && is_file($poster) && filesize($poster) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    // ------------------------------------------------------------------ the social queue

    /** The landing page tagged per channel: utm_campaign is the video's campaign (default documentary). */
    public static function landingUrls(SocialVideo $video): array
    {
        $landing = SocialQueue::https($video->landing_path ?: $video->pageUrl());

        return collect(SocialLink::CHANNELS)->mapWithKeys(fn ($c) => [$c => SocialLink::to($landing, $c, $video->campaign ?: 'documentary', "{$video->type}-{$video->id}")])->all();
    }

    /** Captions per platform: the editor's own per channel, else the main caption (else the title); X fits 280. */
    public static function captions(SocialVideo $video): array
    {
        $links = self::landingUrls($video);
        $per = (array) $video->captions;
        $base = trim((string) ($video->caption ?: $video->title));
        $credits = $video->credits ? "\n\nCredits: ".$video->credits : '';
        $long = fn (string $c) => SocialQueue::plain(trim(($per[$c] ?? $base)."\n\nWatch on Matchday Africa: ".$links[$c].$credits));

        return [
            'x' => SocialQueue::plain(XText::compose((string) ($per['x'] ?? $base), $links['x'])),
            'instagram' => $long('instagram'),
            'tiktok' => $long('tiktok'),
            'youtube' => $long('youtube'),
        ];
    }

    /** Queued long videos from the last $since, for SocialQueue. */
    public function queueItems(\Carbon\Carbon $since): Collection
    {
        if (!SocialVideo::ready()) {
            return collect();
        }

        return SocialVideo::where('status', 'queued')->whereNotNull('video_path')
            ->where('queued_at', '>=', $since->copy()->setTimezone(config('app.timezone')))
            ->get()->filter(fn (SocialVideo $v) => $v->hasVideo())
            ->map(function (SocialVideo $v) {
                $video = SocialQueue::https($v->videoUrl());
                $captions = self::captions($v);
                $landing = SocialQueue::https($v->landing_path ?: $v->pageUrl());

                return [
                    'id' => "video:{$v->id}",
                    'type' => 'video',
                    'video_type' => $v->type,
                    'match' => null,
                    'title' => $v->title,
                    'reel_url' => $video,
                    'video_url' => $video,
                    'reel_duration_seconds' => $v->duration_seconds !== null ? round($v->duration_seconds, 1) : SocialQueue::duration($v->publicVideoPath()),
                    'youtube_url' => $v->youtube_url,
                    'tiktok_url' => $v->tiktok_url,
                    'image_url' => $v->posterUrl() ? SocialQueue::https($v->posterUrl()) : null,
                    'caption' => $captions['instagram'],
                    'captions' => $captions,
                    'page_url' => SocialQueue::https($v->pageUrl()),
                    'landing_url' => $landing,
                    'landing_urls' => self::landingUrls($v),
                    'campaign' => $v->campaign,
                    'credits' => $v->credits,
                    'created_at' => $v->queued_at ?? $v->created_at,
                    '_video' => $v,
                ];
            })->values();
    }

    // ------------------------------------------------------------------ the featured row

    /**
     * Cards for "As seen on TikTok": ['key', 'kind', 'label', 'title', 'page', 'poster', 'length'].
     * Empty when nothing qualifies (the row is then left out).
     */
    public function row(int $limit = self::ROW_LIMIT): Collection
    {
        $cards = collect();
        if (SocialVideo::ready()) {
            $cards = SocialVideo::visible()->where('featured', true)->orderBy('sort_order')->latest('id')->limit($limit)->get()
                ->filter(fn (SocialVideo $v) => $v->hasVideo())
                ->map(fn (SocialVideo $v) => ['key' => "video:{$v->id}", 'kind' => 'video', 'label' => $v->typeLabel(), 'title' => $v->title,
                    'page' => $v->pageUrl(), 'poster' => $v->posterUrl(), 'length' => $v->lengthLabel()]);
        }
        $pinned = FeaturedItem::ready() ? FeaturedItem::orderBy('sort_order')->latest('id')->pluck('subject') : collect();
        $shows = $this->slideshows($pinned);
        $radio = $this->radio($pinned);
        foreach ($pinned as $subject) {
            $card = $shows->get($subject) ?? $radio->get($subject);
            if ($card) {
                $cards->push($card);
            }
        }
        // Fill the room left with the newest of the rest, slideshows and radio together.
        $rest = $shows->concat($radio)->reject(fn ($c) => $pinned->contains($c['key']))->sortByDesc('at');
        $cards = $cards->concat($rest)->unique('key')->take($limit)->values();

        return $cards->map(fn ($c) => array_diff_key($c, ['at' => 1]));
    }

    /** Published slideshows with a video (newest 12), plus any pinned ones, keyed "slideshow:<id>". */
    private function slideshows(Collection $pinned): Collection
    {
        if (!Slideshow::ready()) {
            return collect();
        }
        $ids = $pinned->filter(fn ($s) => str_starts_with($s, 'slideshow:'))->map(fn ($s) => (int) substr($s, 10));
        $rows = Slideshow::published()->latest('approved_at')->limit(12)->get()
            ->concat($ids->isNotEmpty() ? Slideshow::published()->whereIn('id', $ids)->get() : collect())->unique('id');

        return $rows->filter(fn (Slideshow $s) => $s->videoUrl())->mapWithKeys(fn (Slideshow $s) => ["slideshow:{$s->id}" => [
            'key' => "slideshow:{$s->id}", 'kind' => 'slideshow', 'label' => 'Slideshow · '.$s->label(),
            'title' => (string) data_get($s->facts, 'title', $s->title()), 'page' => $s->pageUrl(), 'poster' => $s->posterUrl(),
            'length' => $s->duration_seconds ? (int) round($s->duration_seconds).' s' : null, 'at' => $s->approved_at?->timestamp ?? 0,
        ]]);
    }

    /** Published radio editions with a ready vertical video (newest 6), plus any pinned ones, keyed "radio:<id>". */
    private function radio(Collection $pinned): Collection
    {
        if (!RadioVideo::columnsReady()) {
            return collect();
        }
        $ids = $pinned->filter(fn ($s) => str_starts_with($s, 'radio:'))->map(fn ($s) => (int) substr($s, 6));
        $query = fn () => RadioBulletin::published()->where('video_status', 'ready');
        $rows = $query()->latest('published_at')->limit(6)->get()
            ->concat($ids->isNotEmpty() ? $query()->whereIn('id', $ids)->get() : collect())->unique('id');

        return $rows->filter(fn (RadioBulletin $b) => $b->videoReady())->mapWithKeys(fn (RadioBulletin $b) => ["radio:{$b->id}" => [
            'key' => "radio:{$b->id}", 'kind' => 'radio', 'label' => 'Matchday Radio · '.$b->editionLabel(),
            'title' => (string) $b->title, 'page' => route('radio.show', $b), 'poster' => $b->posterUrl(),
            'length' => $b->video_seconds ? (int) round((float) $b->video_seconds).' s' : null, 'at' => $b->published_at?->timestamp ?? 0,
        ]]);
    }
}
