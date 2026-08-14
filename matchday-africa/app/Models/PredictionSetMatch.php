<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PredictionSetMatch extends Model
{
    protected $fillable = [
        'prediction_set_id',
        'match_id',
        'prediction_type',
        'points_value'
    ];

    protected $casts = [
        'points_value' => 'integer',
    ];

    /**
     * Get the prediction set this match belongs to
     */
    public function predictionSet(): BelongsTo
    {
        return $this->belongsTo(PredictionSet::class);
    }

    /**
     * Get the match
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    /**
     * Get user predictions for this match
     */
    public function userPredictions(): HasMany
    {
        return $this->hasMany(UserPrediction::class, 'match_id', 'match_id');
    }
}
