<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PredictionSet extends Model
{
    protected $fillable = [
        'name',
        'description',
        'admin_id',
        'status',
        'prediction_deadline',
        'metadata'
    ];

    protected $casts = [
        'prediction_deadline' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the admin who created this prediction set
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Get all matches in this prediction set
     */
    public function matches(): HasMany
    {
        return $this->hasMany(PredictionSetMatch::class);
    }

    /**
     * Get all user predictions for this set
     */
    public function userPredictions(): HasMany
    {
        return $this->hasMany(UserPrediction::class);
    }

    /**
     * Get leaderboard entries for this prediction set
     */
    public function leaderboards(): HasMany
    {
        return $this->hasMany(PredictionLeaderboard::class);
    }

    /**
     * Check if prediction set is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if prediction deadline has passed
     */
    public function isDeadlinePassed(): bool
    {
        return $this->prediction_deadline < now();
    }

    /**
     * Get participation count
     */
    public function getParticipationCount(): int
    {
        return $this->userPredictions()
            ->distinct('user_id')
            ->count();
    }
}
