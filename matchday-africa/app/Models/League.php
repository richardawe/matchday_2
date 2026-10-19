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
        'metadata',
        'espn_slug'
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

    /**
     * Position in config/competitions.php (lower is more important); unlisted leagues sort last.
     */
    public function getCompetitionRankAttribute(): int
    {
        $keys = array_keys(config('competitions.order', []));
        foreach (array_filter([$this->espn_slug, $this->short_code]) as $key) {
            $rank = array_search($key, $keys, true);
            if ($rank !== false) {
                return $rank;
            }
        }

        return count($keys) + ($this->is_featured ? 0 : 1);
    }

    /**
     * africa, europe, international or other, for grouping and filtering.
     */
    public function getRegionAttribute(): string
    {
        $order = config('competitions.order', []);

        return $order[$this->espn_slug] ?? $order[$this->short_code] ?? 'other';
    }
}
