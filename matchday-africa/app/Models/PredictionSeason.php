<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionSeason extends Model
{
    protected $fillable = [
        'name', 'started_at', 'ended_at', 'started_by', 'is_active',
        'cleared_predictions', 'cleared_leaderboard_entries', 'archived_prediction_sets',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
