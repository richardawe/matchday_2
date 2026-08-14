<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\PredictionScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScoreFinishedMatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'predictions:score-finished 
                            {--match-id= : Score specific match by ID}
                            {--force : Force scoring even if already scored}
                            {--dry-run : Show what would be scored without actually scoring}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Score predictions for finished matches';

    /**
     * Execute the console command.
     */
    public function handle(PredictionScoringService $scoringService): int
    {
        $this->info('Starting prediction scoring process...');

        if ($matchId = $this->option('match-id')) {
            return $this->scoreSpecificMatch($matchId, $scoringService);
        }

        return $this->scoreAllFinishedMatches($scoringService);
    }

    /**
     * Score a specific match
     */
    private function scoreSpecificMatch(int $matchId, PredictionScoringService $scoringService): int
    {
        $match = FootballMatch::find($matchId);

        if (!$match) {
            $this->error("Match with ID {$matchId} not found.");
            return 1;
        }

        $this->info("Scoring predictions for match: {$match->homeTeam->name} vs {$match->awayTeam->name}");

        if ($this->option('dry-run')) {
            $this->showMatchScoringPreview($match);
            return 0;
        }

        $result = $scoringService->scoreMatchPredictions($match);

        if ($result['success']) {
            $this->info("✅ Successfully scored {$result['scored_count']} predictions");
            
            if (!empty($result['errors'])) {
                $this->warn("⚠️  {$result['errors']} errors occurred during scoring");
                foreach ($result['errors'] as $error) {
                    $this->line("   - {$error}");
                }
            }
        } else {
            $this->error("❌ Failed to score predictions");
            foreach ($result['errors'] as $error) {
                $this->line("   - {$error}");
            }
            return 1;
        }

        return 0;
    }

    /**
     * Score all finished matches
     */
    private function scoreAllFinishedMatches(PredictionScoringService $scoringService): int
    {
        $query = FootballMatch::where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->whereHas('userPredictions', function ($query) {
                $query->where('is_scored', false);
            });

        if (!$this->option('force')) {
            $query->where('scored_at', null);
        }

        $matches = $query->with(['homeTeam', 'awayTeam'])->get();

        if ($matches->isEmpty()) {
            $this->info('No finished matches with unscored predictions found.');
            return 0;
        }

        $this->info("Found {$matches->count()} finished matches with unscored predictions:");

        if ($this->option('dry-run')) {
            $this->showMatchesPreview($matches);
            return 0;
        }

        $progressBar = $this->output->createProgressBar($matches->count());
        $progressBar->start();

        $totalScored = 0;
        $totalErrors = 0;

        foreach ($matches as $match) {
            $result = $scoringService->scoreMatchPredictions($match);
            
            if ($result['success']) {
                $totalScored += $result['scored_count'];
                $totalErrors += count($result['errors']);
                
                // Mark match as scored
                $match->update(['scored_at' => now()]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Scoring completed!");
        $this->info("   - Matches processed: {$matches->count()}");
        $this->info("   - Total predictions scored: {$totalScored}");
        $this->info("   - Total errors: {$totalErrors}");

        return 0;
    }

    /**
     * Show preview of what would be scored for a specific match
     */
    private function showMatchScoringPreview(FootballMatch $match): void
    {
        $unscoredPredictions = $match->userPredictions()
            ->where('is_scored', false)
            ->count();

        $this->info("Match: {$match->homeTeam->name} vs {$match->awayTeam->name}");
        $this->info("Score: {$match->home_score} - {$match->away_score}");
        $this->info("Unscored predictions: {$unscoredPredictions}");
    }

    /**
     * Show preview of all matches that would be scored
     */
    private function showMatchesPreview($matches): void
    {
        $this->table(
            ['Match', 'Score', 'Unscored Predictions'],
            $matches->map(function ($match) {
                $unscoredPredictions = $match->userPredictions()
                    ->where('is_scored', false)
                    ->count();

                return [
                    "{$match->homeTeam->name} vs {$match->awayTeam->name}",
                    "{$match->home_score} - {$match->away_score}",
                    $unscoredPredictions
                ];
            })
        );
    }
}
