<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PlayerService;
use App\Models\Team;
use App\Models\League;

class SyncPlayers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:players 
                           {--team= : Sync players for specific team ID}
                           {--league= : Sync players for all teams in a specific league}
                           {--all : Sync players for all active teams}
                           {--football-data-id= : Sync by football-data.org team ID}
                           {--limit= : Limit number of teams to process (useful for testing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync player squads from external API';

    public function __construct(
        private PlayerService $playerService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('👥 Starting player synchronization...');
        
        try {
            if ($this->option('team')) {
                return $this->syncPlayersByTeamId((int) $this->option('team'));
            }
            
            if ($this->option('football-data-id')) {
                return $this->syncPlayersByFootballDataId((int) $this->option('football-data-id'));
            }
            
            if ($this->option('league')) {
                return $this->syncPlayersByLeague((int) $this->option('league'));
            }
            
            if ($this->option('all')) {
                return $this->syncAllPlayers();
            }
            
            // Default: show available options
            $this->showAvailableTeams();
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Players sync failed: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            return Command::FAILURE;
        }
    }

    private function syncPlayersByTeamId(int $teamId): int
    {
        $team = Team::find($teamId);
        if (!$team) {
            $this->error('❌ Team not found');
            return Command::FAILURE;
        }
        
        $this->info("👥 Syncing players for {$team->name}...");
        
        $result = $this->playerService->syncTeamPlayers($team->football_data_id);
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncPlayersByFootballDataId(int $footballDataId): int
    {
        $team = Team::where('football_data_id', $footballDataId)->first();
        if (!$team) {
            $this->error('❌ Team not found with football-data ID: ' . $footballDataId);
            return Command::FAILURE;
        }
        
        $this->info("👥 Syncing players for {$team->name} (ID: {$footballDataId})...");
        
        $result = $this->playerService->syncTeamPlayers($footballDataId);
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncPlayersByLeague(int $leagueId): int
    {
        $league = League::find($leagueId);
        if (!$league) {
            $this->error('❌ League not found');
            return Command::FAILURE;
        }
        
        $teams = Team::where('league_id', $leagueId)
            ->where('is_active', true)
            ->whereNotNull('football_data_id')
            ->get();
            
        if ($teams->isEmpty()) {
            $this->warn('❌ No active teams found for this league');
            return Command::FAILURE;
        }
        
        $this->info("👥 Syncing players for {$teams->count()} teams in {$league->name}...");
        $this->warn('⚠️  This may take several minutes due to API rate limiting');
        
        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Operation cancelled');
            return Command::SUCCESS;
        }
        
        return $this->processTeams($teams);
    }

    private function syncAllPlayers(): int
    {
        $teams = Team::where('is_active', true)
            ->whereNotNull('football_data_id')
            ->orderBy('name')
            ->get();
            
        if ($teams->isEmpty()) {
            $this->warn('❌ No active teams found');
            return Command::FAILURE;
        }
        
        $limit = $this->option('limit') ? (int) $this->option('limit') : $teams->count();
        $teams = $teams->take($limit);
        
        $this->info("👥 Syncing players for {$teams->count()} teams...");
        $this->warn('⚠️  This may take a very long time due to API rate limiting');
        
        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Operation cancelled');
            return Command::SUCCESS;
        }
        
        return $this->processTeams($teams);
    }

    private function processTeams($teams): int
    {
        $totalSuccess = 0;
        $totalErrors = 0;
        $processedCount = 0;
        
        $progressBar = $this->output->createProgressBar($teams->count());
        $progressBar->start();
        
        foreach ($teams as $team) {
            $this->line("\n👥 Processing {$team->name}...");
            
            $result = $this->playerService->syncTeamPlayers($team->football_data_id);
            
            $totalSuccess += $result['success'];
            $totalErrors += $result['errors'];
            $processedCount++;
            
            $this->line("   ✅ {$result['success']} players, ❌ {$result['errors']} errors");
            
            $progressBar->advance();
            
            // Rate limiting - 10 calls per minute on free plan
            if ($processedCount % 10 == 0) {
                $this->line("\n⏳ Rate limit pause (10 calls reached)... waiting 70 seconds");
                sleep(70);
            } else {
                sleep(7); // 7 seconds between calls
            }
        }
        
        $progressBar->finish();
        
        $finalResult = [
            'success' => $totalSuccess,
            'errors' => $totalErrors,
            'message' => "Processed {$processedCount} teams: {$totalSuccess} players with {$totalErrors} errors"
        ];
        
        $this->line('');
        $this->displayResult($finalResult);
        
        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function showAvailableTeams(): void
    {
        $this->info('Available teams for player sync:');
        $this->line('');
        
        $teams = Team::where('is_active', true)
            ->whereNotNull('football_data_id')
            ->with('league:id,name')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'football_data_id', 'league_id']);
            
        if ($teams->isEmpty()) {
            $this->warn('No active teams found. Run sync:leagues and sync:teams first.');
            return;
        }
        
        $this->table(
            ['ID', 'Team Name', 'League', 'Football-Data ID'],
            $teams->map(function ($team) {
                return [
                    $team->id,
                    $team->name,
                    $team->league->name ?? 'N/A',
                    $team->football_data_id
                ];
            })->toArray()
        );
        
        $totalTeams = Team::where('is_active', true)->whereNotNull('football_data_id')->count();
        
        if ($totalTeams > 20) {
            $this->line("... and " . ($totalTeams - 20) . " more teams");
        }
        
        $this->line('');
        $this->info('Usage examples:');
        $this->line('  php artisan sync:players --team=1');
        $this->line('  php artisan sync:players --league=1');
        $this->line('  php artisan sync:players --all --limit=5');
    }

    private function displayResult(array $result): void
    {
        if ($result['success'] > 0) {
            $this->info("✅ Success: {$result['success']} players synced");
        }
        
        if ($result['errors'] > 0) {
            $this->warn("⚠️  Errors: {$result['errors']} failed");
        }
        
        $this->line("📝 {$result['message']}");
    }
}