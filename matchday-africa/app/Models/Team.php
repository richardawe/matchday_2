<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Team extends Model
{
    protected $fillable = [
        'football_data_id',
        'name',
        'common_name',
        'short_code',
        'country_name',
        'country_code',
        'founded_year',
        'logo_url',
        'venue_name',
        'venue_city',
        'venue_capacity',
        'website_url',
        'league_id',
        'is_active',
        'metadata'
    ];

    protected $casts = [
        'football_data_id' => 'integer',
        'founded_year' => 'integer',
        'venue_capacity' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // Add display_name to appends so it's always available
    protected $appends = ['display_name'];

    /**
     * Get the league this team belongs to
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * Get home matches for this team
     */
    public function homeMatches(): HasMany
    {
        return $this->hasMany(FootballMatch::class, 'home_team_id');
    }

    /**
     * Get away matches for this team
     */
    public function awayMatches(): HasMany
    {
        return $this->hasMany(FootballMatch::class, 'away_team_id');
    }

    /**
     * Get user favorites for this team
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
        return $this->logo_url ?: asset('images/default-team-logo.png');
    }

    /**
     * Get display name (common name if available, otherwise name)
     */
    public function getDisplayNameAttribute(): string
    {
        if (!empty($this->common_name)) {
            return $this->common_name;
        }
        
        if (!empty($this->name)) {
            return $this->name;
        }
        
        return 'Unknown Team';
    }

    /**
     * Scope for active teams
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the players for this team
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * Get active players for this team
     */
    public function activePlayers(): HasMany
    {
        return $this->hasMany(Player::class)->where('is_active', true);
    }

    /**
     * Get players count
     */
    public function getPlayersCountAttribute(): int
    {
        return $this->players()->count();
    }

    /**
     * Get players by position
     */
    public function getPlayersByPosition()
    {
        return $this->activePlayers()
            ->orderBy('position')
            ->orderBy('shirt_number')
            ->get()
            ->groupBy('position');
    }
}