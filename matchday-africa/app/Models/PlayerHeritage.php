<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's African heritage (parentage or FIFA eligibility) with a public source. Only a
 * confirmed row brings a player into scope or allows "of {country} descent" wording; pending
 * and rejected rows never reach public pages, captions or slideshows. Never inferred from a
 * name or appearance: an editor confirms the country and the source.
 */
class PlayerHeritage extends Model
{
    public const STATUSES = ['pending', 'confirmed', 'rejected'];

    protected $fillable = ['player_id', 'country', 'country_code', 'source_url', 'source_note', 'status', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed')->whereNotNull('source_url')->where('source_url', '!=', '');
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed' && trim((string) $this->source_url) !== '';
    }
}
