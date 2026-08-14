<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'provider_token',
        'provider_refresh_token',
        'provider_token_expires_at',
    ];

    protected $casts = [
        'provider_token_expires_at' => 'datetime',
    ];

    /**
     * Get the user that owns the social account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the token is expired.
     */
    public function isTokenExpired(): bool
    {
        if (!$this->provider_token_expires_at) {
            return false;
        }

        return $this->provider_token_expires_at->isPast();
    }

    /**
     * Get the provider name in a human-readable format.
     */
    public function getProviderNameAttribute(): string
    {
        return ucfirst($this->provider);
    }
}