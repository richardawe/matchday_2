<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Standing extends Model
{
    protected $fillable = [
        "team_id",
        "league_id", 
        "team_football_data_id",
        "league_football_data_id",
        "position",
        "points",
        "wins",
        "draws",
        "losses",
        "goals_for",
        "goals_against",
        "goal_difference",
        "matches_played",
        "recent_form",
        "is_current",
        "season",
        "season_year",
        "home_wins",
        "home_draws", 
        "home_losses",
        "home_goals_for",
        "home_goals_against",
        "away_wins",
        "away_draws",
        "away_losses", 
        "away_goals_for",
        "away_goals_against",
        "clean_sheets",
        "failed_to_score",
        "average_goals_for",
        "average_goals_against",
        "qualification_zone",
        "zone_color",
        "recent_form_points",
        "calculation_date",
        "last_api_update",
        "metadata"
    ];

    protected $casts = [
        "position" => "integer",
        "points" => "integer",
        "wins" => "integer",
        "draws" => "integer",
        "losses" => "integer",
        "goals_for" => "integer",
        "goals_against" => "integer",
        "goal_difference" => "integer",
        "matches_played" => "integer",
        "is_current" => "boolean",
        "home_wins" => "integer",
        "home_draws" => "integer",
        "home_losses" => "integer",
        "home_goals_for" => "integer",
        "home_goals_against" => "integer",
        "away_wins" => "integer", 
        "away_draws" => "integer",
        "away_losses" => "integer",
        "away_goals_for" => "integer",
        "away_goals_against" => "integer",
        "clean_sheets" => "integer",
        "failed_to_score" => "integer",
        "average_goals_for" => "float",
        "average_goals_against" => "float",
        "recent_form_points" => "integer",
        "calculation_date" => "datetime",
        "last_api_update" => "datetime",
        "metadata" => "json"
    ];

    /**
     * Get the team for this standing
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the league for this standing
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * Scope for current season standings
     */
    public function scopeCurrent($query)
    {
        return $query->where("is_current", true);
    }

    /**
     * Get formatted form display
     */
    public function getFormDisplayAttribute(): string
    {
        if (!$this->recent_form) return "";
        
        return collect(str_split($this->recent_form))->map(function ($char) {
            return match($char) {
                "W" => "<span class=\"inline-block w-4 h-4 bg-green-500 text-white text-xs rounded text-center\">W</span>",
                "D" => "<span class=\"inline-block w-4 h-4 bg-yellow-500 text-white text-xs rounded text-center\">D</span>",
                "L" => "<span class=\"inline-block w-4 h-4 bg-red-500 text-white text-xs rounded text-center\">L</span>",
                default => $char
            };
        })->join(" ");
    }
    
    /**
     * Alias for matches_played to maintain compatibility
     */
    public function getPlayedGamesAttribute()
    {
        return $this->matches_played;
    }
    
    /**
     * Alias for recent_form to maintain compatibility  
     */
    public function getFormAttribute()
    {
        return $this->recent_form;
    }
}
