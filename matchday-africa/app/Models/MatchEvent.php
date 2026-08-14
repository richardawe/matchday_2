<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchEvent extends Model
{
    protected $fillable = [
        'football_data_id',
        'match_id',
        'match_football_data_id',
        'team_id',
        'team_football_data_id',
        'type',
        'sub_type',
        'minute',
        'extra_minute',
        'period',
        'player_name',
        'player_sportmonks_id',
        'assist_player_name',
        'assist_player_sportmonks_id',
        'related_player_name',
        'related_player_sportmonks_id',
        'reason',
        'description',
        'is_own_goal',
        'is_penalty',
        'x_coordinate',
        'y_coordinate',
        'sort_order',
        'metadata'
    ];

    protected $casts = [
        'football_data_id' => 'integer',
        'match_id' => 'integer',
        'match_football_data_id' => 'integer',
        'team_id' => 'integer',
        'team_football_data_id' => 'integer',
        'minute' => 'integer',
        'extra_minute' => 'integer',
        'player_sportmonks_id' => 'integer',
        'assist_player_sportmonks_id' => 'integer',
        'related_player_sportmonks_id' => 'integer',
        'is_own_goal' => 'boolean',
        'is_penalty' => 'boolean',
        'x_coordinate' => 'decimal:2',
        'y_coordinate' => 'decimal:2',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeGoals($query)
    {
        return $query->where('type', 'goal');
    }

    public function scopeCards($query)
    {
        return $query->whereIn('type', ['yellow_card', 'red_card']);
    }
}
