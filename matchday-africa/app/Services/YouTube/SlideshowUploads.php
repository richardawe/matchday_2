<?php

namespace App\Services\YouTube;

use App\Jobs\UploadSlideshowToYouTube;
use App\Models\Slideshow;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Approved colour slideshows as YouTube Shorts, with their own columns on slideshows (the match's
 * youtube_* columns stay the 20-second reel's). Queued on approval; youtube:uploads sends them
 * after the reels, within the shared daily quota and at most config slideshow.youtube_daily a
 * quota day. Post-match, Team of the Week and milestone decks go before previews; the rest wait
 * for the next quota day. Same resumable upload, retries and claim rules as the reels.
 */
class SlideshowUploads
{
    private const STALE_MINUTES = 30;

    /** Upload order by type: results and milestones before previews when the day is full. */
    private const TYPE_ORDER = ['post_match' => 0, 'totw' => 1, 'milestone' => 2, 'national' => 3, 'preview' => 4];

    public function __construct(private ShortsUpload $shorts) {}

    public function enabled(): bool
    {
        return config('youtube.uploads.enabled') && Slideshow::ready() && $this->shorts->account() !== null;
    }

    /** Called when an editor approves a slideshow. */
    public function approved(Slideshow $show): void
    {
        if ($this->enabled() && $this->queue($show)) {
            UploadSlideshowToYouTube::dispatch($show->id);
        }
    }

    /** Marks an approved, rendered slideshow to be uploaded. False when it is on YouTube already or not publishable. */
    public function queue(Slideshow $show): bool
    {
        $show = $show->fresh() ?? $show;
        if ($show->youtube_video_id || !$show->approved || $show->status !== 'ready' || !$show->videoFile()) {
            return false;
        }
        if ($show->youtube_status === 'uploading' && $show->youtube_next_at && $show->youtube_next_at->isFuture()) {
            return true;
        }
        $show->forceFill(['youtube_status' => 'pending', 'youtube_attempts' => 0, 'youtube_next_at' => null, 'youtube_error' => null])->save();

        return true;
    }

    public function due(): Collection
    {
        if (!Slideshow::ready()) {
            return collect();
        }

        return Slideshow::with('match.league')->published()->whereNull('youtube_video_id')
            ->whereIn('youtube_status', ['pending', 'uploading'])
            ->where(fn ($q) => $q->whereNull('youtube_next_at')->orWhere('youtube_next_at', '<=', $this->clock()))
            ->get()
            ->sortBy(fn (Slideshow $s) => [self::TYPE_ORDER[$s->type] ?? 9, -$s->priority, $s->id])->values();
    }

    /** Slideshow Shorts still allowed today: the shared quota, and this feature's own daily share. */
    public function left(): int
    {
        return min($this->shorts->left(), max(0, (int) config('slideshow.youtube_daily', 2) - (int) Cache::get($this->countKey(), 0)));
    }

    /** Uploads every due slideshow the day allows. Returns [uploaded, still waiting]. */
    public function run(): array
    {
        $uploaded = 0;
        foreach ($this->enabled() ? $this->due() : collect() as $show) {
            if ($this->left() <= 0) {
                break;
            }
            $uploaded += $this->upload($show) === 'uploaded' ? 1 : 0;
        }

        return [$uploaded, $this->due()->count()];
    }

    /** uploaded, waiting, skipped, retry or failed. */
    public function upload(Slideshow $show): string
    {
        if (!$this->enabled() || !($account = $this->shorts->account())) {
            return 'skipped';
        }
        $show = $show->fresh() ?? $show;
        if ($show->youtube_video_id || !$show->approved || $show->status !== 'ready') {
            return 'skipped';
        }
        $file = $show->videoFile();
        if (!$file) {
            $show->forceFill(['youtube_status' => 'failed', 'youtube_error' => 'The slideshow file is missing.'])->save();

            return 'failed';
        }
        $session = $show->youtube_upload_session;
        $resuming = is_array($session) && ($session['size'] ?? null) === filesize($file);
        if (!$resuming && $this->left() <= 0) {
            return 'waiting';
        }
        if (!$this->claim($show)) {
            return 'skipped';
        }

        try {
            $video = $this->shorts->send($account, $this->metadata($show), $file, $resuming ? $session['url'] : null, function (array $started) use ($show) {
                Cache::put($this->countKey(), (int) Cache::get($this->countKey(), 0) + 1, now()->addDays(2));
                $show->forceFill(['youtube_upload_session' => $started])->save();
            });
        } catch (QuotaExhausted $e) {
            $this->shorts->exhausted();
            $show->forceFill(['youtube_status' => 'pending', 'youtube_next_at' => $this->shorts->quotaReset(), 'youtube_error' => $e->getMessage()])->save();

            return 'waiting';
        } catch (Throwable $e) {
            return $this->failed($show, $e);
        }

        $show->forceFill([
            'youtube_video_id' => $video['id'], 'youtube_uploaded_at' => $this->clock(), 'youtube_status' => 'uploaded',
            'youtube_error' => $this->shorts->restriction($video), 'youtube_next_at' => null, 'youtube_upload_session' => null,
        ])->save();
        Log::info('Slideshow uploaded to YouTube', ['slideshow_id' => $show->id, 'video_id' => $video['id']]);

        return 'uploaded';
    }

    /** "Bukayo Saka | Arsenal v Chelsea #Shorts" (at most 100 characters, no angle brackets). */
    public function title(Slideshow $show): string
    {
        $tail = ' #Shorts';
        $player = $show->player?->name;
        $subject = (string) (data_get($show->facts, 'title') ?: $show->label());
        $head = $player && !str_contains($subject, $player) ? "{$player} | {$subject}" : $subject;
        if ($show->type === 'preview' || $show->type === 'national') {
            $head .= ' preview';
        }

        return str_replace(['<', '>'], '', Str::limit($head, 100 - mb_strlen($tail), '…').$tail);
    }

    /** The YouTube caption: summary, the landing page tagged for YouTube, the music and photo credits, two hashtags. */
    public function description(Slideshow $show): string
    {
        return (string) (data_get($show->captions, 'youtube') ?: $show->caption);
    }

    public function metadata(Slideshow $show): array
    {
        $tags = array_values(array_filter(['Matchday Africa', $show->player?->name, data_get($show->facts, 'match.home'),
            data_get($show->facts, 'match.away'), data_get($show->facts, 'match.competition'), 'football', 'Shorts']));

        return [
            'snippet' => [
                'title' => $this->title($show),
                'description' => $this->description($show),
                'categoryId' => (string) config('youtube.uploads.category'),
                'tags' => $tags,
                'defaultLanguage' => 'en',
            ],
            'status' => [
                'privacyStatus' => config('youtube.uploads.privacy'),
                'selfDeclaredMadeForKids' => false,
                'embeddable' => true,
            ],
        ];
    }

    private function claim(Slideshow $show): bool
    {
        $claimed = Slideshow::whereKey($show->id)->whereNull('youtube_video_id')
            ->where(fn ($q) => $q->whereNull('youtube_status')->orWhereIn('youtube_status', ['pending', 'failed'])
                ->orWhere(fn ($q) => $q->where('youtube_status', 'uploading')->where('youtube_next_at', '<=', $this->clock())))
            ->update(['youtube_status' => 'uploading', 'youtube_next_at' => $this->clock()->addMinutes(self::STALE_MINUTES)]);
        if ($claimed) {
            $show->refresh();
        }

        return $claimed === 1;
    }

    private function failed(Slideshow $show, Throwable $e): string
    {
        $attempts = (int) $show->youtube_attempts + 1;
        $waits = (array) config('youtube.uploads.retry_minutes');
        $final = $attempts > count($waits);
        $show->forceFill([
            'youtube_status' => $final ? 'failed' : 'pending',
            'youtube_attempts' => $attempts,
            'youtube_next_at' => $final ? null : $this->clock()->addMinutes((int) $waits[$attempts - 1]),
            'youtube_error' => Str::limit($e->getMessage(), 1000),
        ])->save();
        Log::warning('Slideshow YouTube upload failed', ['slideshow_id' => $show->id, 'attempt' => $attempts, 'final' => $final, 'error' => $e->getMessage()]);

        return $final ? 'failed' : 'retry';
    }

    private function countKey(): string
    {
        return 'youtube:slideshow-uploads:'.$this->shorts->quotaDay();
    }

    private function clock(): Carbon
    {
        return now(config('app.timezone'));
    }
}
