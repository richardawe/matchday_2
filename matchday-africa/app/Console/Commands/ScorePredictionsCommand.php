<?php

namespace App\Console\Commands;

use App\Services\PredictionScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScorePredictionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'predictions:score {--match-id= : Score predictions for a specific match}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Score predictions for completed matches';

    /**
     * Execute the console command.
     */
    public function handle(PredictionScoringService $scoringService): int
    {
        $this->info('Starting prediction scoring...');

        try {
            if ($matchId = $this->option('match-id')) {
                $this->scoreSpecificMatch($scoringService, $matchId);
            } else {
                $this->scoreAllPendingPredictions($scoringService);
            }

            $this->info('Prediction scoring completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Prediction scoring failed: ' . $e->getMessage());
            Log::error('Prediction scoring command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Score predictions for a specific match
     */
    private function scoreSpecificMatch(PredictionScoringService $scoringService, int $matchId): void
    {
        $match = \App\Models\FootballMatch::find($matchId);
        
        if (!$match) {
            $this->error("Match with ID {$matchId} not found.");
            return;
        }

        if (!$match->isFinished()) {
            $this->error("Match {$matchId} is not finished yet.");
            return;
        }

        $this->info("Scoring predictions for match: {$match->homeTeam->name} vs {$match->awayTeam->name}");

        $result = $scoringService->scoreMatchPredictions($match);

        if ($result['success']) {
            $this->info("Successfully scored {$result['scored_count']} predictions.");
            
            if (!empty($result['errors'])) {
                $this->warn('Some errors occurred:');
                foreach ($result['errors'] as $error) {
                    $this->line("  - {$error}");
                }
            }
        } else {
            $this->error('Failed to score predictions for this match.');
            foreach ($result['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }
    }

    /**
     * Score all pending predictions
     */
    private function scoreAllPendingPredictions(PredictionScoringService $scoringService): void
    {
        $this->info('Scoring all pending predictions...');

        $result = $scoringService->scoreAllPendingPredictions();

        $this->info("Processed {$result['matches_processed']} matches");
        $this->info("Scored {$result['total_scored']} predictions");
        
        if ($result['total_errors'] > 0) {
            $this->warn("Encountered {$result['total_errors']} errors during scoring");
        }

        // Show statistics
        $stats = $scoringService->getScoringStats();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Predictions', $stats['total_predictions']],
                ['Scored Predictions', $stats['scored_predictions']],
                ['Pending Predictions', $stats['pending_predictions']],
                ['Correct Predictions', $stats['correct_predictions']],
                ['Accuracy Rate', $stats['accuracy_percentage'] . '%'],
            ]
        );
    }
}
