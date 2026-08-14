<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Exception;

class PlayerService
{
    public function __construct(
        private FootballDataService $footballDataService
    ) {}

    /**
     * Sync players for a specific team
     */
    public function syncTeamPlayers(int $teamFootballDataId): array
    {
        try {
            $response = $this->footballDataService->get("teams/{$teamFootballDataId}");

            if (!$response || !isset($response["squad"])) {
                return ["success" => 0, "errors" => 1, "message" => "No squad data from API"];
            }

            $team = Team::where("football_data_id", $teamFootballDataId)->first();
            if (!$team) {
                return ["success" => 0, "errors" => 1, "message" => "Team not found"];
            }

            $successCount = 0;
            $errorCount = 0;
            $squadData = $response["squad"] ?? [];

            // Clear existing players for this team
            Player::where("team_id", $team->id)->delete();

            foreach ($squadData as $playerData) {
                try {
                    $position = $this->mapPosition($playerData["position"] ?? "");
                    $detailedPosition = $playerData["position"] ?? null;
                    
                    // Calculate age if date of birth is available
                    $age = null;
                    $dateOfBirth = null;
                    if (isset($playerData["dateOfBirth"])) {
                        $dateOfBirth = $playerData["dateOfBirth"];
                        $age = \Carbon\Carbon::parse($dateOfBirth)->age;
                    }

                    Player::create([
                        "team_id" => $team->id,
                        "football_data_id" => $playerData["id"] ?? null,
                        "name" => $playerData["name"],
                        "position" => $position,
                        "detailed_position" => $detailedPosition,
                        "shirt_number" => $playerData["shirtNumber"] ?? null,
                        "nationality" => $playerData["nationality"] ?? null,
                        "nationality_code" => $this->getNationalityCode($playerData["nationality"] ?? ""),
                        "date_of_birth" => $dateOfBirth,
                        "age" => $age,
                        "is_active" => true,
                        "last_api_update" => now(),
                        "metadata" => json_encode($playerData)
                    ]);

                    $successCount++;

                } catch (Exception $e) {
                    $errorCount++;
                    Log::error("Failed to sync player: " . ($playerData["name"] ?? "unknown") . " - " . $e->getMessage());
                    echo "❌ Player error: " . ($playerData["name"] ?? "unknown") . " - " . $e->getMessage() . "\n";
                }
            }

            return [
                "success" => $successCount,
                "errors" => $errorCount,
                "message" => "Synced {$successCount} players with {$errorCount} errors for " . $team->name
            ];

        } catch (Exception $e) {
            Log::error("Player sync failed for team " . $teamFootballDataId . ": " . $e->getMessage());
            return ["success" => 0, "errors" => 1, "message" => $e->getMessage()];
        }
    }

    /**
     * Sync players for all active teams
     */
    public function syncAllTeamPlayers(): array
    {
        $teams = Team::where("is_active", true)
            ->whereNotNull("football_data_id")
            ->orderBy("name")
            ->get();
            
        $totalSuccess = 0;
        $totalErrors = 0;
        $processedTeams = 0;

        foreach ($teams as $team) {
            echo "👥 Syncing players for {$team->name} (ID: {$team->football_data_id})...\n";
            
            $result = $this->syncTeamPlayers($team->football_data_id);
            echo "   " . $result["message"] . "\n";
            
            $totalSuccess += $result["success"];
            $totalErrors += $result["errors"];
            $processedTeams++;
            
            // Rate limiting - 10 calls per minute on free plan
            if ($processedTeams % 10 == 0) {
                echo "   ⏳ Rate limit pause (10 calls reached)... waiting 70 seconds\n";
                sleep(70);
            } else {
                sleep(7); // 7 seconds between calls
            }
        }

        return [
            "success" => $totalSuccess,
            "errors" => $totalErrors,
            "teams_processed" => $processedTeams,
            "message" => "Synced players for {$processedTeams} teams: {$totalSuccess} players with {$totalErrors} errors"
        ];
    }

    /**
     * Map API position to our standardized positions
     */
    private function mapPosition(string $position): string
    {
        $position = strtoupper(trim($position));
        
        // Goalkeeper
        if (in_array($position, ["GOALKEEPER", "GK"])) {
            return "Goalkeeper";
        }
        
        // Defenders
        if (in_array($position, ["DEFENDER", "DEF", "CENTRE-BACK", "LEFT-BACK", "RIGHT-BACK", "CB", "LB", "RB", "LWB", "RWB"])) {
            return "Defender";
        }
        
        // Midfielders
        if (in_array($position, ["MIDFIELDER", "MID", "MIDFIELD", "CENTRAL MIDFIELD", "DEFENSIVE MIDFIELD", "ATTACKING MIDFIELD", "LEFT MIDFIELD", "RIGHT MIDFIELD", "CM", "CDM", "CAM", "LM", "RM"])) {
            return "Midfielder";
        }
        
        // Forwards/Attackers
        if (in_array($position, ["ATTACKER", "FORWARD", "FWD", "STRIKER", "WINGER", "LEFT WINGER", "RIGHT WINGER", "CENTRE-FORWARD", "ST", "CF", "LW", "RW"])) {
            return "Attacker";
        }
        
        // Default to Midfielder if unknown
        return "Midfielder";
    }

