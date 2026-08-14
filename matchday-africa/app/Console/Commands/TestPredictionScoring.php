<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FootballMatch;
use App\Models\UserPrediction;
use App\Services\PredictionScoringService;
use App\Models\User;
use App\Models\PredictionSet;

class TestPredictionScoring extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'test:prediction-scoring {match_id?}';

    /**
     * The console command description.
     */
    protected $description = 'Test the prediction scoring system with sample data';

    /**
     * Execute the console command.
     */
    public function handle(PredictionScoringService $scoringService)
    {
        $this->info('🧪 Testing Prediction Scoring System...');
        
        // Get match ID from argument or find a finished match
        $matchId = $this->argument('match_id');
        
        if ($matchId) {
            $match = FootballMatch::find($matchId);
        } else {
            // Find a finished match with scores
            $match = FootballMatch::where('status', 'FINISHED')
                ->whereNotNull('home_score')
                ->whereNotNull('away_score')
                ->first();
        }
        
        if (!$match) {
            $this->error('❌ No finished match found with scores. Please provide a match ID or finish a match first.');
            return 1;
        }
        
        $this->info("📊 Testing with match: {$match->homeTeam->name} vs {$match->awayTeam->name}");
        $this->info("🏆 Final Score: {$match->home_score}-{$match->away_score}");
        
        // Check if there are any predictions for this match
        $predictions = UserPrediction::where('match_id', $match->id)->get();
        
        if ($predictions->isEmpty()) {
            $this->warn('⚠️  No predictions found for this match. Creating test predictions...');
            $this->createTestPredictions($match);
            $predictions = UserPrediction::where('match_id', $match->id)->get();
        }
        
        $this->info("📝 Found {$predictions->count()} predictions to score");
        
        // Show predictions before scoring
        $this->table(
            ['User', 'Type', 'Prediction', 'Current Points'],
            $predictions->map(function ($prediction) {
                return [
                    $prediction->user->name,
                    ucfirst($prediction->prediction_type),
                    $prediction->prediction_value,
                    $prediction->points_earned ?? 'Not scored'
                ];
            })
        );
        
        // Score the predictions
        $this->info('🎯 Scoring predictions...');
        $result = $scoringService->scoreMatchPredictions($match);
        
        if ($result['success']) {
            $this->info("✅ Successfully scored {$result['scored_count']} predictions");
            
            if (!empty($result['errors'])) {
                $this->warn('⚠️  Some errors occurred:');
                foreach ($result['errors'] as $error) {
                    $this->error("   - {$error}");
                }
            }
        } else {
            $this->error('❌ Failed to score predictions');
            return 1;
        }
        
        // Show results after scoring
        $this->info('📊 Results after scoring:');
        $scoredPredictions = UserPrediction::where('match_id', $match->id)->get();
        
        $this->table(
            ['User', 'Type', 'Prediction', 'Correct', 'Points Earned'],
            $scoredPredictions->map(function ($prediction) {
                return [
                    $prediction->user->name,
                    ucfirst($prediction->prediction_type),
                    $prediction->prediction_value,
                    $prediction->is_correct ? '✅ Yes' : '❌ No',
                    $prediction->points_earned
                ];
            })
        );
        
        // Show scoring summary
        $correctCount = $scoredPredictions->where('is_correct', true)->count();
        $totalPoints = $scoredPredictions->sum('points_earned');
        
        $this->info("📈 Summary:");
        $this->info("   - Correct predictions: {$correctCount}/{$scoredPredictions->count()}");
        $this->info("   - Total points awarded: {$totalPoints}");
        
        return 0;
    }
    
    /**
     * Create test predictions for the match
     */
    private function createTestPredictions(FootballMatch $match)
    {
        $user = User::first();
        if (!$user) {
            $this->error('❌ No users found. Please create a user first.');
            return;
        }
        
        $predictionSet = PredictionSet::first();
        if (!$predictionSet) {
            $this->error('❌ No prediction sets found. Please create a prediction set first.');
            return;
        }
        
        // Create test predictions
        $testPredictions = [
            [
                'prediction_type' => 'result',
                'prediction_value' => $match->home_score > $match->away_score ? 'Home Win' : ($match->home_score < $match->away_score ? 'Away Win' : 'Draw')
            ],
            [
                'prediction_type' => 'score',
                'prediction_value' => $match->home_score . '-' . $match->away_score
            ],
            [
                'prediction_type' => 'result',
                'prediction_value' => 'Home Win' // This should be wrong if it's not a home win
            ]
        ];
        
        foreach ($testPredictions as $predictionData) {
            UserPrediction::create([
                'user_id' => $user->id,
                'prediction_set_id' => $predictionSet->id,
                'match_id' => $match->id,
                'prediction_type' => $predictionData['prediction_type'],
                'prediction_value' => $predictionData['prediction_value'],
                'is_correct' => null,
                'points_earned' => 0,
                'submitted_at' => now()
            ]);
        }
        
        $this->info('✅ Created test predictions');
    }
}