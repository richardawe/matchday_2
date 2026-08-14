<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class League extends Model
{
    protected $fillable = [
        'football_data_id',
        'name',
        'short_code',
        'type',
        'country_name',
        'country_code',
        'logo_url',
        'is_active',
        'is_featured',
        'priority',
        'metadata'
    ];

    protected $casts = [
        'football_data_id' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'priority' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Get all matches for this league
     */
    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class, 'league_id');
    }

    /**
     * Get all teams for this league
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'league_id');
    }

    /**
     * Get all standings for this league
     */
    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class, 'league_id');
    }

    /**
     * Get current season standings
     */
    public function currentStandings(): HasMany
    {
        return $this->standings()->where('is_current', true)->orderBy('position');
    }

    /**
     * Get user favorites for this league
     */
    public function favorites(): MorphMany
    {
        return $this->morphMany(UserFavorite::class, 'favoritable');
    }

    /**
     * Get the logo URL with fallback
     */
    public function getLogoAttribute(): ?string
    {
        return $this->logo_url ?: asset('images/default-league-logo.png');
    }

    /**
     * Scope for active leagues
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for featured leagues
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }
}
