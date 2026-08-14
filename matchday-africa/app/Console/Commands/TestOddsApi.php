<?php

namespace App\Console\Commands;

use App\Services\OddsApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestOddsApi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'odds:test 
                            {--connection : Test API connection only}
                            {--weekend : Test EPL weekend odds}
                            {--upcoming : Test upcoming EPL matches}
                            {--stats : Show usage statistics}
                            {--clear-cache : Clear all odds cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test The Odds API integration and show usage statistics';

    /**
     * Execute the console command.
     */
    public function handle(OddsApiService $oddsService): int
    {
        $this->info('🎯 Testing The Odds API Integration...');
        $this->newLine();

        try {
            // Test connection
            if ($this->option('connection') || !$this->option('weekend') && !$this->option('upcoming') && !$this->option('stats') && !$this->option('clear-cache')) {
                $this->testConnection($oddsService);
            }

            // Test EPL weekend odds
            if ($this->option('weekend')) {
                $this->testEplWeekendOdds($oddsService);
            }

            // Test upcoming EPL matches
            if ($this->option('upcoming')) {
                $this->testUpcomingEplMatches($oddsService);
            }

            // Show usage statistics
            if ($this->option('stats')) {
                $this->showUsageStats($oddsService);
            }

            // Clear cache
            if ($this->option('clear-cache')) {
                $this->clearCache($oddsService);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Command failed: ' . $e->getMessage());
            Log::error('TestOddsApi command failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function testConnection(OddsApiService $oddsService): void
    {
        $this->info('🔌 Testing API Connection...');
        
        $result = $oddsService->testConnection();
        
        if ($result['success']) {
            $this->info('✅ ' . $result['message']);
            $this->line('   📊 Total Sports Available: ' . $result['total_sports']);
            $this->line('   ⚽ EPL Available: ' . ($result['epl_available'] ? 'Yes' : 'No'));
            $this->line('   🔑 API Key Configured: ' . ($result['api_key_configured'] ? 'Yes' : 'No'));
            $this->line('   ⏱️  Cache Duration: ' . $result['cache_duration'] . ' seconds');
            $this->line('   📈 Max Requests/Min: ' . $result['max_requests_per_minute']);
            
            if ($result['epl_info']) {
                $this->line('   🏆 EPL Info: ' . $result['epl_info']['title']);
            }
        } else {
            $this->error('❌ ' . $result['message']);
            $this->line('   🔑 API Key Configured: ' . ($result['api_key_configured'] ? 'Yes' : 'No'));
        }
        
        $this->newLine();
    }

    private function testEplWeekendOdds(OddsApiService $oddsService): void
    {
        $this->info('📅 Testing EPL Weekend Odds...');
        
        $odds = $oddsService->getEplWeekendOdds();
        
        if ($odds && is_array($odds) && count($odds) > 0) {
            $this->info('✅ Successfully retrieved ' . count($odds) . ' EPL weekend matches');
            
            foreach (array_slice($odds, 0, 3) as $match) {
                $this->line('   🏆 ' . $match['home_team'] . ' vs ' . $match['away_team']);
                $this->line('      📅 ' . $match['commence_time']);
                $this->line('      🏟️  ' . ($match['sport_title'] ?? 'EPL'));
            }
            
            if (count($odds) > 3) {
                $this->line('   ... and ' . (count($odds) - 3) . ' more matches');
            }
        } else {
            $this->warn('⚠️  No EPL weekend odds found or API error');
        }
        
        $this->newLine();
    }

    private function testUpcomingEplMatches(OddsApiService $oddsService): void
    {
        $this->info('⏭️  Testing Upcoming EPL Matches...');
        
        $matches = $oddsService->getUpcomingEplMatches();
        
        if ($matches && is_array($matches) && count($matches) > 0) {
            $this->info('✅ Successfully retrieved ' . count($matches) . ' upcoming EPL matches');
            
            foreach (array_slice($matches, 0, 3) as $match) {
                $this->line('   🏆 ' . $match['home_team'] . ' vs ' . $match['away_team']);
                $this->line('      📅 ' . $match['commence_time']);
            }
            
            if (count($matches) > 3) {
                $this->line('   ... and ' . (count($matches) - 3) . ' more matches');
            }
        } else {
            $this->warn('⚠️  No upcoming EPL matches found or API error');
        }
        
        $this->newLine();
    }

    private function showUsageStats(OddsApiService $oddsService): void
    {
        $this->info('📊 API Usage Statistics...');
        
        $stats = $oddsService->getUsageStats();
        
        $this->line('   🔢 Current Requests This Minute: ' . $stats['current_requests_this_minute']);
        $this->line('   📈 Max Requests Per Minute: ' . $stats['max_requests_per_minute']);
        $this->line('   ⏳ Remaining Requests: ' . $stats['remaining_requests']);
        $this->line('   ⏱️  Cache Duration: ' . $stats['cache_duration'] . ' seconds');
        $this->line('   🔑 API Key Configured: ' . ($stats['api_key_configured'] ? 'Yes' : 'No'));
        $this->line('   🌐 Base URL: ' . $stats['base_url']);
        $this->line('   🌍 Regions: ' . $stats['regions']);
        $this->line('   🎯 Markets: ' . $stats['markets']);
        
        $this->newLine();
    }

    private function clearCache(OddsApiService $oddsService): void
    {
        $this->info('🗑️  Clearing Odds API Cache...');
        
        $success = $oddsService->clearCache();
        
        if ($success) {
            $this->info('✅ Cache cleared successfully');
        } else {
            $this->error('❌ Failed to clear cache');
        }
        
        $this->newLine();
    }
}
