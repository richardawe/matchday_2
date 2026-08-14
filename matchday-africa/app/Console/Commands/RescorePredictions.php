<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserPrediction;
use App\Services\PredictionScoringService;
use Illuminate\Support\Facades\DB;

class RescorePredictions extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'predictions:rescore 
                            {--type= : Only re-score specific prediction type (result, score, total_goals, goalscorer)}
                            {--user= : Only re-score for specific user ID}
                            {--match= : Only re-score for specific match ID}
                            {--dry-run : Show what would be re-scored without actually doing it}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Re-score predictions with the new scoring logic';

    protected $scoringService;

    public function __construct(PredictionScoringService $scoringService)
    {
        parent::__construct();
        $this->scoringService = $scoringService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Starting prediction re-scoring with new logic...');

        $query = UserPrediction::whereNotNull('is_correct');

        // Apply filters
        if ($type = $this->option('type')) {
            $query->where('prediction_type', $type);
            $this->info("Filtering by prediction type: {$type}");
        }

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
            $this->info("Filtering by user ID: {$userId}");
        }

        if ($matchId = $this->option('match')) {
            $query->where('match_id', $matchId);
            $this->info("Filtering by match ID: {$matchId}");
        }

        $predictionsToRescore = $query->get();

        if ($predictionsToRescore->isEmpty()) {
            $this->warn('No predictions found to re-score with the given criteria.');
            return 0;
        }

        $this->info("Found {$predictionsToRescore->count()} predictions to re-score:");

        // Show breakdown by type
        $breakdown = $predictionsToRescore->groupBy('prediction_type');
        foreach ($breakdown as $type => $predictions) {
            $this->line("  - {$type}: {$predictions->count()} predictions");
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: No changes will be made.');
            return 0;
        }

        if (!$this->option('force') && !$this->confirm('Do you want to proceed with re-scoring these predictions?')) {
            $this->info('Re-scoring cancelled.');
            return 0;
        }

        // Clear existing scores
        $this->info('Clearing existing scores...');
        $cleared = $query->update([
            'is_correct' => null,
            'points_earned' => 0
        ]);
        $this->info("Cleared {$cleared} prediction scores.");

        // Re-score with new logic
        $this->info('Re-scoring with new logic...');
        $result = $this->scoringService->scoreAllPendingPredictions();

        if ($result['success']) {
            $this->info("✅ Successfully re-scored {$result['total_scored']} predictions!");
            
            if ($result['total_errors'] > 0) {
                $this->warn("⚠️  {$result['total_errors']} errors occurred during re-scoring");
            }

            // Show updated stats
            $this->showUpdatedStats();
        } else {
            $this->error('❌ Re-scoring failed!');
            return 1;
        }

        return 0;
    }

    /**
     * Show updated scoring statistics
     */
    private function showUpdatedStats()
    {
        $stats = $this->scoringService->getScoringStats();
        
        $this->info('📊 Updated Statistics:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Predictions', $stats['total_predictions']],
                ['Scored Predictions', $stats['scored_predictions']],
                ['Correct Predictions', $stats['correct_predictions']],
                ['Accuracy Rate', $stats['accuracy_percentage'] . '%'],
            ]
        );
    }
}


