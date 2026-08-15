<?php

namespace App\Services;

use App\Models\UserPrediction;
use App\Models\PredictionSet;
use App\Models\PredictionLeaderboard;
use App\Models\User;
use App\Models\FootballMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PredictionService
{
    /**
     * Submit user predictions for a prediction set
     */
    public function submitPredictions(User $user, PredictionSet $predictionSet, array $predictions): array
    {
        // Validate prediction set is active and deadline hasn't passed
        if (!$predictionSet->isActive() || $predictionSet->isDeadlinePassed()) {
            return [
                'success' => false,
                'submitted_count' => 0,
                'total_predictions' => count($predictions),
                'errors' => ['This prediction round is closed.'],
                'message' => 'Prediction round is not available for submissions'
            ];
        }

        return DB::transaction(function () use ($user, $predictionSet, $predictions) {
            $submittedCount = 0;
            $errors = [];

            foreach ($predictions as $prediction) {
                try {
                    $this->submitSinglePrediction($user, $predictionSet, $prediction);
                    $submittedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to submit prediction for match {$prediction['match_id']}: " . $e->getMessage();
                    Log::error('Prediction submission failed', [
                        'user_id' => $user->id,
                        'prediction_set_id' => $predictionSet->id,
                        'match_id' => $prediction['match_id'] ?? null,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Update leaderboard
            $this->updateUserLeaderboard($user, $predictionSet);

            return [
                'success' => $submittedCount > 0,
                'submitted_count' => $submittedCount,
                'total_predictions' => count($predictions),
                'errors' => $errors,
                'message' => "Successfully submitted {$submittedCount} predictions"
            ];
        });
    }

    /**
     * Submit a single prediction
     */
    public function submitSinglePrediction(User $user, PredictionSet $predictionSet, array $prediction): UserPrediction
    {
        // Validate prediction data
        $this->validatePredictionData($prediction);

        // Check if match exists in prediction set
        $predictionSetMatch = $predictionSet->matches()
            ->where('match_id', $prediction['match_id'])
            ->where('prediction_type', $prediction['prediction_type'])
            ->first();

        if (!$predictionSetMatch) {
            throw new \Exception('Match not found in prediction set or prediction type not allowed');
        }

        // Check if match is still eligible
        $match = FootballMatch::find($prediction['match_id']);
        if (!$match || !$match->isPredictionEligible() || $match->isPredictionDeadlinePassed()) {
            throw new \Exception('Match is not eligible for predictions');
        }

        // Create or update prediction
        return UserPrediction::updateOrCreate(
            [
                'user_id' => $user->id,
                'prediction_set_id' => $predictionSet->id,
                'match_id' => $prediction['match_id'],
                'prediction_type' => $prediction['prediction_type'],
            ],
            [
                'prediction_value' => $prediction['prediction_value'],
                'submitted_at' => now(),
            ]
        );
    }

    /**
     * Validate prediction data
     */
    public function validatePredictionData(array $prediction): void
    {
        $required = ['match_id', 'prediction_type', 'prediction_value'];
        
        foreach ($required as $field) {
            if (empty($prediction[$field])) {
                throw new \Exception("Field '{$field}' is required");
            }
        }

        $allowedTypes = ['result', 'score', 'goalscorer', 'total_goals'];
        if (!in_array($prediction['prediction_type'], $allowedTypes)) {
            throw new \Exception("Invalid prediction type. Allowed: " . implode(', ', $allowedTypes));
        }

        // Validate prediction value based on type
        $this->validatePredictionValue($prediction['prediction_type'], $prediction['prediction_value']);
    }

    /**
     * Validate prediction value based on type
     */
    public function validatePredictionValue(string $type, string $value): void
    {
        switch ($type) {
            case 'result':
                $allowedValues = ['Home Win', 'Draw', 'Away Win'];
                if (!in_array($value, $allowedValues)) {
                    throw new \Exception("Invalid result prediction. Allowed: " . implode(', ', $allowedValues));
                }
                break;

            case 'score':
                if (!preg_match('/^\d+-\d+$/', $value)) {
                    throw new \Exception("Invalid score format. Use format: X-Y (e.g., 2-1)");
                }
                break;

            case 'goalscorer':
                // Basic validation - should be a non-empty string
                if (empty(trim($value))) {
                    throw new \Exception("Goalscorer name cannot be empty");
                }
                break;

            case 'total_goals':
                $allowedValues = ['Over 0.5', 'Over 1.5', 'Over 2.5', 'Over 3.5', 'Under 0.5', 'Under 1.5', 'Under 2.5', 'Under 3.5'];
                if (!in_array($value, $allowedValues)) {
                    throw new \Exception("Invalid total goals prediction. Allowed: " . implode(', ', $allowedValues));
                }
                break;
        }
    }

    /**
     * Get user predictions for a prediction set
     */
    public function getUserPredictions(User $user, PredictionSet $predictionSet): \Illuminate\Database\Eloquent\Collection
    {
        return $user->predictions()
            ->where('prediction_set_id', $predictionSet->id)
            ->with(['match.homeTeam', 'match.awayTeam', 'match.league'])
            ->orderBy('submitted_at', 'desc')
            ->get();
    }

    /**
     * Get user prediction history
     */
    public function getUserPredictionHistory(User $user, array $filters = [], int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $user->predictions()
            ->with(['predictionSet', 'match.homeTeam', 'match.awayTeam', 'match.league']);

        if (!empty($filters['prediction_set_id'])) {
            $query->where('prediction_set_id', $filters['prediction_set_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('submitted_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (!empty($filters['date_to'])) {
            $query->where('submitted_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (array_key_exists('is_correct', $filters) && in_array($filters['is_correct'], ['0', '1', 0, 1], true)) {
            $query->where('is_correct', (bool) $filters['is_correct']);
        }

        return $query->orderBy('submitted_at', 'desc')->paginate($perPage);
    }

    /**
     * Calculate user statistics
     */
    public function getUserStats(User $user, ?PredictionSet $predictionSet = null): array
    {
        $query = $user->predictions();

        if ($predictionSet) {
            $query->where('prediction_set_id', $predictionSet->id);
        }

        $totalPredictions = $query->count();
        $correctPredictions = (clone $query)->where('is_correct', true)->count();
        $totalPoints = (clone $query)->sum('points_earned');
        $accuracy = $totalPredictions > 0 ? round(($correctPredictions / $totalPredictions) * 100, 2) : 0;

        // Get user's rank
        $rank = $this->getUserRank($user, $predictionSet);

        return [
            'total_predictions' => $totalPredictions,
            'correct_predictions' => $correctPredictions,
            'total_points' => $totalPoints,
            'accuracy_percentage' => $accuracy,
            'rank' => $rank,
        ];
    }

    /**
     * Get user's rank
     */
    public function getUserRank(User $user, ?PredictionSet $predictionSet = null): int
    {
        $leaderboardQuery = PredictionLeaderboard::query()
            ->where('period', 'all_time');

        if ($predictionSet) {
            $leaderboardQuery->where('prediction_set_id', $predictionSet->id);
        } else {
            $leaderboardQuery->whereNull('prediction_set_id');
        }

        $userEntry = $leaderboardQuery->where('user_id', $user->id)->first();
        
        return $userEntry ? (int) $userEntry->rank : 0;
    }

    /**
     * Update user leaderboard entry
     */
    public function updateUserLeaderboard(User $user, ?PredictionSet $predictionSet = null): void
    {
        $stats = $this->getUserStats($user, $predictionSet);

        PredictionLeaderboard::updateOrCreate(
            [
                'user_id' => $user->id,
                'prediction_set_id' => $predictionSet?->id,
                'period' => 'all_time',
            ],
            [
                'total_points' => $stats['total_points'],
                'correct_predictions' => $stats['correct_predictions'],
                'total_predictions' => $stats['total_predictions'],
                'accuracy_percentage' => $stats['accuracy_percentage'],
            ]
        );

        // Update ranks
        $this->updateLeaderboardRanks($predictionSet);
    }

    /**
     * Update leaderboard ranks
     */
    public function updateLeaderboardRanks(?PredictionSet $predictionSet = null): void
    {
        $query = PredictionLeaderboard::query();
        
        if ($predictionSet) {
            $query->where('prediction_set_id', $predictionSet->id);
        } else {
            $query->whereNull('prediction_set_id');
        }

        $leaderboard = $query->orderBy('total_points', 'desc')
            ->orderBy('accuracy_percentage', 'desc')
            ->get();

        foreach ($leaderboard as $index => $entry) {
            $entry->update(['rank' => $index + 1]);
        }
    }

    /**
     * Get leaderboard with filtering and pagination
     */
    public function getLeaderboard(array $filters = [], int $perPage = 50): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = PredictionLeaderboard::with('user')
            ->where('period', $filters['period'] ?? 'all_time');

        if (isset($filters['prediction_set_id']) && $filters['prediction_set_id']) {
            $query->where('prediction_set_id', $filters['prediction_set_id']);
        } else {
            $query->whereNull('prediction_set_id');
        }

        // Apply minimum predictions filter
        if (isset($filters['min_predictions'])) {
            $query->where('total_predictions', '>=', $filters['min_predictions']);
        }

        return $query->orderBy('rank')
            ->paginate($perPage);
    }

    /**
     * Get leaderboard for AJAX requests
     */
    public function getLeaderboardData(array $filters = [], int $limit = 50): array
    {
        $leaderboard = $this->getLeaderboard($filters, $limit);
        
        return [
            'success' => true,
            'leaderboard' => $leaderboard->items(),
            'pagination' => [
                'current_page' => $leaderboard->currentPage(),
                'last_page' => $leaderboard->lastPage(),
                'per_page' => $leaderboard->perPage(),
                'total' => $leaderboard->total(),
            ]
        ];
    }

    /**
     * Check if user has submitted predictions for a prediction set
     */
    public function hasUserSubmittedPredictions(User $user, PredictionSet $predictionSet): bool
    {
        return $user->predictions()
            ->where('prediction_set_id', $predictionSet->id)
            ->exists();
    }

    /**
     * Get available prediction sets for user
     */
    public function getAvailablePredictionSets(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return PredictionSet::with(['matches.match.homeTeam', 'matches.match.awayTeam', 'matches.match.league'])
            ->where('status', 'active')
            ->where('prediction_deadline', '>', now())
            ->orderBy('prediction_deadline', 'asc')
            ->get();
    }
    /** Update existing predictions while the set and its matches remain open. */
    public function updatePredictions(User $user, PredictionSet $predictionSet, array $predictions): array
    {
        if (!$predictionSet->isActive() || $predictionSet->isDeadlinePassed()) {
            return [
                'success' => false,
                'submitted_count' => 0,
                'total_predictions' => count($predictions),
                'errors' => ['The prediction deadline has passed.'],
                'message' => 'Predictions can no longer be changed',
            ];
        }

        return DB::transaction(function () use ($user, $predictionSet, $predictions) {
            $submittedCount = 0;
            $errors = [];

            foreach ($predictions as $prediction) {
                try {
                    $this->updateSinglePrediction($user, $predictionSet, $prediction);
                    $submittedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to update prediction for match {$prediction['match_id']}: " . $e->getMessage();
                    Log::error('Prediction update failed', [
                        'user_id' => $user->id,
                        'prediction_set_id' => $predictionSet->id,
                        'match_id' => $prediction['match_id'] ?? null,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Update leaderboard
            $this->updateUserLeaderboard($user, $predictionSet);

            return [
                'success' => $submittedCount > 0,
                'submitted_count' => $submittedCount,
                'total_predictions' => count($predictions),
                'errors' => $errors,
                'message' => "Successfully updated {$submittedCount} predictions"
            ];
        });
    }

    /**
     * Update a single prediction (bypasses deadline check)
     */
    public function updateSinglePrediction(User $user, PredictionSet $predictionSet, array $prediction): UserPrediction
    {
        // Validate prediction data
        $this->validatePredictionData($prediction);

        // Check if match exists in prediction set
        $predictionSetMatch = $predictionSet->matches()
            ->where('match_id', $prediction['match_id'])
            ->where('prediction_type', $prediction['prediction_type'])
            ->first();

        if (!$predictionSetMatch) {
            throw new \Exception('Match not found in prediction set or prediction type not allowed');
        }

        // Updates obey the same kickoff/eligibility rule as initial submissions.
        $match = FootballMatch::find($prediction['match_id']);
        if (!$match || !$match->isPredictionEligible() || $match->isPredictionDeadlinePassed()) {
            throw new \Exception('Match is no longer eligible for predictions');
        }

        // Update the call or create it when an admin added a new prediction
        // type after the user's original submission.
        $userPrediction = UserPrediction::firstOrNew([
            'user_id' => $user->id,
            'prediction_set_id' => $predictionSet->id,
            'match_id' => $prediction['match_id'],
            'prediction_type' => $prediction['prediction_type'],
        ]);

        $userPrediction->fill([
            'prediction_value' => $prediction['prediction_value'],
            'submitted_at' => now(),
            'is_correct' => null,
            'is_scored' => false,
            'points_earned' => 0,
        ])->save();

        return $userPrediction;
    }
}
