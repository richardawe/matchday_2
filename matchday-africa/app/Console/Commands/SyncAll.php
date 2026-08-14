<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:all 
                           {--quick : Run quick sync (leagues + today\'s matches + standings)}
                           {--full : Run full sync (everything including players)}
                           {--leagues-only : Sync only leagues and competitions}
                           {--matches-only : Sync only matches (today + recent)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run complete football data synchronization';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Starting complete football data synchronization...');
        $this->line('');
        
        try {
            if ($this->option('quick')) {
                return $this->runQuickSync();
            }
            
            if ($this->option('full')) {
                return $this->runFullSync();
            }
            
            if ($this->option('leagues-only')) {
                return $this->runLeaguesOnly();
            }
            
            if ($this->option('matches-only')) {
                return $this->runMatchesOnly();
            }
            
            // Default: show options
            return $this->showSyncOptions();
            
        } catch (\Exception $e) {
            $this->error('❌ Complete sync failed: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            return Command::FAILURE;
        }
    }

    private function runQuickSync(): int
    {
        $this->alert('🏃 QUICK SYNC - Essential data only');
        $this->warn('⏱️  Estimated time: 2-5 minutes');
        
        if (!$this->confirm('Continue with quick sync?')) {
            return Command::SUCCESS;
        }
        
        $totalErrors = 0;
        
        // Step 1: Sync leagues
        $this->info('📍 Step 1/3: Syncing featured leagues...');
        $result = $this->call('sync:leagues', ['--featured' => true]);
        $totalErrors += $result;
        
        // Step 2: Sync today's matches
        $this->info('📍 Step 2/3: Syncing today\'s matches...');
        $result = $this->call('sync:matches', ['--today' => true]);
        $totalErrors += $result;
        
        // Step 3: Sync standings for active leagues
        $this->info('📍 Step 3/3: Syncing league standings...');
        $result = $this->call('sync:standings', ['--all' => true]);
        $totalErrors += $result;
        
        $this->line('');
        if ($totalErrors === 0) {
            $this->info('✅ Quick sync completed successfully!');
        } else {
            $this->warn("⚠️  Quick sync completed with {$totalErrors} errors");
        }
        
        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runFullSync(): int
    {
        $this->alert('🐘 FULL SYNC - Complete data synchronization');
        $this->warn('⏱️  Estimated time: 30-60 minutes (due to API rate limits)');
        $this->warn('⚠️  This will sync ALL data including player squads');
        
        if (!$this->confirm('Continue with full sync? This will take a long time.')) {
            return Command::SUCCESS;
        }
        
        $totalErrors = 0;
        
        // Step 1: Sync all leagues
        $this->info('📍 Step 1/5: Syncing all leagues...');
        $result = $this->call('sync:leagues', ['--all' => true]);
        $totalErrors += $result;
        
        // Step 2: Sync recent matches (last 7 days)
        $this->info('📍 Step 2/5: Syncing recent matches...');
        $result = $this->call('sync:matches', ['--range' => '7']);
        $totalErrors += $result;
        
        // Step 3: Sync standings
        $this->info('📍 Step 3/5: Syncing league standings...');
        $result = $this->call('sync:standings', ['--all' => true]);
        $totalErrors += $result;
        
        // Step 4: Sync players (limited to prevent timeout)
        $this->info('📍 Step 4/5: Syncing player squads (limited)...');
        $result = $this->call('sync:players', ['--all' => true, '--limit' => '20']);
        $totalErrors += $result;
        
        // Step 5: Final status
        $this->info('📍 Step 5/5: Generating final report...');
        $this->call('sync:leagues', ['--show' => true]);
        
        $this->line('');
        if ($totalErrors === 0) {
            $this->info('✅ Full sync completed successfully!');
        } else {
            $this->warn("⚠️  Full sync completed with {$totalErrors} errors");
        }
        
        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runLeaguesOnly(): int
    {
        $this->alert('🏆 LEAGUES SYNC - Competitions and standings only');
        
        $totalErrors = 0;
        
        // Sync featured leagues
        $this->info('📍 Step 1/2: Syncing featured leagues...');
        $result = $this->call('sync:leagues', ['--featured' => true]);
        $totalErrors += $result;
        
        // Sync standings
        $this->info('📍 Step 2/2: Syncing league standings...');
        $result = $this->call('sync:standings', ['--all' => true]);
        $totalErrors += $result;
        
        $this->line('');
        if ($totalErrors === 0) {
            $this->info('✅ Leagues sync completed successfully!');
        } else {
            $this->warn("⚠️  Leagues sync completed with {$totalErrors} errors");
        }
        
        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runMatchesOnly(): int
    {
        $this->alert('⚽ MATCHES SYNC - Recent and today\'s matches');
        
        $totalErrors = 0;
        
        // Today's matches
        $this->info('📍 Step 1/2: Syncing today\'s matches...');
        $result = $this->call('sync:matches', ['--today' => true]);
        $totalErrors += $result;
        
        // Recent matches (last 3 days)
        $this->info('📍 Step 2/2: Syncing recent matches...');
        $result = $this->call('sync:matches', ['--range' => '3']);
        $totalErrors += $result;
        
        $this->line('');
        if ($totalErrors === 0) {
            $this->info('✅ Matches sync completed successfully!');
        } else {
            $this->warn("⚠️  Matches sync completed with {$totalErrors} errors");
        }
        
        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function showSyncOptions(): int
    {
        $this->info('🚀 Football Data Synchronization Options');
        $this->line('');
        
        $this->table(
            ['Option', 'Description', 'Time Est.', 'Includes'],
            [
                ['--quick', 'Essential data only', '2-5 min', 'Featured leagues, today\'s matches, standings'],
                ['--full', 'Complete synchronization', '30-60 min', 'All leagues, recent matches, standings, players'],
                ['--leagues-only', 'Leagues and standings', '5-10 min', 'Featured leagues, all standings'],
                ['--matches-only', 'Recent matches only', '1-3 min', 'Today + last 3 days matches'],
            ]
        );
        
        $this->line('');
        $this->info('Individual sync commands:');
        $this->line('  sync:leagues   - Sync competitions/leagues');
        $this->line('  sync:matches   - Sync football matches');
        $this->line('  sync:standings - Sync league tables');
        $this->line('  sync:players   - Sync player squads');
        
        $this->line('');
        $this->info('Usage examples:');
        $this->line('  php artisan sync:all --quick');
        $this->line('  php artisan sync:all --full');
        $this->line('  php artisan sync:all --matches-only');
        
        return Command::SUCCESS;
    }
}