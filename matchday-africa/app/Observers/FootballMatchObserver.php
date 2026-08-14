<?php

namespace App\Observers;

use App\Models\FootballMatch;
use App\Services\PredictionScoringService;
use App\Events\MatchScoreUpdated;
use Illuminate\Support\Facades\Log;

class FootballMatchObserver
{
    /**
     * Handle the FootballMatch "updated" event.
     */
    public function updated(FootballMatch $match): void
    {
        // Broadcast score updates
        $this->broadcastScoreUpdate($match);
        
        // Check if match just finished and has scores
        if ($this->shouldTriggerScoring($match)) {
            $this->triggerScoring($match);
        }
    }

    /**
     * Handle the FootballMatch "created" event.
     */
    public function created(FootballMatch $match): void
    {
        // Check if match is already finished when created (rare case)
        if ($this->shouldTriggerScoring($match)) {
            $this->triggerScoring($match);
        }
    }

    /**
     * Determine if scoring should be triggered
     */
    private function shouldTriggerScoring(FootballMatch $match): bool
    {
        // Match must be finished
        if (!$match->isFinished()) {
            return false;
        }

        // Match must have scores
        if ($match->home_score === null || $match->away_score === null) {
            return false;
        }

        // Match must not already be scored
        if ($match->scored_at !== null) {
            return false;
        }

        // Match must have predictions
        if (!$match->userPredictions()->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Trigger scoring for the match
     */
    private function triggerScoring(FootballMatch $match): void
    {
        try {
            Log::info('Triggering automatic scoring for match', [
                'match_id' => $match->id,
                'home_team' => $match->homeTeam->name ?? 'Unknown',
                'away_team' => $match->awayTeam->name ?? 'Unknown',
                'score' => $match->home_score . '-' . $match->away_score
            ]);

            // Score synchronously so a missing/failed queue worker cannot strand a match.
            $result = app(PredictionScoringService::class)->scoreMatchPredictions($match);

            if (!$result['success'] || !empty($result['errors'])) {
                throw new \RuntimeException(implode('; ', $result['errors']) ?: 'Prediction scoring failed');
            }

            $match->updateQuietly(['scored_at' => now()]);

        } catch (\Exception $e) {
            Log::error('Failed to trigger automatic scoring', [
                'match_id' => $match->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Broadcast score updates
     */
    private function broadcastScoreUpdate(FootballMatch $match): void
    {
        try {
            // Only broadcast if scores have changed
            if ($match->wasChanged(['home_score', 'away_score', 'status', 'minute'])) {
                broadcast(new MatchScoreUpdated($match));
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast score update', [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
