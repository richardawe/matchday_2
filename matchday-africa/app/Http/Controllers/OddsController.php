<?php

namespace App\Http\Controllers;

use App\Services\OddsApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OddsController extends Controller
{
    private OddsApiService $oddsService;

    public function __construct(OddsApiService $oddsService)
    {
        $this->oddsService = $oddsService;
    }

    /**
     * Get EPL odds for this weekend
     */
    public function eplWeekend(): JsonResponse
    {
        try {
            $odds = $this->oddsService->getEplWeekendOdds();
            
            if (!$odds) {
                return response()->json([
                    'success' => false,
                    'message' => 'No EPL odds available'
                ], 404);
            }

            // Format the response for easier consumption
            $formattedOdds = $this->formatOddsResponse($odds);

            return response()->json([
                'success' => true,
                'data' => $formattedOdds,
                'count' => count($formattedOdds)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching EPL odds: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get odds for specific EPL match
     */
    public function matchOdds(string $eventId): JsonResponse
    {
        try {
            $odds = $this->oddsService->getMatchOdds($eventId);
            
            if (!$odds) {
                return response()->json([
                    'success' => false,
                    'message' => 'No odds available for this match'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $odds
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching match odds: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get upcoming EPL matches with odds
     */
    public function upcoming(): JsonResponse
    {
        try {
            $matches = $this->oddsService->getUpcomingEplMatches();
            
            if (!$matches) {
                return response()->json([
                    'success' => false,
                    'message' => 'No upcoming EPL matches available'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $matches,
                'count' => count($matches)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching upcoming matches: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format odds response for easier consumption
     */
    private function formatOddsResponse(array $odds): array
    {
        return array_map(function ($match) {
            // Get best odds for each outcome
            $bestOdds = $this->getBestOdds($match['bookmakers']);
            
            return [
                'match_id' => $match['id'],
                'home_team' => $match['home_team'],
                'away_team' => $match['away_team'],
                'commence_time' => $match['commence_time'],
                'sport' => $match['sport_title'],
                'best_odds' => $bestOdds,
                'bookmaker_count' => count($match['bookmakers']),
                'all_bookmakers' => $this->formatBookmakers($match['bookmakers'])
            ];
        }, $odds);
    }

    /**
     * Get best odds from all bookmakers
     */
    private function getBestOdds(array $bookmakers): array
    {
        $bestOdds = [
            'home_win' => ['price' => 0, 'bookmaker' => ''],
            'away_win' => ['price' => 0, 'bookmaker' => ''],
            'draw' => ['price' => 0, 'bookmaker' => '']
        ];

        foreach ($bookmakers as $bookmaker) {
            foreach ($bookmaker['markets'] as $market) {
                if ($market['key'] === 'h2h') {
                    foreach ($market['outcomes'] as $outcome) {
                        $team = $outcome['name'];
                        $price = $outcome['price'];
                        
                        if ($team === $bookmakers[0]['markets'][0]['outcomes'][0]['name']) {
                            // Home team
                            if ($price > $bestOdds['home_win']['price']) {
                                $bestOdds['home_win'] = [
                                    'price' => $price,
                                    'bookmaker' => $bookmaker['title']
                                ];
                            }
                        } elseif ($team === 'Draw') {
                            if ($price > $bestOdds['draw']['price']) {
                                $bestOdds['draw'] = [
                                    'price' => $price,
                                    'bookmaker' => $bookmaker['title']
                                ];
                            }
                        } else {
                            // Away team
                            if ($price > $bestOdds['away_win']['price']) {
                                $bestOdds['away_win'] = [
                                    'price' => $price,
                                    'bookmaker' => $bookmaker['title']
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $bestOdds;
    }

    /**
     * Format bookmakers data
     */
    private function formatBookmakers(array $bookmakers): array
    {
        return array_map(function ($bookmaker) {
            return [
                'name' => $bookmaker['title'],
                'key' => $bookmaker['key'],
                'last_update' => $bookmaker['last_update'],
                'markets' => $bookmaker['markets']
            ];
        }, $bookmakers);
    }
}
