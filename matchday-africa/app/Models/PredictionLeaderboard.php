<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionLeaderboard extends Model
{
    protected $fillable = [
        'prediction_set_id',
        'user_id',
        'total_points',
        'correct_predictions',
        'total_predictions',
        'accuracy_percentage',
        'rank',
        'period'
    ];

    protected $casts = [
        'total_points' => 'integer',
        'correct_predictions' => 'integer',
        'total_predictions' => 'integer',
        'accuracy_percentage' => 'decimal:2',
        'rank' => 'integer',
    ];

    /**
     * Get the prediction set this leaderboard entry belongs to
     */
    public function predictionSet(): BelongsTo
    {
        return $this->belongsTo(PredictionSet::class);
    }

    /**
     * Get the user this leaderboard entry belongs to
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate accuracy percentage
     */
    public function calculateAccuracyPercentage(): float
    {
        if ($this->total_predictions === 0) {
            return 0.0;
        }

        return round(($this->correct_predictions / $this->total_predictions) * 100, 2);
    }

    /**
     * Update leaderboard entry
     */
    public function updateStats(int $totalPoints, int $correctPredictions, int $totalPredictions): void
    {
        $this->update([
            'total_points' => $totalPoints,
            'correct_predictions' => $correctPredictions,
            'total_predictions' => $totalPredictions,
            'accuracy_percentage' => $this->calculateAccuracyPercentage(),
        ]);
    }
}
