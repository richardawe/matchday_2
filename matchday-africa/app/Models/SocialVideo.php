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
 */
class SocialVideo extends Model
{
    public const TYPES = ['documentary', 'compilation', 'other'];

    public const STATUSES = ['approved', 'queued', 'off'];

    protected $fillable = [
        'title', 'caption', 'captions', 'description', 'duration_seconds', 'type', 'campaign', 'featured', 'sort_order',
        'credits', 'landing_path', 'video_path', 'poster_path', 'video_bytes', 'tiktok_url', 'youtube_url', 'status',
        'source', 'uploaded_by', 'queued_at',
    ];

    protected $casts = [
        'captions' => 'array', 'featured' => 'boolean', 'sort_order' => 'integer', 'duration_seconds' => 'float',
        'video_bytes' => 'integer', 'queued_at' => 'datetime',
    ];

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
        return in_array($this->status, ['approved', 'queued'], true) && $this->hasVideo();
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
