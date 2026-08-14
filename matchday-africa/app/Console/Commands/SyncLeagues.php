<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LeagueService;
use App\Models\League;

class SyncLeagues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:leagues 
                           {--all : Sync all available competitions}
                           {--featured : Sync only featured/free tier competitions}
                           {--show : Show current leagues in database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync football leagues/competitions from external API';

    public function __construct(
        private LeagueService $leagueService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🏆 Starting leagues synchronization...');
        
        try {
            if ($this->option('show')) {
                return $this->showCurrentLeagues();
            }
            
            if ($this->option('featured')) {
                return $this->syncFeaturedLeagues();
            }
            
            if ($this->option('all')) {
                return $this->syncAllLeagues();
            }
            
            // Default: sync featured leagues
            return $this->syncFeaturedLeagues();
            
        } catch (\Exception $e) {
            $this->error('❌ Leagues sync failed: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            return Command::FAILURE;
        }
    }

    private function syncFeaturedLeagues(): int
    {
        $this->info('🌟 Syncing featured leagues (free tier)...');
        
        $result = $this->leagueService->syncFeaturedCompetitions();
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncAllLeagues(): int
    {
        $this->info('🌍 Syncing all available leagues...');
        $this->warn('⚠️  This will sync ALL competitions from the API');
        
        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Operation cancelled');
            return Command::SUCCESS;
        }
        
        $result = $this->leagueService->syncAllCompetitions();
        
        $this->displayResult($result);
        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function showCurrentLeagues(): int
    {
        $this->info('Current leagues in database:');
        $this->line('');
        
        $leagues = League::orderBy('is_featured', 'desc')
            ->orderBy('name')
            ->get(['id', 'name', 'country_name', 'football_data_id', 'is_active', 'is_featured']);
            
        if ($leagues->isEmpty()) {
            $this->warn('No leagues found in database. Run sync:leagues first.');
            return Command::SUCCESS;
        }
        
        $this->table(
            ['ID', 'League Name', 'Country', 'Football-Data ID', 'Active', 'Featured'],
            $leagues->map(function ($league) {
                return [
                    $league->id,
                    $league->name,
                    $league->country_name ?? 'N/A',
                    $league->football_data_id,
                    $league->is_active ? '✅' : '❌',
                    $league->is_featured ? '⭐' : '○'
                ];
            })->toArray()
        );
        
        $activeCount = $leagues->where('is_active', true)->count();
        $featuredCount = $leagues->where('is_featured', true)->count();
        
        $this->line('');
        $this->info("Total: {$leagues->count()} leagues | Active: {$activeCount} | Featured: {$featuredCount}");
        
        return Command::SUCCESS;
    }

    private function displayResult(array $result): void
    {
        if (isset($result['success']) && $result['success'] > 0) {
            $this->info("✅ Success: {$result['success']} leagues synced");
        }
        
        if (isset($result['errors']) && $result['errors'] > 0) {
            $this->warn("⚠️  Errors: {$result['errors']} failed");
        }
        
        if (isset($result['updated']) && $result['updated'] > 0) {
            $this->info("🔄 Updated: {$result['updated']} leagues updated");
        }
        
        if (isset($result['message'])) {
            $this->line("📝 {$result['message']}");
        }
        
        // Show quick stats
        $this->showQuickStats();
    }

    private function showQuickStats(): void
    {
        $total = League::count();
        $active = League::where('is_active', true)->count();
        $featured = League::where('is_featured', true)->count();
        
        $this->line('');
        $this->info("📊 Database totals: {$total} leagues | {$active} active | {$featured} featured");
    }
}