<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\PredictionScoringService;
use Illuminate\Console\Command;

class TestAutomaticScoring extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'predictions:test-scoring {match_id?}';

    /**
     * The console command description.
     */
    protected $description = 'Test automatic scoring system by simulating a match finish';

    /**
     * Execute the console command.
     */
    public function handle(PredictionScoringService $scoringService): int
    {
        $matchId = $this->argument('match_id');
        
        if ($matchId) {
            $match = FootballMatch::find($matchId);
            if (!$match) {
                $this->error("Match with ID {$matchId} not found.");
                return 1;
            }
        } else {
            // Find a match with predictions that hasn't been scored
            $match = FootballMatch::whereHas('userPredictions')
                ->whereNull('scored_at')
                ->whereNotNull('home_score')
                ->whereNotNull('away_score')
                ->where('status', 'finished')
                ->first();
                
            if (!$match) {
                $this->error('No finished matches with predictions found.');
                return 1;
            }
        }

        $this->info("Testing automatic scoring for match: {$match->homeTeam->name} vs {$match->awayTeam->name}");
        $this->info("Score: {$match->home_score}-{$match->away_score}");
        
        $predictionCount = $match->userPredictions()->count();
        $this->info("Predictions to score: {$predictionCount}");

        if ($predictionCount === 0) {
            $this->warn('No predictions found for this match.');
            return 0;
        }

        // Simulate the scoring process
        $result = $scoringService->scoreMatchPredictions($match);
        
        if ($result['success']) {
            $this->info("✅ Successfully scored {$result['scored_count']} predictions");
            
            if (!empty($result['errors'])) {
                $this->warn('Some errors occurred:');
                foreach ($result['errors'] as $error) {
                    $this->error("  - {$error}");
                }
            }
        } else {
            $this->error('❌ Scoring failed');
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $this->error("  - {$error}");
                }
            }
        }

        return 0;
    }
}
