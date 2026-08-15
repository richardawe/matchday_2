<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class FootballMatch extends Model
{
    public const LIVE_STATUSES = ['LIVE', 'IN_PLAY', 'PAUSED', '1H', '2H', 'HT', 'ET', 'PENALTY_SHOOTOUT'];

    protected $table = 'matches';

    protected $fillable = [
        'football_data_id',
        'league_id',
        'league_football_data_id',
        'home_team_id',
        'home_team_football_data_id',
        'away_team_id',
        'away_team_football_data_id',
        'match_date',
        'status',
        'minute',
        'period',
        'home_score',
        'away_score',
        'home_score_ht',
        'away_score_ht',
        'home_possession','away_possession','home_shots','away_shots',
        'home_shots_on_target','away_shots_on_target','home_corners','away_corners',
        'home_fouls','away_fouls','home_yellow_cards','away_yellow_cards',
        'home_red_cards','away_red_cards',
        'venue_name',
        'venue_city',
        'referee_name',
        'attendance',
        'weather',
        'temperature',
        'is_featured',
        'has_live_coverage',
        'has_preview',
        'last_api_update',
        'metadata',
        'is_prediction_eligible',
        'prediction_deadline',
        'prediction_types_enabled',
        'scored_at'
    ];

    protected $casts = [
        'football_data_id' => 'integer',
        'league_id' => 'integer',
        'league_football_data_id' => 'integer',
        'home_team_id' => 'integer',
        'home_team_football_data_id' => 'integer',
        'away_team_id' => 'integer',
        'away_team_football_data_id' => 'integer',
        'match_date' => 'datetime',
        'minute' => 'integer',
        'home_score' => 'integer',
        'away_score' => 'integer',
        'home_score_ht' => 'integer',
        'away_score_ht' => 'integer',
        'home_possession' => 'integer','away_possession' => 'integer',
        'home_shots' => 'integer','away_shots' => 'integer',
        'home_shots_on_target' => 'integer','away_shots_on_target' => 'integer',
        'home_corners' => 'integer','away_corners' => 'integer',
        'home_fouls' => 'integer','away_fouls' => 'integer',
        'home_yellow_cards' => 'integer','away_yellow_cards' => 'integer',
        'home_red_cards' => 'integer','away_red_cards' => 'integer',
        'attendance' => 'integer',
        'temperature' => 'decimal:1',
        'is_featured' => 'boolean',
        'has_live_coverage' => 'boolean',
        'last_api_update' => 'datetime',
        'scored_at' => 'datetime',
        'metadata' => 'array',
        'is_prediction_eligible' => 'boolean',
        'prediction_deadline' => 'datetime',
        'prediction_types_enabled' => 'array',
    ];

    /**
     * Only advertise a match as live when both its timing and API update are credible.
     */
    public function scopeCrediblyLive(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query
            ->whereIn('status', self::LIVE_STATUSES)
            ->whereBetween('match_date', [$at->copy()->subHours(4), $at->copy()->addMinutes(30)])
            ->whereNotNull('last_api_update')
            ->where('last_api_update', '>=', $at->copy()->subMinutes(30));
    }

    /**
     * Get the league this match belongs to
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class, 'league_id');
    }

    /**
     * Get the home team
     */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /**
     * Get the away team
     */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /**
     * Get match events
     */
    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id');
    }
    public function story(){return $this->hasOne(MatchStory::class,'match_id');}

    /**
     * Get match chats
     */
    public function chats(): HasMany
    {
        return $this->hasMany(MatchChat::class, 'match_id');
    }

    /**
     * Get recent chats for this match
     */
    public function recentChats()
    {
        return $this->chats()
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(20);
    }

    /**
     * Scope for today's matches
     */
    public function scopeToday($query)
    {
        return $query->whereDate('match_date', now());
    }

    /**
     * Scope for live matches
     */
    public function scopeLive($query)
    {
        return $query->whereIn('status', ['LIVE', '1H', '2H', 'HT']);
    }

    /**
     * Scope for finished matches
     */
    public function scopeFinished($query)
    {
        return $query->where('status', 'FINISHED');
    }

    /**
     * Scope for upcoming matches
     */
    public function scopeUpcoming($query)
    {
        return $query->where('match_date', '>', now());
    }

    /**
     * Get formatted score
     */
    public function getScoreAttribute(): string
    {
        if ($this->home_score !== null && $this->away_score !== null) {
            return $this->home_score . ' - ' . $this->away_score;
        }
        return 'vs';
    }

    /**
     * Get match status display
     */
    public function getStatusDisplayAttribute(): string
    {
        return match($this->status) {
            'SCHEDULED', 'TIMED' => 'Scheduled',
            'LIVE', '1H', '2H' => 'Live',
            'HT' => 'Half Time',
            'FT', 'FINISHED' => 'Finished',
            'POSTPONED' => 'Postponed',
            'CANCELLED' => 'Cancelled',
            default => $this->status
        };
    }

    /**
     * Get the match preview
     */
    public function preview()
    {
        return $this->hasOne(MatchPreview::class, 'match_id');
    }

    /**
     * Check if match has a preview
     */
    public function hasPreview()
    {
        return $this->has_preview && $this->preview;
    }

    /**
     * Get prediction set matches for this match
     */
    public function predictionSetMatches()
    {
        return $this->hasMany(PredictionSetMatch::class, 'match_id');
    }

    /**
     * Get user predictions for this match
     */
    public function userPredictions()
    {
        return $this->hasMany(UserPrediction::class, 'match_id');
    }

    /**
     * Check if match is eligible for predictions
     */
    public function isPredictionEligible(): bool
    {
        // If is_prediction_eligible is explicitly set to false, respect that
        if ($this->is_prediction_eligible === false) {
            return false;
        }
        
        // Otherwise, check if match is in the future and not finished/cancelled
        return $this->match_date > now() && 
               !in_array($this->status, ['FINISHED', 'CANCELLED', 'POSTPONED']);
    }

    /**
     * Check if prediction deadline has passed
     */
    public function isPredictionDeadlinePassed(): bool
    {
        if (!$this->prediction_deadline) {
            return false;
        }
        
        return $this->prediction_deadline < now();
    }

    /**
     * Get enabled prediction types for this match
     */
    public function getEnabledPredictionTypes(): array
    {
        return $this->prediction_types_enabled ?? ['result'];
    }

    /**
     * Check if match is finished
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['FINISHED', 'FT']) && 
               $this->home_score !== null && 
               $this->away_score !== null;
    }
}
