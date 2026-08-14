<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MatchService;
use Carbon\Carbon;

class SyncMatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:matches 
                           {--date= : Specific date to sync (YYYY-MM-DD format)}
                           {--today : Sync today\'s matches only}
                           {--upcoming= : Sync matches for the next number of days}
                           {--range= : Sync matches for date range (e.g., 7 for last 7 days)}
                           {--league= : Sync matches for specific league ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync football matches from external API';

    public function __construct(
        private MatchService $matchService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🏈 Starting match synchronization...');
        
        try {
            if ($this->option('today')) {
                return $this->syncTodaysMatches();
            }

            if ($this->option('upcoming')) {
                $days = max(1, min(14, (int) $this->option('upcoming')));
                $this->info("📅 Syncing the next {$days} days...");
                $result = $this->matchService->syncUpcomingMatches($days);
                $this->displayResult($result);
                return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
            }
            
            if ($this->option('date')) {
                return $this->syncMatchesByDate($this->option('date'));
            }
            
            if ($this->option('range')) {
                return $this->syncMatchesByRange((int) $this->option('range'));
            }
            
            if ($this->option('league')) {
                return $this->syncMatchesByLeague((int) $this->option('league'));
            }
            
            // Default: sync today's matches
            return $this->syncTodaysMatches();
            
        } catch (\Exception $e) {
            $this->error('❌ Match sync failed: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            return Command::FAILURE;
        }
    }

    private function syncTodaysMatches(): int
    {
        $this->info('📅 Syncing today\'s matches...');
        
        $result = $this->matchService->syncTodaysMatches();
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncMatchesByDate(string $date): int
    {
        try {
            $carbonDate = Carbon::createFromFormat('Y-m-d', $date);
            $this->info("📅 Syncing matches for {$carbonDate->format('Y-m-d')}...");
            
            $result = $this->matchService->syncMatchesByDate($date);
            
            $this->displayResult($result);
            return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Invalid date format. Use YYYY-MM-DD');
            return Command::FAILURE;
        }
    }

    private function syncMatchesByRange(int $days): int
    {
        $this->info("📅 Syncing matches for the last {$days} days...");
        
        $totalSuccess = 0;
        $totalErrors = 0;
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $this->line("   Syncing {$date}...");
            
            $result = $this->matchService->syncMatchesByDate($date);
            $totalSuccess += $result['success'];
            $totalErrors += $result['errors'];
            
            // Rate limiting
            if ($i > 0) {
                sleep(2);
            }
        }
        
        $this->displayResult([
            'success' => $totalSuccess,
            'errors' => $totalErrors,
            'message' => "Synced matches for {$days} days"
        ]);
        
        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncMatchesByLeague(int $leagueId): int
    {
        $this->info("🏆 Syncing matches for league ID {$leagueId}...");
        
        // Get the league's football_data_id
        $league = \App\Models\League::find($leagueId);
        if (!$league) {
            $this->error('❌ League not found');
            return Command::FAILURE;
        }
        
        $result = $this->matchService->syncMatchesForLeague($leagueId, $league->football_data_id);
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function displayResult(array $result): void
    {
        if ($result['success'] > 0) {
            $this->info("✅ Success: {$result['success']} matches synced");
        }
        
        if ($result['errors'] > 0) {
            $this->warn("⚠️  Errors: {$result['errors']} failed");
        }
        
        $this->line("📝 {$result['message']}");
    }
}
