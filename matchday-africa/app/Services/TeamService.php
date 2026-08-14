<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class TeamService
{
    private SportmonksService $api;
    
    public function __construct(SportmonksService $api)
    {
        $this->api = $api;
    }
    
    /**
     * Sync teams for a specific league/season
     */
    public function syncTeamsByLeague(int $leagueId, string $season = null): array
    {
        $results = ['success' => 0, 'errors' => 0];
        
        try {
            $params = ['include' => 'country,venue'];
            if ($season) {
                $params['seasons'] = $season;
            }
            
            $response = $this->api->get("teams/seasons/{$leagueId}", $params, 3600); // Cache for 1 hour
            
            if (!$response || !isset($response['data'])) {
                Log::warning('No teams data received for league', ['league_id' => $leagueId]);
                return $results;
            }
            
            foreach ($response['data'] as $teamData) {
                try {
                    $this->createOrUpdateTeam($teamData);
                    $results['success']++;
                } catch (\Exception $e) {
                    Log::error('Error syncing team', [
                        'team_id' => $teamData['id'] ?? 'unknown',
                        'league_id' => $leagueId,
                        'error' => $e->getMessage()
                    ]);
                    $results['errors']++;
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error in syncTeamsByLeague', [
                'league_id' => $leagueId,
                'error' => $e->getMessage()
            ]);
            $results['errors']++;
        }
        
        return $results;
    }
    
    /**
     * Sync a specific team by ID
     */
    public function syncTeamById(int $teamId): ?Team
    {
        try {
            $response = $this->api->get("teams/{$teamId}", ['include' => 'country,venue'], 1800); // Cache for 30 minutes
            
            if (!$response || !isset($response['data'])) {
                return null;
            }
            
            return $this->createOrUpdateTeam($response['data']);
            
        } catch (\Exception $e) {
            Log::error('Error syncing team by ID', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Sync popular/featured teams (for major leagues)
     */
    public function syncFeaturedTeams(): array
    {
        $featuredLeagueIds = [8, 11, 12, 17, 22]; // Premier League, La Liga, Bundesliga, Serie A, Ligue 1
        $results = ['success' => 0, 'errors' => 0];
        
        foreach ($featuredLeagueIds as $leagueId) {
            $leagueResults = $this->syncTeamsByLeague($leagueId);
            $results['success'] += $leagueResults['success'];
            $results['errors'] += $leagueResults['errors'];
        }
        
        return $results;
    }
    
    /**
     * Create or update team from API data
     */
    private function createOrUpdateTeam(array $teamData): Team
    {
        $country = $teamData['country'] ?? null;
        $venue = $teamData['venue'] ?? null;
        
        return Team::updateOrCreate(
            ['sportmonks_id' => $teamData['id']],
            [
                'name' => $teamData['name'],
                'short_code' => $teamData['short_code'] ?? null,
                'common_name' => $teamData['common_name'] ?? $teamData['name'],
                'country_name' => $country['name'] ?? null,
                'country_code' => $country['code'] ?? null,
                'logo_url' => $teamData['image_path'] ?? null,
                'venue_name' => $venue['name'] ?? null,
                'venue_city' => $venue['city_name'] ?? null,
                'founded_year' => $teamData['founded'] ?? null,
                'is_active' => true,
                'is_national_team' => $teamData['national_team'] ?? false,
                'metadata' => $teamData
            ]
        );
    }
    
    /**
     * Get team with recent matches and statistics
     */
    public function getTeamWithRecentMatches(int $teamId, int $matchLimit = 5): ?array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return null;
        }
        
        try {
            // Get recent matches from API
            $response = $this->api->get("teams/{$team->sportmonks_id}/matches", [
                'include' => 'scores,statistics',
                'limit' => $matchLimit
            ], 900); // Cache for 15 minutes
            
            if (!$response || !isset($response['data'])) {
                return ['team' => $team, 'recent_matches' => []];
            }
            
            return [
                'team' => $team,
                'recent_matches' => $response['data']
            ];
            
        } catch (\Exception $e) {
            Log::error('Error fetching team recent matches', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);
            
            return ['team' => $team, 'recent_matches' => []];
        }
    }
    
    /**
     * Get team statistics for current season
     */
    public function getTeamSeasonStats(int $teamId, string $season = null): ?array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return null;
        }
        
        try {
            $params = ['include' => 'statistics'];
            if ($season) {
                $params['seasons'] = $season;
            }
            
            $response = $this->api->get("teams/{$team->sportmonks_id}/statistics", $params, 1800); // Cache for 30 minutes
            
            if (!$response || !isset($response['data'])) {
                return null;
            }
            
            return $response['data'];
            
        } catch (\Exception $e) {
            Log::error('Error fetching team season stats', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Search teams by name or country
     */
    public function searchTeams(string $query, int $limit = 10): Collection
    {
        return Team::where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('common_name', 'like', "%{$query}%")
                  ->orWhere('short_code', 'like', "%{$query}%")
                  ->orWhere('country_name', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Get teams by country
     */
    public function getTeamsByCountry(string $countryCode, int $limit = 20): Collection
    {
        return Team::where('is_active', true)
            ->where('country_code', $countryCode)
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Get rival teams (if data available)
     */
    public function getTeamRivals(int $teamId): array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return [];
        }
        
        try {
            $response = $this->api->get("teams/{$team->sportmonks_id}/rivals", [], 86400); // Cache for 24 hours
            
            if (!$response || !isset($response['data'])) {
                return [];
            }
            
            return $response['data'];
            
        } catch (\Exception $e) {
            Log::error('Error fetching team rivals', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Get popular teams based on user activity
     */
    public function getPopularTeams(int $limit = 10): Collection
    {
        // For now, return teams from featured leagues
        // Later, this can be enhanced with user favorites/activity data
        return Team::whereHas('userFavorites')
            ->where('is_active', true)
            ->withCount('userFavorites')
            ->orderBy('user_favorites_count', 'desc')
            ->limit($limit)
            ->get();
    }
}