<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\MatchPreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GenerateDailyMatchPreviews extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'previews:generate-daily {--date= : Specific date to generate previews for (Y-m-d format)} {--force : Force regeneration of existing previews}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate AI-powered match previews for all matches on a specific day';

    protected $matchPreviewService;

    public function __construct(MatchPreviewService $matchPreviewService)
    {
        parent::__construct();
        $this->matchPreviewService = $matchPreviewService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $force = $this->option('force');

        $this->info("Generating match previews for {$date->format('Y-m-d')}...");

        // Get all matches for the specified date
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereDate('match_date', $date)
            ->where('status', '!=', 'FINISHED')
            ->orderBy('match_date')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn("No matches found for {$date->format('Y-m-d')}");
            return 0;
        }

        $this->info("Found {$matches->count()} matches to process");

        $bar = $this->output->createProgressBar($matches->count());
        $bar->start();

        $successCount = 0;
        $errorCount = 0;

        foreach ($matches as $match) {
            try {
                // Check if preview already exists and we're not forcing regeneration
                if (!$force && $match->preview && $match->preview->isRecent()) {
                    $this->line("\nSkipping match {$match->id} - preview already exists and is recent");
                    $bar->advance();
                    continue;
                }

                // Generate preview
                $preview = $this->matchPreviewService->generatePreview($match);
                
                if ($preview) {
                    $successCount++;
                    $this->line("\n✓ Generated preview for {$match->homeTeam->name} vs {$match->awayTeam->name}");
                } else {
                    $errorCount++;
                    $this->line("\n✗ Failed to generate preview for {$match->homeTeam->name} vs {$match->awayTeam->name}");
                }

            } catch (\Exception $e) {
                $errorCount++;
                $this->line("\n✗ Error generating preview for match {$match->id}: " . $e->getMessage());
                Log::error("Daily preview generation failed for match {$match->id}", [
                    'match_id' => $match->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Summary
        $this->info("Preview generation completed!");
        $this->info("✓ Successfully generated: {$successCount} previews");
        if ($errorCount > 0) {
            $this->warn("✗ Failed to generate: {$errorCount} previews");
        }

        // Show API usage
        $apiUsage = $this->matchPreviewService->getStats();
        $this->info("API Usage: {$apiUsage['api_usage']['daily_requests']} requests used today");

        return 0;
    }
}