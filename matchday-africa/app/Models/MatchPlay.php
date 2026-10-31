<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One play of a match in the standard Griot shape (config/griot.php lists the types). */
class MatchPlay extends Model
{
    protected $fillable = ['match_id', 'provider', 'provider_key', 'seq', 'period', 'minute', 'extra_minute', 'type', 'side',
        'player', 'player2', 'x', 'y', 'detail', 'text', 'happened_at'];

    protected $casts = ['seq' => 'integer', 'period' => 'integer', 'minute' => 'integer', 'extra_minute' => 'integer',
        'x' => 'float', 'y' => 'float', 'detail' => 'array', 'happened_at' => 'datetime'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    /** Match time in minutes for ordering and windows: 45+2 → 47 in the first half, 90+3 → 93. */
    public function time(): float
    {
        return $this->minute + (float) ($this->extra_minute ?? 0);
    }
}
