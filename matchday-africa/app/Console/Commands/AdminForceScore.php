<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\PredictionScoringService;
use Illuminate\Console\Command;

class AdminForceScore extends Command
{
    protected $signature = 'admin:force-score {match_id?} {--all : Force score all finished matches}';
    protected $description = 'Force score predictions for specific match or all finished matches';

    protected $scoringService;

    public function __construct(PredictionScoringService $scoringService)
    {
        parent::__construct();
        $this->scoringService = $scoringService;
    }

    public function handle()
    {
        if ($this->option('all')) {
            return $this->scoreAllFinishedMatches();
        }

        $matchId = $this->argument('match_id');
        if (!$matchId) {
            $this->error('Please provide a match ID or use --all option');
            return 1;
        }

        $match = FootballMatch::find($matchId);
        if (!$match) {
            $this->error("Match with ID {$matchId} not found");
            return 1;
        }

        if ($match->status !== 'finished') {
            $this->error("Match {$matchId} is not finished. Current status: {$match->status}");
            return 1;
        }

        $this->info("Force scoring predictions for match: {$match->homeTeam->name} vs {$match->awayTeam->name}");
        
        try {
            $this->scoringService->scoreMatchPredictions($match);
            
            $scoredCount = $match->predictions()->where('is_scored', true)->count();
            $this->info("✅ Successfully scored {$scoredCount} predictions!");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Scoring failed: " . $e->getMessage());
            return 1;
        }
    }

    private function scoreAllFinishedMatches()
    {
        $this->info('Force scoring all finished matches...');
        
        $finishedMatches = FootballMatch::where('status', 'finished')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->get();

        if ($finishedMatches->isEmpty()) {
            $this->info('No finished matches with scores found');
            return 0;
        }

        $this->info("Found {$finishedMatches->count()} finished matches");

        $scored = 0;
        $errors = 0;

        foreach ($finishedMatches as $match) {
            try {
                $this->scoringService->scoreMatchPredictions($match);
                $scoredCount = $match->predictions()->where('is_scored', true)->count();
                $this->info("✅ {$match->homeTeam->name} vs {$match->awayTeam->name}: {$scoredCount} predictions scored");
                $scored++;
            } catch (\Exception $e) {
                $this->error("❌ {$match->homeTeam->name} vs {$match->awayTeam->name}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->info("\n📊 Summary:");
        $this->info("✅ Successfully scored: {$scored} matches");
        if ($errors > 0) {
            $this->error("❌ Errors: {$errors} matches");
        }

        return $errors > 0 ? 1 : 0;
    }
}
