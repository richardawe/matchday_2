<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One post on a match's live Griot timeline. */
class GriotPost extends Model
{
    public const KINDS = ['kickoff', 'goal', 'red_card', 'var', 'woodwork', 'big_chance', 'pressure', 'injury', 'half_time', 'full_time'];

    protected $fillable = ['match_id', 'key', 'kind', 'side', 'period', 'minute', 'extra_minute', 'seq', 'home_score', 'away_score',
        'facts', 'play_keys', 'confidence', 'picker', 'written', 'audio_path'];

    protected $casts = ['period' => 'integer', 'minute' => 'integer', 'extra_minute' => 'integer', 'seq' => 'integer',
        'home_score' => 'integer', 'away_score' => 'integer', 'facts' => 'array', 'play_keys' => 'array',
        'confidence' => 'float', 'written' => 'array'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    /** "45+2′", "63′", or null for kick-off and the whistles. */
    public function getClockAttribute(): ?string
    {
        if (in_array($this->kind, ['kickoff', 'half_time', 'full_time'], true)) {
            return null;
        }

        return $this->minute.($this->extra_minute ? '+'.$this->extra_minute : '').'′';
    }

    public function audioUrl(): ?string
    {
        return $this->audio_path && is_file(public_path($this->audio_path)) ? asset($this->audio_path) : null;
    }
}
