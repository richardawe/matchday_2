<?php

namespace App\Services;

use App\Models\Standing;
use App\Models\League;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Exception;

class StandingService
{
    public function __construct(
        private FootballDataService $footballDataService
    ) {}

    /**
     * Sync standings for a specific league
     */
    public function syncLeagueStandings(int $leagueFootballDataId): array
    {
        try {
            $response = $this->footballDataService->get("competitions/{$leagueFootballDataId}/standings");

            if (!$response || !isset($response["standings"])) {
                Log::error("No standings data from API for league: " . $leagueFootballDataId);
                return ["success" => 0, "errors" => 1, "message" => "No standings data from API"];
            }

            $league = League::where("football_data_id", $leagueFootballDataId)->first();
            if (!$league) {
                Log::error("League not found: " . $leagueFootballDataId);
                return ["success" => 0, "errors" => 1, "message" => "League not found"];
            }

            $successCount = 0;
            $errorCount = 0;

            // Usually standings[0] contains the main table
            $standingsData = $response["standings"][0]["table"] ?? [];
            
            if (empty($standingsData)) {
                Log::warning("Empty standings table for league: " . $leagueFootballDataId);
                return ["success" => 0, "errors" => 1, "message" => "Empty standings table"];
            }
            
            // Clear current standings for this league
            Standing::where("league_id", $league->id)
                ->where("is_current", true)
                ->delete();

            foreach ($standingsData as $standingData) {
                try {
                    $team = Team::where("football_data_id", $standingData["team"]["id"])->first();
                    
                    if (!$team) {
                        // Create team if it doesn't exist
                        $team = Team::create([
                            "football_data_id" => $standingData["team"]["id"],
                            "name" => $standingData["team"]["name"],
                            "common_name" => $standingData["team"]["shortName"] ?? $standingData["team"]["name"],
                            "short_code" => $standingData["team"]["tla"] ?? null,
                            "logo_url" => $standingData["team"]["crest"] ?? null,
                            "league_id" => $league->id,
                            "is_active" => true,
                        ]);
                    } else {
                        // Update team's league association if not set
                        if (!$team->league_id) {
                            $team->update(["league_id" => $league->id]);
                        }
                    }

                    Standing::create([
                        "team_id" => $team->id,
                        "league_id" => $league->id,
                        "team_football_data_id" => $standingData["team"]["id"],
                        "league_football_data_id" => $leagueFootballDataId,
                        "position" => $standingData["position"],
                        "points" => $standingData["points"],
                        "wins" => $standingData["won"],
                        "draws" => $standingData["draw"],
                        "losses" => $standingData["lost"],
                        "goals_for" => $standingData["goalsFor"],
                        "goals_against" => $standingData["goalsAgainst"],
                        "goal_difference" => $standingData["goalDifference"],
                        "matches_played" => $standingData["playedGames"],
                        "recent_form" => $standingData["form"] ?? null,
                        "is_current" => true,
                        "season" => $response["season"]["startDate"] ?? date("Y"),
                        "season_year" => date("Y", strtotime($response["season"]["startDate"] ?? "now")),
                        "calculation_date" => now(),
                        "last_api_update" => now(),
                    ]);

                    $successCount++;
                    
                } catch (Exception $e) {
                    $errorCount++;
                    Log::error("Failed to sync standing for team: " . ($standingData["team"]["name"] ?? "unknown") . " - " . $e->getMessage());
                    echo "❌ Team error: " . ($standingData["team"]["name"] ?? "unknown") . " - " . $e->getMessage() . "\n";
                }
            }

            return [
                "success" => $successCount,
                "errors" => $errorCount,
                "message" => "Synced {$successCount} standings with {$errorCount} errors"
            ];

        } catch (Exception $e) {
            Log::error("Standings sync failed for league " . $leagueFootballDataId . ": " . $e->getMessage());
            return ["success" => 0, "errors" => 1, "message" => $e->getMessage()];
        }
    }

    /**
     * Sync standings for all active leagues
     */
    public function syncAllLeagueStandings(): array
    {
        $leagues = League::where("is_active", true)->get();
        $totalSuccess = 0;
        $totalErrors = 0;

        foreach ($leagues as $league) {
            echo "🏆 Syncing " . $league->name . "...\n";
            $result = $this->syncLeagueStandings($league->football_data_id);
            echo "   Result: " . $result["message"] . "\n";
            
            $totalSuccess += $result["success"];
            $totalErrors += $result["errors"];
            
            // Rate limiting
            sleep(7);
        }

        return [
            "success" => $totalSuccess,
            "errors" => $totalErrors,
            "message" => "Synced standings for {$leagues->count()} leagues"
        ];
    }
}
