<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Models\Team;
use App\Models\League;

class TeamController extends Controller
{
    public function index(Request $request): View
    {
        $query = Team::with(['league'])->where('is_active', true);
        
        // Search functionality
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('common_name', 'LIKE', "%{$search}%");
            });
        }
        
        // Filter by league
        if ($request->filled('league')) {
            $query->where('league_id', $request->league);
        }
        
        // IMPORTANT: Use paginate() not get() so view can call ->total()
        $teams = $query->orderBy('name')->paginate(20);
        
        // Get leagues for filter dropdown
        $leagues = League::where('is_active', true)->orderBy('name')->get();
        
        return view('teams.index', compact('teams', 'leagues'));
    }

        public function show(Team $team)
    {
        try {
            $team->load(['league']);
            
            // Get recent matches
            $recentMatches = \App\Models\FootballMatch::where(function($query) use ($team) {
                $query->where('home_team_id', $team->id)
                      ->orWhere('away_team_id', $team->id);
            })->with(['homeTeam', 'awayTeam', 'league'])
              ->where('match_date', '<=', now())
              ->orderBy('match_date', 'desc')
              ->limit(5)
              ->get();

            // Get upcoming matches
            $upcomingMatches = \App\Models\FootballMatch::where(function($query) use ($team) {
                $query->where('home_team_id', $team->id)
                      ->orWhere('away_team_id', $team->id);
            })->with(['homeTeam', 'awayTeam', 'league'])
              ->where('match_date', '>', now())
              ->orderBy('match_date', 'asc')
              ->limit(5)
              ->get();

            // Calculate team statistics
            $teamStats = [
                'total_matches' => \App\Models\FootballMatch::where(function($query) use ($team) {
                    $query->where('home_team_id', $team->id)
                          ->orWhere('away_team_id', $team->id);
                })->count(),
                'wins' => 0,
                'draws' => 0,
                'losses' => 0
            ];

            // Calculate wins, draws, losses if matches exist
            if ($teamStats['total_matches'] > 0) {
                $finishedMatches = \App\Models\FootballMatch::where(function($query) use ($team) {
                    $query->where('home_team_id', $team->id)
                          ->orWhere('away_team_id', $team->id);
                })->where('status', 'FINISHED')
                  ->whereNotNull('home_score')
                  ->whereNotNull('away_score')
                  ->get();

                foreach ($finishedMatches as $match) {
                    if ($match->home_team_id == $team->id) {
                        // Team is home
                        if ($match->home_score > $match->away_score) {
                            $teamStats['wins']++;
                        } elseif ($match->home_score == $match->away_score) {
                            $teamStats['draws']++;
                        } else {
                            $teamStats['losses']++;
                        }
                    } else {
                        // Team is away
                        if ($match->away_score > $match->home_score) {
                            $teamStats['wins']++;
                        } elseif ($match->away_score == $match->home_score) {
                            $teamStats['draws']++;
                        } else {
                            $teamStats['losses']++;
                        }
                    }
                }
            }

            // Get squad preview if Player model exists
            $playersByPosition = collect([]);
            if (class_exists('App\Models\Player')) {
                try {
                    $players = \App\Models\Player::where('team_id', $team->id)
                        ->where('is_active', true)
                        ->orderBy('position')
                        ->orderBy('shirt_number')
                        ->get();
                    
                    $playersByPosition = $players->groupBy('position');
                } catch (\Exception $e) {
                    // Player functionality not available
                    \Log::info('Player data not available for team: ' . $e->getMessage());
                }
            }

            return view('teams.show', compact(
                'team', 
                'recentMatches', 
                'upcomingMatches', 
                'teamStats',
                'playersByPosition'
            ));

        } catch (\Exception $e) {
            \Log::error('Team show error: ' . $e->getMessage());
            
            return view('teams.show', [
                'team' => $team,
                'recentMatches' => collect([]),
                'upcomingMatches' => collect([]),
                'teamStats' => [
                    'total_matches' => 0,
                    'wins' => 0,
                    'draws' => 0,
                    'losses' => 0
                ],
                'playersByPosition' => collect([])
            ]);
        }
    }

    /**
     * Display the squad for a specific team
     */
    public function squad(Team $team)
    {
        try {
            $team->load(['league', 'activePlayers']);
            
            // Get players grouped by position
            $playersByPosition = $team->activePlayers()
                ->orderBy('position')
                ->orderBy('shirt_number')
                ->get()
                ->groupBy('position');
            
            // Calculate squad statistics
            $squadStats = [
                'total_players' => $team->activePlayers()->count(),
                'goalkeepers' => $team->activePlayers()->where('position', 'Goalkeeper')->count(),
                'defenders' => $team->activePlayers()->where('position', 'Defender')->count(),
                'midfielders' => $team->activePlayers()->where('position', 'Midfielder')->count(),
                'forwards' => $team->activePlayers()->where('position', 'Attacker')->count(),
                'average_age' => $team->activePlayers()->avg('age') ?: 0,
                'captains' => $team->activePlayers()->where('is_captain', true)->count(),
                'nationalities' => $team->activePlayers()->distinct('nationality')->count('nationality')
            ];
            
            // Get recent team matches for context
            $recentMatches = \App\Models\FootballMatch::where(function($query) use ($team) {
                $query->where('home_team_id', $team->id)
                      ->orWhere('away_team_id', $team->id);
            })->with(['homeTeam', 'awayTeam', 'league'])
              ->where('match_date', '<=', now())
              ->orderBy('match_date', 'desc')
              ->limit(5)
              ->get();
            
            return view('teams.squad', compact(
                'team', 
                'playersByPosition', 
                'squadStats',
                'recentMatches'
            ));
            
        } catch (\Exception $e) {
            \Log::error('Team squad error: ' . $e->getMessage());
            
            return view('teams.squad', [
                'team' => $team,
                'playersByPosition' => collect([]),
                'squadStats' => [
                    'total_players' => 0,
                    'goalkeepers' => 0,
                    'defenders' => 0,
                    'midfielders' => 0,
                    'forwards' => 0,
                    'average_age' => 0,
                    'captains' => 0,
                    'nationalities' => 0
                ],
                'recentMatches' => collect([])
            ]);
        }
    }
}