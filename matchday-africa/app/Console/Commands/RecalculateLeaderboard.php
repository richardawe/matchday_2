<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PredictionLeaderboard;
use App\Models\UserPrediction;
use App\Models\User;
use App\Models\PredictionSet;
use App\Services\PredictionScoringService;

class RecalculateLeaderboard extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'leaderboard:recalculate {--force : Force recalculation even if leaderboard exists}';

    /**
     * The console command description.
     */
    protected $description = 'Recalculate all leaderboard entries with the new scoring system';

    /**
     * Execute the console command.
     */
    public function handle(PredictionScoringService $scoringService)
    {
        $this->info('🔄 Recalculating leaderboard with new scoring system...');
        
        $force = $this->option('force');
        
        if (!$force) {
            if ($this->confirm('This will recalculate all leaderboard entries. Continue?')) {
                $this->info('Proceeding with recalculation...');
            } else {
                $this->info('Recalculation cancelled.');
                return 0;
            }
        }
        
        // Clear existing leaderboard entries
        $this->info('🗑️  Clearing existing leaderboard entries...');
        PredictionLeaderboard::truncate();
        $this->info('✅ Cleared existing entries');
        
        // Get all users who have predictions
        $users = User::whereHas('predictions')->get();
        $this->info("👥 Found {$users->count()} users with predictions");
        
        // Recalculate global leaderboard
        $this->info('🌍 Recalculating global leaderboard...');
        $scoringService->updateGlobalLeaderboard();
        $this->info('✅ Global leaderboard updated');
        
        // Recalculate prediction set specific leaderboards
        $predictionSets = PredictionSet::all();
        $this->info("📊 Recalculating {$predictionSets->count()} prediction set leaderboards...");
        
        foreach ($predictionSets as $predictionSet) {
            $this->info("   - Updating leaderboard for: {$predictionSet->name}");
            $scoringService->updateLeaderboardForPredictionSet($predictionSet);
        }
        
        $this->info('✅ All prediction set leaderboards updated');
        
        // Show updated leaderboard
        $this->info('📈 Updated leaderboard (Top 10):');
        $topUsers = PredictionLeaderboard::with('user')
            ->whereNull('prediction_set_id')
            ->orderBy('total_points', 'desc')
            ->limit(10)
            ->get();
            
        $this->table(
            ['Rank', 'User', 'Points', 'Predictions', 'Accuracy'],
            $topUsers->map(function ($entry, $index) {
                return [
                    $index + 1,
                    $entry->user->name,
                    $entry->total_points,
                    $entry->total_predictions,
                    $entry->accuracy_percentage . '%'
                ];
            })
        );
        
        $this->info('🎉 Leaderboard recalculation completed!');
        
        return 0;
    }
}