<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A long video posted to TikTok that is not a reel, slideshow or radio edition: a mini-documentary,
 * a compilation or something else. Served as a static file at public/generated/videos/{id}/video.mp4
 * (byte ranges) with its own page at /reels/videos/{id}.
 *
 * status: approved (public page, not on the social queue), queued (public page and on the social
 * queue as "video:<id>"), off (taken down: no page, no row, no queue item).
 *
 * idle_since: when it went off, or was left neither featured nor queued (stamped on save, cleared when it
 * is featured or queued again). videos:prune-files deletes the mp4 after 30 idle days and stamps
 * video_removed_at; the row, poster and page stay (the page then shows the poster and the TikTok/YouTube link).
 */
class SocialVideo extends Model
{
    public const TYPES = ['documentary', 'compilation', 'other'];

    public const STATUSES = ['approved', 'queued', 'off'];

    protected $fillable = [
        'title', 'caption', 'captions', 'description', 'duration_seconds', 'type', 'campaign', 'featured', 'sort_order',
        'credits', 'landing_path', 'video_path', 'poster_path', 'video_bytes', 'tiktok_url', 'youtube_url', 'status',
        'source', 'uploaded_by', 'queued_at', 'idle_since', 'video_removed_at',
    ];

    protected $casts = [
        'captions' => 'array', 'featured' => 'boolean', 'sort_order' => 'integer', 'duration_seconds' => 'float',
        'video_bytes' => 'integer', 'queued_at' => 'datetime', 'idle_since' => 'datetime', 'video_removed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (SocialVideo $video) {
            if (!self::tracksIdle()) {
                return;
            }
            if ($video->isIdle()) {
                $video->idle_since ??= now();
            } else {
                $video->idle_since = null;
            }
        });
    }

    private static function tracksIdle(): bool
    {
        return self::ready() && Schema::hasColumn('social_videos', 'idle_since');
    }

    /** Taken down, or neither featured nor queued: its mp4 may be deleted after 30 days. */
    public function isIdle(): bool
    {
        return $this->status === 'off' || (!$this->featured && $this->status !== 'queued');
    }

    public function scopeIdle(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('status', 'off')->orWhere(fn ($q) => $q->where('featured', false)->where('status', '!=', 'queued')));
    }

    /** The mp4 was deleted by videos:prune-files. */
    public function videoRemoved(): bool
    {
        return $this->video_removed_at !== null && !$this->hasVideo();
    }

    /** Where to watch once the file is gone: TikTok first, then YouTube. */
    public function externalUrl(): ?string
    {
        return $this->tiktok_url ?: ($this->youtube_url ?: null);
    }

    public static function ready(): bool
    {
        return Schema::hasTable('social_videos');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Approved or queued: shown on its page and, when featured, on /reels. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', ['approved', 'queued'])->whereNotNull('video_path');
    }

    public function directory(): string
    {
        return public_path("generated/videos/{$this->id}");
    }

    public function publicVideoPath(): string
    {
        return $this->directory().'/video.mp4';
    }

    public function publicPosterPath(): ?string
    {
        return $this->poster_path ? $this->directory().'/'.basename($this->poster_path) : null;
    }

    public function hasVideo(): bool
    {
        return $this->video_path !== null && is_file($this->publicVideoPath());
    }

    public function isPublic(): bool
    {
        return in_array($this->status, ['approved', 'queued'], true) && ($this->hasVideo() || $this->videoRemoved());
    }

    public function videoUrl(): ?string
    {
        return $this->hasVideo() ? asset("generated/videos/{$this->id}/video.mp4") : null;
    }

    public function posterUrl(): ?string
    {
        $file = $this->publicPosterPath();

        return $file && is_file($file) ? asset("generated/videos/{$this->id}/".basename($file)) : null;
    }

    public function pageUrl(): string
    {
        return route('reels.video', $this);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'documentary' => 'Mini-documentary',
            'compilation' => 'Compilation',
            default => 'Video',
        };
    }

    /** "4 min 05 s" or "45 s". */
    public function lengthLabel(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }
        $s = (int) round($this->duration_seconds);

        return $s >= 60 ? intdiv($s, 60).' min '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).' s' : $s.' s';
    }
}
