<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SocialShare extends Model
{
    protected $fillable = [
        'user_id',
        'shareable_type',
        'shareable_id',
        'platform',
        'share_url',
        'shared_at',
    ];

    protected $casts = [
        'shared_at' => 'datetime',
    ];

    /**
     * Get the user that made the share.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the shareable model (match, blog, prediction, etc.).
     */
    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the platform name in a human-readable format.
     */
    public function getPlatformNameAttribute(): string
    {
        return ucfirst($this->platform);
    }

    /**
     * Scope for a specific platform.
     */
    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope for a specific shareable type.
     */
    public function scopeShareableType($query, string $type)
    {
        return $query->where('shareable_type', $type);
    }
}