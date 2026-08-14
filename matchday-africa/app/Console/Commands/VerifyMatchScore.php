<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FootballMatch;
use App\Services\FootballDataService;
use App\Services\PredictionScoringService;

class VerifyMatchScore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'match:verify-score {match_id : The ID of the match to verify}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify and correct a specific match score against the API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $matchId = $this->argument('match_id');
        
        $match = FootballMatch::with(['homeTeam', 'awayTeam'])->find($matchId);
        
        if (!$match) {
            $this->error("Match with ID {$matchId} not found.");
            return 1;
        }

        if (!$match->football_data_id) {
            $this->error("Match {$matchId} has no football_data_id. Cannot verify against API.");
            return 1;
        }

        $this->info("Verifying match: {$match->homeTeam->name} vs {$match->awayTeam->name}");
        $this->info("Current score: {$match->home_score}-{$match->away_score} (Status: {$match->status})");

        $footballDataService = new FootballDataService();
        $matchData = $footballDataService->getMatchDetails($match->football_data_id);

        if (!$matchData) {
            $this->error("Could not fetch API data for match {$matchId} (FD_ID: {$match->football_data_id})");
            return 1;
        }

        $apiHomeScore = $matchData['score']['fullTime']['home'] ?? null;
        $apiAwayScore = $matchData['score']['fullTime']['away'] ?? null;
        $apiStatus = $matchData['status'] ?? 'SCHEDULED';

        $this->info("API score: {$apiHomeScore}-{$apiAwayScore} (Status: {$apiStatus})");

        if ($apiHomeScore === null || $apiAwayScore === null) {
            $this->warn("API data incomplete - no scores available");
            return 0;
        }

        // Check if correction is needed
        $needsUpdate = false;
        $oldScore = $match->home_score . '-' . $match->away_score;
        $newScore = $apiHomeScore . '-' . $apiAwayScore;

        if ($match->home_score != $apiHomeScore || $match->away_score != $apiAwayScore) {
            $needsUpdate = true;
        }

        if ($match->status !== 'FINISHED' && in_array($apiStatus, ['FINISHED', 'FT'])) {
            $needsUpdate = true;
        }

        if (!$needsUpdate) {
            $this->info("✅ Match score is correct - no update needed");
            return 0;
        }

        if ($this->confirm("Update match from {$oldScore} to {$newScore}?")) {
            $match->update([
                'status' => 'FINISHED',
                'home_score' => $apiHomeScore,
                'away_score' => $apiAwayScore,
                'scored_at' => now(),
                'last_api_update' => now(),
            ]);

            $this->info("✅ Match score updated successfully");

            // Re-score predictions
            try {
                $scoringService = new PredictionScoringService();
                $scoringService->scoreMatchPredictions($match);
                $this->info("✅ Predictions re-scored successfully");
            } catch (\Exception $e) {
                $this->error("❌ Failed to re-score predictions: " . $e->getMessage());
            }
        } else {
            $this->info("Update cancelled");
        }

        return 0;
    }
}