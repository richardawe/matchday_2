<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPrediction extends Model
{
    protected $fillable = [
        'user_id',
        'prediction_set_id',
        'match_id',
        'prediction_type',
        'prediction_value',
        'points_earned',
        'is_correct',
        'is_scored',
        'home_score_prediction',
        'away_score_prediction',
        'goalscorer_name',
        'total_goals_prediction',
        'submitted_at'
    ];

    protected $casts = [
        'points_earned' => 'integer',
        'is_correct' => 'boolean',
        'is_scored' => 'boolean',
        'home_score_prediction' => 'integer',
        'away_score_prediction' => 'integer',
        'total_goals_prediction' => 'integer',
        'submitted_at' => 'datetime',
    ];

    /**
     * Get the user who made this prediction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the prediction set this prediction belongs to
     */
    public function predictionSet(): BelongsTo
    {
        return $this->belongsTo(PredictionSet::class);
    }

    /**
     * Get the match this prediction is for
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    /**
     * Check if prediction is correct
     */
    public function isCorrect(): bool
    {
        return $this->is_correct === true;
    }

    /**
     * Get points earned for this prediction
     */
    public function getPointsEarned(): int
    {
        return $this->points_earned;
    }
}