    /**
     * Get nationality code from nationality name
     */
    private function getNationalityCode(string $nationality): string
    {
        $nationalityCodes = [
            "England" => "ENG",
            "Scotland" => "SCO", 
            "Wales" => "WAL",
            "Northern Ireland" => "NIR",
            "France" => "FRA",
            "Germany" => "GER",
            "Spain" => "ESP",
            "Italy" => "ITA",
            "Portugal" => "POR",
            "Netherlands" => "NED",
            "Belgium" => "BEL",
            "Brazil" => "BRA",
            "Argentina" => "ARG",
            "Uruguay" => "URU",
            "Colombia" => "COL",
            "United States" => "USA",
            "Canada" => "CAN",
            "Mexico" => "MEX",
            "Japan" => "JPN",
            "Korea Republic" => "KOR",
            "Australia" => "AUS",
            "Nigeria" => "NGA",
            "Ghana" => "GHA",
            "Senegal" => "SEN",
            "Côte d'Ivoire" => "CIV",
            "Morocco" => "MAR",
            "Egypt" => "EGY",
            "Tunisia" => "TUN",
            "Algeria" => "ALG",
            "Poland" => "POL",
            "Czech Republic" => "CZE",
            "Croatia" => "HRV",
            "Serbia" => "SRB",
            "Denmark" => "DEN",
            "Sweden" => "SWE",
            "Norway" => "NOR",
            "Austria" => "AUT",
            "Switzerland" => "SUI",
            "Turkey" => "TUR",
            "Greece" => "GRE",
            "Ukraine" => "UKR",
            "Russia" => "RUS",
            "Finland" => "FIN",
            "Iceland" => "ISL",
            "Ireland" => "IRL",
            "Slovenia" => "SVN",
            "Slovakia" => "SVK",
            "Hungary" => "HUN",
            "Romania" => "ROU",
            "Bulgaria" => "BGR",
            "Bosnia and Herzegovina" => "BIH",
            "Montenegro" => "MNE",
            "North Macedonia" => "MKD",
            "Albania" => "ALB",
            "Georgia" => "GEO",
            "Armenia" => "ARM",
            "Azerbaijan" => "AZE",
            "Kazakhstan" => "KAZ",
            "Israel" => "ISR",
            "Iran" => "IRN",
            "Iraq" => "IRQ",
            "Saudi Arabia" => "SAU",
            "United Arab Emirates" => "UAE",
            "Qatar" => "QAT",
            "Kuwait" => "KUW",
            "Bahrain" => "BHR",
            "Oman" => "OMA",
            "Jordan" => "JOR",
            "Lebanon" => "LBN",
            "Syria" => "SYR",
            "Yemen" => "YEM",
            "India" => "IND",
            "China" => "CHN",
            "South Korea" => "KOR",
            "Thailand" => "THA",
            "Vietnam" => "VIE",
            "Malaysia" => "MAS",
            "Singapore" => "SIN",
            "Indonesia" => "IDN",
            "Philippines" => "PHI",
            "South Africa" => "RSA",
            "Kenya" => "KEN",
            "Ethiopia" => "ETH",
            "Uganda" => "UGA",
            "Tanzania" => "TAN",
            "Zimbabwe" => "ZIM",
            "Zambia" => "ZAM",
            "Cameroon" => "CMR",
            "Mali" => "MLI",
            "Burkina Faso" => "BFA",
            "Guinea" => "GUI",
            "Sierra Leone" => "SLE",
            "Liberia" => "LBR",
            "Togo" => "TOG",
            "Benin" => "BEN",
            "Cape Verde" => "CPV",
            "Gambia" => "GAM",
            "Guinea-Bissau" => "GNB",
            "Equatorial Guinea" => "EQG",
            "São Tomé and Príncipe" => "STP",
            "Chad" => "CHA",
            "Central African Republic" => "CAF",
            "Democratic Republic of the Congo" => "COD",
            "Republic of the Congo" => "CGO",
            "Gabon" => "GAB",
            "Angola" => "ANG",
            "Mozambique" => "MOZ",
            "Madagascar" => "MAD",
            "Mauritius" => "MRI",
            "Comoros" => "COM",
            "Seychelles" => "SEY"
        ];
        
        return $nationalityCodes[$nationality] ?? strtoupper(substr($nationality, 0, 3));
    }
}
