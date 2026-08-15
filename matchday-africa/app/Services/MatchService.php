<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\Team;
use App\Models\League;
use App\Models\MatchEvent;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class MatchService
{
    public function __construct(
        private FootballDataService $footballDataService
    ) {}

    public function syncTodaysMatches(): array
    {
        return $this->syncMatchesByDate(now()->toDateString());
    }

    public function syncLiveMatches(): array
    {
        try {
            $response = $this->footballDataService->get('matches', ['status' => 'LIVE']);
            if (!$response || !isset($response['matches'])) {
                return ['success' => 0, 'errors' => 1, 'message' => 'No live response from API'];
            }
            $success = 0; $errors = 0;
            foreach ($response['matches'] as $matchData) {
                try {
                    $league = $this->findOrCreateLeague($matchData['competition']);
                    $this->createOrUpdateMatch($matchData, $league->id, $league->football_data_id);
                    $success++;
                } catch (Exception $e) { $errors++; Log::error('Live match sync failed', ['error'=>$e->getMessage()]); }
            }
            return ['success'=>$success,'errors'=>$errors,'message'=>"Refreshed {$success} live matches"];
        } catch (Exception $e) {
            return ['success'=>0,'errors'=>1,'message'=>$e->getMessage()];
        }
    }

    public function syncUpcomingMatches(int $days = 7): array
    {
        try {
            $response = $this->footballDataService->get('matches', [
                'dateFrom' => now()->toDateString(),
                'dateTo' => now()->addDays($days)->toDateString(),
            ]);

            if (!$response || !isset($response['matches'])) {
                return ['success' => 0, 'errors' => 1, 'message' => 'No response from API'];
            }

            $successCount = 0;
            $errorCount = 0;
            foreach ($response['matches'] as $matchData) {
                try {
                    $league = $this->findOrCreateLeague($matchData['competition']);
                    $this->createOrUpdateMatch($matchData, $league->id, $league->football_data_id);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    Log::error('Failed to sync upcoming match: '.$e->getMessage());
                }
            }

            return ['success' => $successCount, 'errors' => $errorCount, 'message' => "Synced {$successCount} matches for the next {$days} days"];
        } catch (Exception $e) {
            Log::error('Upcoming match sync failed: '.$e->getMessage());
            return ['success' => 0, 'errors' => 1, 'message' => $e->getMessage()];
        }
    }

    public function syncMatchesByDate(string $date): array
    {
        try {
            $response = $this->footballDataService->get('matches', [
                'date' => $date
            ]);

            if (!$response || !isset($response['matches'])) {
                return ['success' => 0, 'errors' => 1, 'message' => 'No response from API'];
            }

            $successCount = 0;
            $errorCount = 0;

            foreach ($response['matches'] as $matchData) {
                try {
                    $league = $this->findOrCreateLeague($matchData['competition']);
                    $this->createOrUpdateMatch($matchData, $league->id, $league->football_data_id);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    Log::error('Failed to sync match: ' . $e->getMessage());
                }
            }

            return [
                'success' => $successCount,
                'errors' => $errorCount,
                'message' => "Synced {$successCount} matches for {$date}"
            ];
        } catch (Exception $e) {
            Log::error('Match sync failed: ' . $e->getMessage());
            return ['success' => 0, 'errors' => 1, 'message' => $e->getMessage()];
        }
    }

    public function syncLeagueMatches(string $leagueCode, array $params = []): array
    {
        try {
            $response = $this->footballDataService->getCompetitionMatches($leagueCode, $params);
            
            if (!$response || !isset($response['matches'])) {
                return ['success' => 0, 'errors' => 1, 'message' => 'No response from API'];
            }

            $league = League::where('football_data_id', $leagueCode)->first();
            if (!$league) {
                return ['success' => 0, 'errors' => 1, 'message' => 'League not found'];
            }

            $successCount = 0;
            $errorCount = 0;

            foreach ($response['matches'] as $matchData) {
                try {
                    $this->createOrUpdateMatch($matchData, $league->id, $league->football_data_id);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    Log::error('Failed to sync league match: ' . $e->getMessage());
                }
            }

            return [
                'success' => $successCount,
                'errors' => $errorCount,
                'message' => "Synced {$successCount} matches for league {$league->name}"
            ];
        } catch (Exception $e) {
            Log::error('League match sync failed: ' . $e->getMessage());
            return ['success' => 0, 'errors' => 1, 'message' => $e->getMessage()];
        }
    }

    public function createOrUpdateMatch(array $matchData, int $leagueId, int $leagueFootballDataId): FootballMatch
    {
        $homeTeam = $this->findOrCreateTeam($matchData['homeTeam'], $leagueId);
        $awayTeam = $this->findOrCreateTeam($matchData['awayTeam'], $leagueId);

        $matchDate = Carbon::parse($matchData['utcDate']);

        $homeScore = $matchData['score']['fullTime']['home'] ?? null;
        $awayScore = $matchData['score']['fullTime']['away'] ?? null;
        $homeScoreHT = $matchData['score']['halfTime']['home'] ?? null;
        $awayScoreHT = $matchData['score']['halfTime']['away'] ?? null;

        // Create or update the match
        $match = FootballMatch::updateOrCreate(
            ['football_data_id' => $matchData['id']],
            [
                'league_id' => $leagueId,
                'league_football_data_id' => $leagueFootballDataId,
                'home_team_id' => $homeTeam->id,
                'home_team_football_data_id' => $homeTeam->football_data_id,
                'away_team_id' => $awayTeam->id,
                'away_team_football_data_id' => $awayTeam->football_data_id,
                'match_date' => $matchDate,
                'status' => $matchData['status'] ?? 'SCHEDULED',
                'minute' => $matchData['minute'] ?? null,
                'period' => $matchData['period'] ?? null,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'home_score_ht' => $homeScoreHT,
                'away_score_ht' => $awayScoreHT,
                'venue_name' => $matchData['venue'] ?? null,
                'venue_city' => $matchData['venue'] ?? null,
                'referee_name' => $matchData['referees'][0]['name'] ?? null,
                'attendance' => $matchData['attendance'] ?? null,
                'weather' => $matchData['weather'] ?? null,
                'temperature' => $matchData['temperature'] ?? null,
                'is_featured' => false,
                'has_live_coverage' => $matchData['live'] ?? false,
                'last_api_update' => now(),
                'metadata' => json_encode($matchData)
            ]
        );

        // Sync match events (goals, cards, etc.) if match is completed or live
        if (in_array($match->status, ['FINISHED', 'LIVE', '1H', '2H', 'HT', 'ET', 'PENALTY_SHOOTOUT'])) {
            $this->syncMatchEvents($match->football_data_id, $match->id);
        }

        return $match;
    }

    /**
     * Sync match events (goals, cards, assists, etc.) for a specific match
     * Uses match details since events endpoint isn't available in TIER_ONE
     */
    public function syncMatchEvents(int $footballDataId, int $matchId): void
    {
        try {
            // Get match details instead of events (TIER_ONE compatible)
            $matchDetails = $this->footballDataService->getMatchDetails($footballDataId);
            
            if (!$matchDetails) {
                Log::info("No match details found for match {$matchId}");
                return;
            }

            $eventsCreated = 0;

            // Extract goals from match details
            if (isset($matchDetails['goals'])) {
                foreach ($matchDetails['goals'] as $goalData) {
                    $this->createGoalEvent($goalData, $matchId, $matchDetails);
                    $eventsCreated++;
                }
            }

            // Extract bookings (cards) from match details
            if (isset($matchDetails['bookings'])) {
                foreach ($matchDetails['bookings'] as $bookingData) {
                    $this->createBookingEvent($bookingData, $matchId, $matchDetails);
                    $eventsCreated++;
                }
            }

            // Extract substitutions from match details
            if (isset($matchDetails['substitutions'])) {
                foreach ($matchDetails['substitutions'] as $subData) {
                    $this->createSubstitutionEvent($subData, $matchId, $matchDetails);
                    $eventsCreated++;
                }
            }

            if ($eventsCreated > 0) {
                Log::info("Synced {$eventsCreated} events for match {$matchId}");
            } else {
                Log::info("No events found for match {$matchId}");
            }
            
        } catch (Exception $e) {
            Log::error("Failed to sync events for match {$matchId}: " . $e->getMessage());
        }
    }

    /**
     * Create a goal event from match details
     */
    private function createGoalEvent(array $goalData, int $matchId, array $matchDetails): void
    {
        try {
            // Determine team ID
            $teamId = null;
            if (isset($goalData['team']['id'])) {
                $team = Team::where('football_data_id', $goalData['team']['id'])->first();
                $teamId = $team ? $team->id : null;
            }

            // Determine event type
            $eventType = 'goal';
            if (isset($goalData['penalty']) && $goalData['penalty']) {
                $eventType = 'penalty_goal';
            }
            if (isset($goalData['ownGoal']) && $goalData['ownGoal']) {
                $eventType = 'own_goal';
            }

            // Create unique identifier for the event
            $eventId = $goalData['id'] ?? uniqid('goal_' . $matchId . '_');

            MatchEvent::updateOrCreate(
                [
                    'match_id' => $matchId,
                    'type' => $eventType,
                    'player_name' => $goalData['scorer']['name'] ?? null,
                    'minute' => $goalData['minute'] ?? null
                ],
                [
                    'football_data_id' => $eventId,
                    'match_football_data_id' => $matchDetails['id'] ?? null,
                    'team_id' => $teamId,
                    'team_football_data_id' => $goalData['team']['id'] ?? null,
                    'sub_type' => $eventType,
                    'period' => $goalData['period'] ?? null,
                    'player_sportmonks_id' => $goalData['scorer']['id'] ?? null,
                    'assist_player_name' => $goalData['assist']['name'] ?? null,
                    'assist_player_sportmonks_id' => $goalData['assist']['id'] ?? null,
                    'related_player_name' => null,
                    'related_player_sportmonks_id' => null,
                    'reason' => null,
                    'description' => "Goal scored by " . ($goalData['scorer']['name'] ?? 'Unknown'),
                    'is_own_goal' => $goalData['ownGoal'] ?? false,
                    'is_penalty' => $goalData['penalty'] ?? false,
                    'x_coordinate' => null,
                    'y_coordinate' => null,
                    'sort_order' => $goalData['minute'] ?? 0,
                    'metadata' => json_encode($goalData)
                ]
            );
            
        } catch (Exception $e) {
            Log::error("Failed to create goal event: " . $e->getMessage(), [
                'goal_data' => $goalData,
                'match_id' => $matchId
            ]);
        }
    }

    /**
     * Create a booking event from match details
     */
    private function createBookingEvent(array $bookingData, int $matchId, array $matchDetails): void
    {
        try {
            // Determine team ID
            $teamId = null;
            if (isset($bookingData['team']['id'])) {
                $team = Team::where('football_data_id', $bookingData['team']['id'])->first();
                $teamId = $team ? $team->id : null;
            }

            // Create unique identifier for the event
            $eventId = $bookingData['id'] ?? uniqid('card_' . $matchId . '_');

            MatchEvent::updateOrCreate(
                [
                    'match_id' => $matchId,
                    'type' => 'card',
                    'player_name' => $bookingData['player']['name'] ?? null,
                    'minute' => $bookingData['minute'] ?? null
                ],
                [
                    'football_data_id' => $eventId,
                    'match_football_data_id' => $matchDetails['id'] ?? null,
                    'team_id' => $teamId,
                    'team_football_data_id' => $bookingData['team']['id'] ?? null,
                    'sub_type' => $bookingData['card'] ?? 'yellow', // yellow, red, yellow_red
                    'period' => $bookingData['period'] ?? null,
                    'player_sportmonks_id' => $bookingData['player']['id'] ?? null,
                    'assist_player_name' => null,
                    'assist_player_sportmonks_id' => null,
                    'related_player_name' => null,
                    'related_player_sportmonks_id' => null,
                    'reason' => $bookingData['reason'] ?? null,
                    'description' => ucfirst($bookingData['card'] ?? 'yellow') . " card for " . ($bookingData['player']['name'] ?? 'Unknown'),
                    'is_own_goal' => false,
                    'is_penalty' => false,
                    'x_coordinate' => null,
                    'y_coordinate' => null,
                    'sort_order' => $bookingData['minute'] ?? 0,
                    'metadata' => json_encode($bookingData)
                ]
            );
            
        } catch (Exception $e) {
            Log::error("Failed to create booking event: " . $e->getMessage(), [
                'booking_data' => $bookingData,
                'match_id' => $matchId
            ]);
        }
    }

    /**
     * Create a substitution event from match details
     */
    private function createSubstitutionEvent(array $subData, int $matchId, array $matchDetails): void
    {
        try {
            // Determine team ID
            $teamId = null;
            if (isset($subData['team']['id'])) {
                $team = Team::where('football_data_id', $subData['team']['id'])->first();
                $teamId = $team ? $team->id : null;
            }

            // Create unique identifier for the event
            $eventId = $subData['id'] ?? uniqid('sub_' . $matchId . '_');

            MatchEvent::updateOrCreate(
                [
                    'match_id' => $matchId,
                    'type' => 'substitution',
                    'player_name' => $subData['playerOut']['name'] ?? null,
                    'minute' => $subData['minute'] ?? null
                ],
                [
                    'football_data_id' => $eventId,
                    'match_football_data_id' => $matchDetails['id'] ?? null,
                    'team_id' => $teamId,
                    'team_football_data_id' => $subData['team']['id'] ?? null,
                    'sub_type' => 'substitution',
                    'period' => $subData['period'] ?? null,
                    'player_sportmonks_id' => $subData['playerOut']['id'] ?? null,
                    'assist_player_name' => null,
                    'assist_player_sportmonks_id' => null,
                    'related_player_name' => $subData['playerIn']['name'] ?? null,
                    'related_player_sportmonks_id' => $subData['playerIn']['id'] ?? null,
                    'reason' => null,
                    'description' => "Substitution: " . ($subData['playerOut']['name'] ?? 'Unknown') . " ↔ " . ($subData['playerIn']['name'] ?? 'Unknown'),
                    'is_own_goal' => false,
                    'is_penalty' => false,
                    'x_coordinate' => null,
                    'y_coordinate' => null,
                    'sort_order' => $subData['minute'] ?? 0,
                    'metadata' => json_encode($subData)
                ]
            );
            
        } catch (Exception $e) {
            Log::error("Failed to create substitution event: " . $e->getMessage(), [
                'sub_data' => $subData,
                'match_id' => $matchId
            ]);
        }
    }

    private function findOrCreateLeague(array $leagueData): League
    {
        return League::firstOrCreate(
            ['football_data_id' => $leagueData['id']],
            [
                'name' => $leagueData['name'],
                'short_name' => $leagueData['shortName'] ?? $leagueData['name'],
                'country' => $leagueData['area']['name'] ?? null,
                'country_code' => $leagueData['area']['countryCode'] ?? null,
                'emblem' => $leagueData['emblem'] ?? null,
                'plan' => $leagueData['plan'] ?? 'TIER_ONE',
                'current_season' => $leagueData['currentSeason']['startDate'] ?? null,
                'is_featured' => false,
                'last_api_update' => now(),
                'metadata' => json_encode($leagueData)
            ]
        );
    }

    private function findOrCreateTeam(array $teamData, int $leagueId): Team
    {
        return Team::firstOrCreate(
            ['football_data_id' => $teamData['id']],
            [
                'league_id' => $leagueId,
                'name' => $teamData['name'],
                'short_name' => $teamData['shortName'] ?? $teamData['name'],
                'tla' => $teamData['tla'] ?? null,
                'crest' => $teamData['crest'] ?? null,
                'website' => $teamData['website'] ?? null,
                'founded' => $teamData['founded'] ?? null,
                'club_colors' => $teamData['clubColors'] ?? null,
                'venue_name' => $teamData['venue'] ?? null,
                'last_api_update' => now(),
                'metadata' => json_encode($teamData)
            ]
        );
    }

    /**
     * Get matches with events for a specific date
     */
    public function getMatchesWithEventsByDate(string $date): array
    {
        try {
            $response = $this->footballDataService->getMatchesWithEventsByDate($date);
            
            if (!$response || !isset($response['matches'])) {
                return ['success' => 0, 'errors' => 1, 'message' => 'No response from API'];
            }

            $successCount = 0;
            $errorCount = 0;

            foreach ($response['matches'] as $matchData) {
                try {
                    $league = $this->findOrCreateLeague($matchData['competition']);
                    $match = $this->createOrUpdateMatch($matchData, $league->id, $league->football_data_id);
                    
                    // If the match has events, sync them
                    if (isset($matchData['events']) && !empty($matchData['events'])) {
                        foreach ($matchData['events'] as $eventData) {
                            $this->createOrUpdateMatchEvent($eventData, $match->id);
                        }
                    }
                    
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    Log::error('Failed to sync match with events: ' . $e->getMessage());
                }
            }

            return [
                'success' => $successCount,
                'errors' => $errorCount,
                'message' => "Synced {$successCount} matches with events for {$date}"
            ];
        } catch (Exception $e) {
            Log::error('Match with events sync failed: ' . $e->getMessage());
            return ['success' => 0, 'errors' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * Manually sync events for a specific match
     */
    public function syncEventsForMatch(int $matchId): array
    {
        try {
            $match = FootballMatch::find($matchId);
            if (!$match) {
                return ['success' => false, 'message' => 'Match not found'];
            }

            $this->syncMatchEvents($match->football_data_id, $match->id);
            
            // Count events created
            $eventCount = MatchEvent::where('match_id', $matchId)->count();
            
            return [
                'success' => true, 
                'message' => "Synced events for match {$matchId}",
                'events_count' => $eventCount
            ];
        } catch (Exception $e) {
            Log::error("Failed to sync events for match {$matchId}: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sync events for all finished matches
     */
    public function syncEventsForFinishedMatches(int $limit = 10): array
    {
        try {
            $finishedMatches = FootballMatch::where('status', 'FINISHED')
                ->whereDoesntHave('events')
                ->take($limit)
                ->get();

            $successCount = 0;
            $errorCount = 0;

            foreach ($finishedMatches as $match) {
                try {
                    $this->syncMatchEvents($match->football_data_id, $match->id);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    Log::error("Failed to sync events for finished match {$match->id}: " . $e->getMessage());
                }
            }

            return [
                'success' => $successCount,
                'errors' => $errorCount,
                'message' => "Synced events for {$successCount} finished matches"
            ];
        } catch (Exception $e) {
            Log::error('Finished matches events sync failed: ' . $e->getMessage());
            return ['success' => 0, 'errors' => 1, 'message' => $e->getMessage()];
        }
    }
}
