<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * One breaking news item (App\Services\BreakingNewsDesk writes them). Only one shows at a time: the
 * newest item that has not ended or expired. Items expire on their own (expires_at, 48 h at most).
 */
class BreakingNews extends Model
{
    protected $table = 'breaking_news';

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime', 'ended_at' => 'datetime'];

    public const CACHE_KEY = 'breaking-news:current';

    public static function ready(): bool
    {
        try {
            return Schema::hasTable('breaking_news');
        } catch (\Throwable) {
            return false;
        }
    }

    /** The item on screen now, or null. Cached for 30 s; every write clears it. */
    public static function current(): ?self
    {
        try {
            $id = Cache::remember(self::CACHE_KEY, 30, fn () => self::ready() ? (self::live()->latest('created_at')->latest('id')->value('id') ?? 0) : 0);
            $item = $id ? self::find($id) : null;
        } catch (\Throwable) {
            return null; // the banner never breaks a page
        }

        return $item?->isLive() ? $item : null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function scopeLive($query)
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return !$this->ended_at && $this->expires_at && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return $this->ended_at ? 'ended' : ($this->expires_at->isFuture() ? 'live' : 'expired');
    }

    /** What the link opens: article, match, video, listen or source (another site). */
    public function linkKind(): ?string
    {
        if (!$this->link_url) {
            return null;
        }
        $host = parse_url($this->link_url, PHP_URL_HOST);
        $path = (string) parse_url($this->link_url, PHP_URL_PATH);
        $own = $host && $host === parse_url((string) config('app.url'), PHP_URL_HOST);
        if (preg_match('#\.(mp4|mov|webm)$#i', $path) || preg_match('#(^|\.)(youtube\.com|youtu\.be|tiktok\.com|vimeo\.com)$#i', (string) $host)
            || ($own && preg_match('#^/(reels|slideshows|generated)/#', $path))) {
            return 'video';
        }
        if (!$own) {
            return 'source';
        }

        return match (true) {
            (bool) preg_match('#^/matches/\d+#', $path) => 'match',
            (bool) preg_match('#^/(blog|blogs|articles|news)/#', $path) => 'article',
            (bool) preg_match('#^/listen#', $path) => 'listen',
            default => 'page',
        };
    }

    public function linkLabel(): ?string
    {
        return match ($this->linkKind()) {
            null => null,
            'match' => 'Match centre',
            'article' => 'Read more',
            'video' => 'Watch',
            'listen' => 'Listen',
            'source' => 'Source: '.preg_replace('/^www\./', '', (string) parse_url($this->link_url, PHP_URL_HOST)),
            default => 'Open',
        };
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'status' => $this->status(), 'headline' => $this->headline, 'body' => $this->body,
            'link_url' => $this->link_url, 'link_kind' => $this->linkKind(), 'image_url' => $this->image_url, 'image_credit' => $this->image_credit,
            'source' => $this->source, 'created_by' => $this->author?->name, 'updated_by' => $this->editor?->name, 'ended_by' => $this->ender?->name,
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(), 'expires_at' => $this->expires_at?->utc()->toIso8601ZuluString(),
            'ended_at' => $this->ended_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
