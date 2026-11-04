<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\UserPrediction;
use App\Models\PredictionSet;
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
            ->get()
            // A match closed only because its feed went quiet waits for a confirmed result.
            ->reject(fn (FootballMatch $match) => $match->statusInferred());

        $totalScored = 0;
        $totalErrors = 0;

        foreach ($completedMatches as $match) {
            $result = $this->scoreMatchPredictions($match);
            $totalScored += $result['scored_count'];
            $totalErrors += count($result['errors']);
        }


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
