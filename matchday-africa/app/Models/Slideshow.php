<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A colour slideshow video (1080×1920, about 16 s, music): a pre-match, post-match, Team of the
 * Week, milestone or national-team deck built from the site's own data, featuring African players
 * and players of confirmed African descent. Work files live in storage/app/slideshows/{id}/ and
 * the published mp4 and poster in public/generated/slideshows/{id}/.
 */
class Slideshow extends Model
{
    public const TYPES = ['preview', 'post_match', 'totw', 'milestone', 'national'];

    public const TYPE_LABELS = [
        'preview' => 'Match preview', 'post_match' => 'Full time', 'totw' => 'Team of the Week',
        'milestone' => 'Milestone', 'national' => 'National team',
    ];

    protected $fillable = [
        'key', 'match_id', 'player_id', 'type', 'status', 'priority', 'facts', 'slides', 'caption', 'captions',
        'landing_path', 'photo', 'music', 'video_path', 'poster_path', 'duration_seconds', 'attempts', 'error',
        'rendered_at', 'approved', 'approved_at', 'approved_by',
        'youtube_video_id', 'youtube_status', 'youtube_attempts', 'youtube_next_at', 'youtube_error',
        'youtube_uploaded_at', 'youtube_upload_session',
    ];

    protected $casts = [
        'facts' => 'array', 'slides' => 'array', 'captions' => 'array', 'photo' => 'array', 'music' => 'array',
        'youtube_upload_session' => 'array', 'approved' => 'boolean', 'duration_seconds' => 'float',
        'rendered_at' => 'datetime', 'approved_at' => 'datetime', 'youtube_next_at' => 'datetime', 'youtube_uploaded_at' => 'datetime',
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('slideshows');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Rendered, file present and approved by an editor: what public pages, the API and YouTube may use. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'ready')->where('approved', true)->whereNotNull('video_path');
    }

    /** Approved slideshows about a match, newest first (empty before the migration). */
    public static function forMatch(int $matchId, int $limit = 6): \Illuminate\Support\Collection
    {
        return self::ready() ? self::published()->where('match_id', $matchId)->latest('approved_at')->limit($limit)->get() : collect();
    }

    /** Approved slideshows featuring a player, newest first. */
    public static function forPlayer(int $playerId, int $limit = 6): \Illuminate\Support\Collection
    {
        return self::ready() ? self::published()->where('player_id', $playerId)->latest('approved_at')->limit($limit)->get() : collect();
    }

    public function label(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function title(): string
    {
        return (string) (data_get($this->facts, 'title') ?? $this->label());
    }

    public function workDir(): string
    {
        return storage_path("app/slideshows/{$this->id}");
    }

    public function videoFile(): ?string
    {
        $file = $this->video_path ? storage_path('app/'.$this->video_path) : null;

        return $file && is_file($file) ? $file : null;
    }

    public function publicVideoPath(): string
    {
        return public_path("generated/slideshows/{$this->id}/slideshow.mp4");
    }

    public function publicPosterPath(): string
    {
        return public_path("generated/slideshows/{$this->id}/poster.jpg");
    }

    /** The static mp4 (byte ranges for iPhones and Buffer), without a cache-busting query. */
    public function videoUrl(): ?string
    {
        return is_file($this->publicVideoPath()) ? asset("generated/slideshows/{$this->id}/slideshow.mp4") : null;
    }

    public function posterUrl(): ?string
    {
        return is_file($this->publicPosterPath()) ? asset("generated/slideshows/{$this->id}/poster.jpg") : null;
    }

    public function pageUrl(): string
    {
        return route('slideshows.show', $this);
    }
}
