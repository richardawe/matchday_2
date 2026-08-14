<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MatchPreviewService;
use App\Models\FootballMatch;
use Carbon\Carbon;

class GenerateMatchPreviews extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'previews:generate 
                            {--matches= : Number of matches to process (default: 10)}
                            {--force : Force regeneration of existing previews}
                            {--live : Include live matches}
                            {--today : Include today\'s matches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate AI-powered match previews for football matches';

    /**
     * Execute the console command.
     */
    public function handle(MatchPreviewService $previewService)
    {
        $this->info('🚀 Starting match preview generation...');

        try {
            // Get matches to process
            $matches = $this->getMatchesToProcess();
            
            if ($matches->isEmpty()) {
                $this->warn('No matches found to process.');
                return 0;
            }

            $this->info("Found {$matches->count()} matches to process.");

            // Show matches being processed
            $this->table(
                ['ID', 'Home Team', 'Away Team', 'League', 'Date', 'Status'],
                $matches->map(function ($match) {
                    return [
                        $match->id,
                        $match->homeTeam ? $match->homeTeam->name : 'N/A',
                        $match->awayTeam ? $match->awayTeam->name : 'N/A',
                        $match->league ? $match->league->name : 'N/A',
                        $match->match_date ? $match->match_date->format('M d, H:i') : 'N/A',
                        $match->status ?? 'SCHEDULED'
                    ];
                })
            );

            // Confirm before proceeding
            if (!$this->option('force') && !$this->confirm('Do you want to proceed with preview generation?')) {
                $this->info('Operation cancelled.');
                return 0;
            }

            // Generate previews
            $this->info('Generating previews...');
            $progressBar = $this->output->createProgressBar($matches->count());
            $progressBar->start();

            $results = [
                'success' => 0,
                'failed' => 0,
                'skipped' => 0
            ];

            foreach ($matches as $match) {
                try {
                    $preview = $previewService->generatePreview($match);
                    if ($preview) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $this->error("Failed to generate preview for match {$match->id}: " . $e->getMessage());
                }

                $progressBar->advance();
                
                // Add delay to avoid overwhelming the API
                usleep(500000); // 0.5 seconds
            }

            $progressBar->finish();
            $this->newLine();

            // Show results
            $this->info('✅ Preview generation completed!');
            $this->table(
                ['Status', 'Count'],
                [
                    ['Success', $results['success']],
                    ['Failed', $results['failed']],
                    ['Skipped', $results['skipped']]
                ]
            );

            // Show API usage
            $stats = $previewService->getStats();
            $this->info('📊 API Usage:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Previews', $stats['total_previews']],
                    ['Featured Previews', $stats['featured_previews']],
                    ['Recent Previews', $stats['recent_previews']],
                    ['Daily API Requests', $stats['api_usage']['daily_requests']],
                    ['Remaining Requests', $stats['api_usage']['remaining_requests']]
                ]
            );

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ An error occurred: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Get matches to process based on options
     */
    protected function getMatchesToProcess()
    {
        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id');

        $maxMatches = (int) $this->option('matches') ?: 10;

        // Filter by match status and date
        if ($this->option('live')) {
            $query->whereIn('status', ['LIVE', '1H', '2H', 'HT']);
        } elseif ($this->option('today')) {
            $query->whereDate('match_date', Carbon::today());
        } else {
            // Default: upcoming matches
            $query->where('match_date', '>=', now())
                  ->where('status', 'SCHEDULED');
        }

        // Exclude matches that already have recent previews (unless force option is used)
        if (!$this->option('force')) {
            $query->whereDoesntHave('preview', function ($q) {
                $q->where('generated_at', '>=', now()->subHours(6));
            });
        }

        return $query->orderBy('match_date', 'asc')
                    ->limit($maxMatches)
                    ->get();
    }
} 