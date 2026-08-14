<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StandingService;
use App\Models\League;

class SyncStandings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:standings 
                           {--league= : Sync standings for specific league ID}
                           {--all : Sync standings for all active leagues}
                           {--football-data-id= : Sync by football-data.org league ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync league standings from external API';

    public function __construct(
        private StandingService $standingService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🏆 Starting standings synchronization...');
        
        try {
            if ($this->option('all')) {
                return $this->syncAllStandings();
            }
            
            if ($this->option('league')) {
                return $this->syncStandingsByLeagueId((int) $this->option('league'));
            }
            
            if ($this->option('football-data-id')) {
                return $this->syncStandingsByFootballDataId((int) $this->option('football-data-id'));
            }
            
            // Default: show available options
            $this->showAvailableLeagues();
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Standings sync failed: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            return Command::FAILURE;
        }
    }

    private function syncAllStandings(): int
    {
        $this->info('📊 Syncing standings for all active leagues...');
        $this->warn('⚠️  This may take several minutes due to API rate limiting');
        
        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Operation cancelled');
            return Command::SUCCESS;
        }
        
        $result = $this->standingService->syncAllLeagueStandings();
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncStandingsByLeagueId(int $leagueId): int
    {
        $league = League::find($leagueId);
        if (!$league) {
            $this->error('❌ League not found');
            return Command::FAILURE;
        }
        
        $this->info("🏆 Syncing standings for {$league->name}...");
        
        $result = $this->standingService->syncLeagueStandings($league->football_data_id);
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncStandingsByFootballDataId(int $footballDataId): int
    {
        $league = League::where('football_data_id', $footballDataId)->first();
        if (!$league) {
            $this->error('❌ League not found with football-data ID: ' . $footballDataId);
            return Command::FAILURE;
        }
        
        $this->info("🏆 Syncing standings for {$league->name} (ID: {$footballDataId})...");
        
        $result = $this->standingService->syncLeagueStandings($footballDataId);
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function showAvailableLeagues(): void
    {
        $this->info('Available leagues for standings sync:');
        $this->line('');
        
        $leagues = League::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'football_data_id', 'country_name']);
            
        if ($leagues->isEmpty()) {
            $this->warn('No active leagues found. Run sync:leagues first.');
            return;
        }
        
        $this->table(
            ['ID', 'League Name', 'Country', 'Football-Data ID'],
            $leagues->map(function ($league) {
                return [
                    $league->id,
                    $league->name,
                    $league->country_name ?? 'N/A',
                    $league->football_data_id
                ];
            })->toArray()
        );
        
        $this->line('');
        $this->info('Usage examples:');
        $this->line('  php artisan sync:standings --league=1');
        $this->line('  php artisan sync:standings --football-data-id=2021');
        $this->line('  php artisan sync:standings --all');
    }

    private function displayResult(array $result): void
    {
        if ($result['success'] > 0) {
            $this->info("✅ Success: {$result['success']} standings synced");
        }
        
        if ($result['errors'] > 0) {
            $this->warn("⚠️  Errors: {$result['errors']} failed");
        }
        
        $this->line("📝 {$result['message']}");
    }
}