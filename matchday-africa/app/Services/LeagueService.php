<?php

namespace App\Services;

use App\Models\League;
use App\Models\Standing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class LeagueService
{
    private FootballDataService $api;
    
    public function __construct(FootballDataService $api)
    {
        $this->api = $api;
    }
    
    /**
     * Fetch and store all competitions from API
     */
    public function syncAllCompetitions(): array
    {
        $results = ['success' => 0, 'errors' => 0, 'updated' => 0];
        
        try {
            $response = $this->api->getCompetitions();
            
            if (!$response || !isset($response['competitions'])) {
                Log::error('Failed to fetch competitions from football-data.org API');
                return $results;
            }
            
            foreach ($response['competitions'] as $competitionData) {
                try {
                    $this->createOrUpdateLeague($competitionData);
                    $results['success']++;
                } catch (\Exception $e) {
                    Log::error('Error syncing competition', [
                        'competition_id' => $competitionData['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $results['errors']++;
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error in syncAllCompetitions', ['error' => $e->getMessage()]);
            $results['errors']++;
        }
        
        return $results;
    }
    
    /**
     * Fetch and store featured competitions (free tier ones)
     */
    public function syncFeaturedCompetitions(): array
    {
        // These are the free tier competitions from football-data.org
        $featuredCompetitionCodes = [
            'PL',  // Premier League
            'PD',  // La Liga
            'BL1', // Bundesliga
            'SA',  // Serie A
            'FL1', // Ligue 1
            'DED', // Eredivisie
            'PPL', // Primeira Liga
            'ELC', // Championship
            'CL',  // Champions League
            'BSA', // Serie A Brazil
            'WC',  // World Cup
            'EC'   // European Championship
        ];
        
        $results = ['success' => 0, 'errors' => 0];
        
        foreach ($featuredCompetitionCodes as $code) {
            try {
                $league = $this->syncCompetitionByCode($code);
                if ($league) {
                    // Mark as featured
                    $league->update(['is_featured' => true, 'priority' => 1]);
                    $results['success']++;
                }
            } catch (\Exception $e) {
                Log::error('Error syncing featured competition', [
                    'competition_code' => $code,
                    'error' => $e->getMessage()
                ]);
                $results['errors']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Sync a specific competition by code
     */
    public function syncCompetitionByCode(string $competitionCode): ?League
    {
        try {
            $response = $this->api->get("competitions/{$competitionCode}");
            
            if (!$response) {
                return null;
            }
            
            return $this->createOrUpdateLeague($response);
            
        } catch (\Exception $e) {
            Log::error('Error syncing competition by code', [
                'competition_code' => $competitionCode,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Create or update league from API data
     */
    private function createOrUpdateLeague(array $competitionData): League
    {
        $area = $competitionData['area'] ?? null;
        
        return League::updateOrCreate(
            ['football_data_id' => $competitionData['id']],
            [
                'name' => $competitionData['name'],
                'short_code' => $competitionData['code'] ?? null,
                'type' => strtolower($competitionData['type'] ?? 'league'),
                'country_name' => $area['name'] ?? null,
                'country_code' => $area['code'] ?? null,
                'logo_url' => $competitionData['emblem'] ?? null,
                'is_active' => true, // football-data.org only returns active competitions
                'metadata' => $competitionData
            ]
        );
    }
    
    /**
     * Fetch and store standings for a competition
     */
    public function syncCompetitionStandings(string $competitionCode): array
    {
        $results = ['success' => 0, 'errors' => 0];
        
        try {
            $response = $this->api->getCompetitionStandings($competitionCode);
            
            if (!$response || !isset($response['standings'])) {
                return $results;
            }
            
            $league = League::where('short_code', $competitionCode)->first();
            if (!$league) {
                Log::error('League not found for standings sync', ['code' => $competitionCode]);
                return $results;
            }
            
            foreach ($response['standings'] as $standingGroup) {
                $groupName = $standingGroup['group'] ?? 'TOTAL';
                $season = $response['season']['startDate'] ?? date('Y');
                
                if (isset($standingGroup['table'])) {
                    foreach ($standingGroup['table'] as $standingData) {
                        try {
                            $this->createOrUpdateStanding($standingData, $league, $season, $groupName);
                            $results['success']++;
                        } catch (\Exception $e) {
                            Log::error('Error syncing standing', [
                                'league_id' => $league->id,
                                'team_id' => $standingData['team']['id'] ?? 'unknown',
                                'error' => $e->getMessage()
                            ]);
                            $results['errors']++;
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error in syncCompetitionStandings', [
                'competition_code' => $competitionCode,
                'error' => $e->getMessage()
            ]);
            $results['errors']++;
        }
        
        return $results;
    }
    
    /**
     * Create or update standing from API data
     */
    private function createOrUpdateStanding(array $standingData, League $league, string $season, string $groupName): Standing
    {
        $team = \App\Models\Team::where('football_data_id', $standingData['team']['id'])->first();
        
        if (!$team) {
            // Create the team if it doesn't exist
            $team = \App\Models\Team::create([
                'football_data_id' => $standingData['team']['id'],
                'name' => $standingData['team']['name'],
                'short_code' => $standingData['team']['tla'] ?? null,
                'logo_url' => $standingData['team']['crest'] ?? null,
                'is_active' => true,
                'metadata' => $standingData['team']
            ]);
        }
        
        return Standing::updateOrCreate(
            [
                'league_id' => $league->id,
                'team_id' => $team->id,
                'season' => substr($season, 0, 4),
                'calculation_date' => now()->toDateString()
            ],
            [
                'season_year' => (int) substr($season, 0, 4),
                'position' => $standingData['position'],
                'points' => $standingData['points'],
                'matches_played' => $standingData['playedGames'],
                'wins' => $standingData['won'],
                'draws' => $standingData['draw'],
                'losses' => $standingData['lost'],
                'goals_for' => $standingData['goalsFor'],
                'goals_against' => $standingData['goalsAgainst'],
                'goal_difference' => $standingData['goalDifference'],
                'recent_form' => $standingData['form'] ?? null,
                'qualification_zone' => $groupName,
                'is_current' => true,
                'last_api_update' => now(),
                'metadata' => $standingData
            ]
        );
    }
    
    /**
     * Get popular leagues for homepage
     */
    public function getPopularLeagues(int $limit = 6): Collection
    {
        return League::where('is_active', true)
            ->where('is_featured', true)
            ->orderBy('priority')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Search leagues by name or country
     */
    public function searchLeagues(string $query, int $limit = 10): Collection
    {
        return League::where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('country_name', 'like', "%{$query}%")
                  ->orWhere('short_code', 'like', "%{$query}%");
            })
            ->orderBy('is_featured', 'desc')
            ->orderBy('priority')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Sync all free tier competitions and their standings
     */
    public function syncFreeCompetitions(): array
    {
        $totalResults = ['success' => 0, 'errors' => 0];
        
        // First sync the competitions
        $competitionResults = $this->syncFeaturedCompetitions();
        $totalResults['success'] += $competitionResults['success'];
        $totalResults['errors'] += $competitionResults['errors'];
        
        // Then sync standings for each competition
        $featuredCodes = ['PL', 'PD', 'BL1', 'SA', 'FL1', 'DED', 'PPL', 'ELC'];
        
        foreach ($featuredCodes as $code) {
            $standingResults = $this->syncCompetitionStandings($code);
            $totalResults['success'] += $standingResults['success'];
            $totalResults['errors'] += $standingResults['errors'];
        }
        
        return $totalResults;
    }
}