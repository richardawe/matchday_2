<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\UserPrediction;
use App\Models\PredictionSet;
use App\Models\PredictionLeaderboard;
use App\Models\User;
use App\Notifications\PredictionScoredNotification;
use App\Notifications\MatchFinishedNotification;
use App\Events\PredictionScored;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PredictionScoringService
{
    /**
     * Score all predictions for a completed match
     */
    public function scoreMatchPredictions(FootballMatch $match): array
    {
        $scoredCount = 0;
        $errors = [];

        try {
            DB::transaction(function () use ($match, &$scoredCount, &$errors) {
                $predictions = UserPrediction::where('match_id', $match->id)
                    ->where('is_scored', false)
                    ->get();

                foreach ($predictions as $prediction) {
                    try {
                        $this->scoreSinglePrediction($prediction, $match);
                        $scoredCount++;
                    } catch (\Exception $e) {
                        $errors[] = "Failed to score prediction {$prediction->id}: " . $e->getMessage();
                        Log::error('Prediction scoring failed', [
                            'prediction_id' => $prediction->id,
                            'match_id' => $match->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Update leaderboards for affected prediction sets
                $this->updateLeaderboardsForMatch($match);
                
                // Send match finished notifications
                $this->sendMatchFinishedNotifications($match);
            });

            return [
                'success' => true,
                'scored_count' => $scoredCount,
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            Log::error('Match scoring failed', [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'scored_count' => $scoredCount,
                'errors' => array_merge($errors, [$e->getMessage()])
            ];
        }
    }

    /**
     * Score a single prediction
     */
    public function scoreSinglePrediction(UserPrediction $prediction, FootballMatch $match): void
    {
        $scoringResult = $this->evaluatePredictionWithScoring($prediction, $match);
        
        $prediction->update([
            'is_correct' => $scoringResult['is_correct'],
            'points_earned' => $scoringResult['points_earned'],
            'is_scored' => $scoringResult['is_correct'] !== null,
        ]);

        // Send notification to user
        $user = $prediction->user;
        if ($user && $scoringResult['is_correct'] !== null) {
            $user->notify(new PredictionScoredNotification($match, $prediction, $scoringResult['is_correct']));
        }

        // Broadcast prediction scored event
        broadcast(new PredictionScored($match, $prediction));

        Log::info('Prediction scored', [
            'prediction_id' => $prediction->id,
            'is_correct' => $scoringResult['is_correct'],
            'points_earned' => $scoringResult['points_earned'],
            'scoring_type' => $scoringResult['scoring_type']
        ]);
    }

    /**
     * Evaluate prediction with comprehensive scoring
     */
    public function evaluatePredictionWithScoring(UserPrediction $prediction, FootballMatch $match): array
    {
        if (!$match->isFinished() || $match->home_score === null || $match->away_score === null) {
            return [
                'is_correct' => false,
                'points_earned' => 0,
                'scoring_type' => 'no_score'
            ];
        }

        switch ($prediction->prediction_type) {
            case 'result':
                return $this->evaluateResultPredictionWithScoring($prediction, $match);
            case 'score':
                return $this->evaluateScorePredictionWithScoring($prediction, $match);
            case 'goalscorer':
                return $this->evaluateGoalscorerPredictionWithScoring($prediction, $match);
            case 'total_goals':
                return $this->evaluateTotalGoalsPredictionWithScoring($prediction, $match);
            default:
                return [
                    'is_correct' => false,
                    'points_earned' => 0,
                    'scoring_type' => 'unknown_type'
                ];
        }
    }

    /**
     * Evaluate if a prediction is correct (legacy method for backward compatibility)
     */
    public function evaluatePrediction(UserPrediction $prediction, FootballMatch $match): bool
    {
        $result = $this->evaluatePredictionWithScoring($prediction, $match);
        return $result['is_correct'];
    }

    /**
     * Evaluate result prediction with comprehensive scoring
     */
    private function evaluateResultPredictionWithScoring(UserPrediction $prediction, FootballMatch $match): array
    {
        $actualResult = $this->getMatchResult($match);
        $isCorrect = $this->isResultCorrect($prediction->prediction_value, $actualResult);
        $points = $this->pointsFor($prediction, 1);
        
        return [
            'is_correct' => $isCorrect,
            'points_earned' => $isCorrect ? $points : 0,
            'scoring_type' => 'result'
        ];
    }

    /**
     * Evaluate score prediction with comprehensive scoring
     */
    private function evaluateScorePredictionWithScoring(UserPrediction $prediction, FootballMatch $match): array
    {
        $actualScore = $match->home_score . '-' . $match->away_score;
        $actualResult = $this->getMatchResult($match);
        
        // Check if exact score is correct
        $exactScoreCorrect = $prediction->prediction_value === $actualScore;
        
        // Check if result is correct (for partial points)
        // Convert predicted score to result for comparison
        $predictedResult = $this->getResultFromScore($prediction->prediction_value);
        $resultCorrect = $predictedResult && $predictedResult === $actualResult;
        
        if ($exactScoreCorrect) {
            return [
                'is_correct' => true,
                'points_earned' => $this->pointsFor($prediction, 3),
                'scoring_type' => 'exact_score'
            ];
        } elseif ($resultCorrect) {
            return [
                'is_correct' => true,
                'points_earned' => 1, // Consolation point for the right outcome
                'scoring_type' => 'partial_result'
            ];
        } else {
            return [
                'is_correct' => false,
                'points_earned' => 0,
                'scoring_type' => 'incorrect'
            ];
        }
    }

    /**
     * Check if a prediction value matches the actual result
     */
    private function isResultCorrect(string $predictionValue, string $actualResult): bool
    {
        // Handle different possible prediction values
        $predictionValue = trim($predictionValue);
        $actualResult = trim($actualResult);
        
        // Direct match
        if ($predictionValue === $actualResult) {
            return true;
        }
        
        // Handle variations
        $resultVariations = [
            'Home Win' => ['Home', '1', '1X', 'Home Win', 'H'],
            'Away Win' => ['Away', '2', 'X2', 'Away Win', 'A'],
            'Draw' => ['Draw', 'X', '0', 'Tie', 'D']
        ];
        
        foreach ($resultVariations as $result => $variations) {
            if ($actualResult === $result) {
                return in_array($predictionValue, $variations);
            }
        }
        
        return false;
    }

    /**
     * Evaluate result prediction (Home Win, Draw, Away Win) - Legacy method
     */
    private function evaluateResultPrediction(UserPrediction $prediction, FootballMatch $match): bool
    {
        $result = $this->evaluateResultPredictionWithScoring($prediction, $match);
        return $result['is_correct'];
    }

    /**
     * Evaluate correct score prediction - Legacy method
     */
    private function evaluateScorePrediction(UserPrediction $prediction, FootballMatch $match): bool
    {
        $result = $this->evaluateScorePredictionWithScoring($prediction, $match);
        return $result['is_correct'];
    }

    /**
     * Evaluate first goalscorer prediction with comprehensive scoring
     */
    private function evaluateGoalscorerPredictionWithScoring(UserPrediction $prediction, FootballMatch $match): array
    {
        $firstGoal = $match->events()
            ->where('type', 'goal')
            ->where('is_own_goal', false)
            ->orderBy('minute')
            ->orderByRaw('COALESCE(extra_minute, 0)')
            ->orderBy('sort_order')
            ->first();

        if (!$firstGoal || !$firstGoal->player_name) {
            // Do not turn unavailable event data into a losing prediction.
            return [
                'is_correct' => null,
                'points_earned' => 0,
                'scoring_type' => 'goalscorer_event_unavailable'
            ];
        }

        $normalize = static fn (string $name): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $isCorrect = $normalize($prediction->prediction_value) === $normalize($firstGoal->player_name);

        return [
            'is_correct' => $isCorrect,
            'points_earned' => $isCorrect ? $this->pointsFor($prediction, 2) : 0,
            'scoring_type' => 'first_goalscorer'
        ];
    }

    /**
     * Evaluate total goals prediction with comprehensive scoring
     */
    private function evaluateTotalGoalsPredictionWithScoring(UserPrediction $prediction, FootballMatch $match): array
    {
        $totalGoals = $match->home_score + $match->away_score;
        $predictionValue = $prediction->prediction_value;

        if (str_starts_with($predictionValue, 'Over ')) {
            $threshold = (float) str_replace('Over ', '', $predictionValue);
            $isCorrect = $totalGoals > $threshold;
            return [
                'is_correct' => $isCorrect,
                'points_earned' => $isCorrect ? $this->pointsFor($prediction, 1) : 0,
                'scoring_type' => 'total_goals_over'
            ];
        } elseif (str_starts_with($predictionValue, 'Under ')) {
            $threshold = (float) str_replace('Under ', '', $predictionValue);
            $isCorrect = $totalGoals < $threshold;
            return [
                'is_correct' => $isCorrect,
                'points_earned' => $isCorrect ? $this->pointsFor($prediction, 1) : 0,
                'scoring_type' => 'total_goals_under'
            ];
        }

        return [
            'is_correct' => false,
            'points_earned' => 0,
            'scoring_type' => 'total_goals_invalid'
        ];
    }

    /**
     * Evaluate first goalscorer prediction - Legacy method
     */
    private function evaluateGoalscorerPrediction(UserPrediction $prediction, FootballMatch $match): bool
    {
        $result = $this->evaluateGoalscorerPredictionWithScoring($prediction, $match);
        return $result['is_correct'];
    }

    /**
     * Evaluate total goals prediction (Over/Under) - Legacy method
     */
    private function evaluateTotalGoalsPrediction(UserPrediction $prediction, FootballMatch $match): bool
    {
        $result = $this->evaluateTotalGoalsPredictionWithScoring($prediction, $match);
        return $result['is_correct'];
    }

    /**
     * Get match result as string
     */
    private function getMatchResult(FootballMatch $match): string
    {
        if ($match->home_score > $match->away_score) {
            return 'Home Win';
        } elseif ($match->home_score < $match->away_score) {
            return 'Away Win';
        } else {
            return 'Draw';
        }
    }

    /**
     * Convert a score prediction to a result
     */
    private function getResultFromScore(string $scorePrediction): ?string
    {
        // Parse the score prediction (e.g., "2-1", "0-0", "1-3")
        if (!preg_match('/^(\d+)-(\d+)$/', $scorePrediction, $matches)) {
            return null; // Invalid score format
        }

        $homeScore = (int) $matches[1];
        $awayScore = (int) $matches[2];

        if ($homeScore > $awayScore) {
            return 'Home Win';
        } elseif ($homeScore < $awayScore) {
            return 'Away Win';
        } else {
            return 'Draw';
        }
    }

    /**
     * Calculate points for a correct prediction (Legacy method - now handled in evaluation methods)
     */
    private function calculatePoints(UserPrediction $prediction): int
    {
        // This method is now deprecated - scoring is handled in individual evaluation methods
        // Keeping for backward compatibility
        switch ($prediction->prediction_type) {
            case 'score':
                return 3; // Correct score gets 3 points
            case 'result':
                return 1; // Correct result gets 1 point
            case 'goalscorer':
                return 2; // First goalscorer gets 2 points (bonus for difficulty)
            case 'total_goals':
                return 1; // Total goals gets 1 point
            default:
                return 0;
        }
    }

    /** Resolve the administrator-configured value for this set/match/type. */
    private function pointsFor(UserPrediction $prediction, int $default): int
    {
        $configured = \App\Models\PredictionSetMatch::where('prediction_set_id', $prediction->prediction_set_id)
            ->where('match_id', $prediction->match_id)
            ->where('prediction_type', $prediction->prediction_type)
            ->value('points_value');

        return $configured !== null ? (int) $configured : $default;
    }

    /**
     * Update leaderboards for a specific match
     */
    public function updateLeaderboardsForMatch(FootballMatch $match): void
    {
        $predictionSets = $match->predictionSetMatches()
            ->with('predictionSet')
            ->get()
            ->pluck('predictionSet')
            ->filter() // Remove null values
            ->unique('id');

        foreach ($predictionSets as $predictionSet) {
            if ($predictionSet) {
                $this->updateLeaderboardForPredictionSet($predictionSet);
            }
        }

        // Update global leaderboard
        $this->updateGlobalLeaderboard();
    }

    /**
     * Update leaderboard for a specific prediction set
     */
    public function updateLeaderboardForPredictionSet(PredictionSet $predictionSet): void
    {
        if (!$predictionSet) {
            return;
        }

        $users = $predictionSet->userPredictions()
            ->distinct('user_id')
            ->pluck('user_id');

        foreach ($users as $userId) {
            $this->updateUserLeaderboardEntry($userId, $predictionSet);
        }
    }

    /**
     * Update global leaderboard
     */
    public function updateGlobalLeaderboard(): void
    {
        $users = UserPrediction::distinct('user_id')->pluck('user_id');

        foreach ($users as $userId) {
            $this->updateUserLeaderboardEntry($userId);
        }
    }

    /**
     * Update user leaderboard entry
     */
    public function updateUserLeaderboardEntry(int $userId, ?PredictionSet $predictionSet = null): void
    {
        $query = UserPrediction::where('user_id', $userId);

        if ($predictionSet) {
            $query->where('prediction_set_id', $predictionSet->id);
        }

        $totalPredictions = $query->count();
        $correctPredictions = (clone $query)->where('is_correct', true)->count();
        $totalPoints = (clone $query)->sum('points_earned');
        $accuracy = $totalPredictions > 0 ? round(($correctPredictions / $totalPredictions) * 100, 2) : 0;

        PredictionLeaderboard::updateOrCreate(
            [
                'user_id' => $userId,
                'prediction_set_id' => $predictionSet?->id,
                'period' => 'all_time',
            ],
            [
                'total_points' => $totalPoints,
                'correct_predictions' => $correctPredictions,
                'total_predictions' => $totalPredictions,
                'accuracy_percentage' => $accuracy,
            ]
        );
    }

    /**
     * Update all leaderboard ranks
     */
    public function updateAllLeaderboardRanks(): void
    {
        // Update ranks for each prediction set
        $predictionSets = PredictionSet::all();
        foreach ($predictionSets as $predictionSet) {
            $this->updateLeaderboardRanks($predictionSet);
        }

        // Update global ranks
        $this->updateLeaderboardRanks();
    }

    /**
     * Update leaderboard ranks for a prediction set or globally
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
     * Score all pending predictions for completed matches
     */
    public function scoreAllPendingPredictions(): array
    {
        $completedMatches = FootballMatch::where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->whereHas('userPredictions', function ($query) {
                $query->where('is_scored', false);
            })
            ->get();

        $totalScored = 0;
        $totalErrors = 0;

        foreach ($completedMatches as $match) {
            $result = $this->scoreMatchPredictions($match);
            $totalScored += $result['scored_count'];
            $totalErrors += count($result['errors']);
        }

        // Update all leaderboard ranks
        $this->updateAllLeaderboardRanks();

        return [
            'success' => true,
            'matches_processed' => $completedMatches->count(),
            'total_scored' => $totalScored,
            'total_errors' => $totalErrors
        ];
    }

    /**
     * Get scoring statistics
     */
    public function getScoringStats(): array
    {
        $totalPredictions = UserPrediction::count();
        $scoredPredictions = UserPrediction::where('is_scored', true)->count();
        $pendingPredictions = $totalPredictions - $scoredPredictions;
        $correctPredictions = UserPrediction::where('is_correct', true)->count();
        $accuracy = $scoredPredictions > 0 ? round(($correctPredictions / $scoredPredictions) * 100, 2) : 0;

        return [
            'total_predictions' => $totalPredictions,
            'scored_predictions' => $scoredPredictions,
            'pending_predictions' => $pendingPredictions,
            'correct_predictions' => $correctPredictions,
            'accuracy_percentage' => $accuracy,
        ];
    }

    /**
     * Send match finished notifications to all users who predicted on the match
     */
    public function sendMatchFinishedNotifications(FootballMatch $match): void
    {
        try {
            $users = $match->userPredictions()
                ->with('user')
                ->get()
                ->pluck('user')
                ->unique('id');

            foreach ($users as $user) {
                if ($user) {
                    $user->notify(new MatchFinishedNotification($match));
                }
            }

            Log::info('Match finished notifications sent', [
                'match_id' => $match->id,
                'users_notified' => $users->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send match finished notifications', [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
