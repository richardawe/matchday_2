<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchMoment extends Model
{
    public const TYPES = ['kickoff', 'goal', 'red_card', 'half_time', 'full_time'];

    protected $fillable = ['match_id', 'key', 'type', 'side', 'minute', 'extra_minute', 'player_name', 'assist_name', 'home_score', 'away_score', 'angle', 'match_event_id'];

    protected $casts = ['minute' => 'integer', 'extra_minute' => 'integer', 'home_score' => 'integer', 'away_score' => 'integer'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    /** "45+2′", "63′", or null for kick-off. */
    public function getClockAttribute(): ?string
    {
        if ($this->minute === null) {
            return null;
        }

        return $this->minute.($this->extra_minute ? '+'.$this->extra_minute : '').'′';
    }

    public function cardUrl(string $voice = 'en', string $format = 'feed'): string
    {
        return route('moments.card', ['moment' => $this->id, 'voice' => $voice, 'format' => $format, 'v' => $this->updated_at?->timestamp]);
    }
}
