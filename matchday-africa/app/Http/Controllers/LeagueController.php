<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Models\League;
use App\Models\FootballMatch;
use App\Models\Team;
use App\Models\Standing;
use Illuminate\Support\Facades\Schema;

class LeagueController extends Controller
{
    // EXISTING METHODS - UNCHANGED
    public function index(): View
    {
        $featuredLeagues = League::where('is_featured', true)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();
            
        $otherLeagues = League::where('is_featured', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        
        return view('leagues.index', compact('featuredLeagues', 'otherLeagues'));
    }

    public function show(League $league): View
    {
        try {
            // EXISTING FUNCTIONALITY - UNCHANGED
            $upcomingMatches = FootballMatch::with(['homeTeam', 'awayTeam'])
                ->where('league_id', $league->id)
                ->where('match_date', '>', now())
                ->orderBy('match_date')
                ->limit(10)
                ->get();
                
            $liveMatches = FootballMatch::with(['homeTeam', 'awayTeam'])
                ->where('league_id', $league->id)
                ->whereIn('status', ['LIVE', '1H', '2H', 'HT'])
                ->orderBy('match_date')
                ->limit(5)
                ->get();
                
            $recentMatches = FootballMatch::with(['homeTeam', 'awayTeam'])
                ->where('league_id', $league->id)
                ->where('status', 'FINISHED')
                ->where('match_date', '>=', now()->subDays(7))
                ->orderBy('match_date', 'desc')
                ->limit(10)
                ->get();
            
            $teams = collect([]);
            try {
                if (Schema::hasColumn('teams', 'league_id')) {
                    $teams = Team::where('league_id', $league->id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get();
                }
            } catch(\Exception $e) {
                $teams = collect([]);
            }
            
            // NEW: Add standings data (optional, doesn't break if missing)
            $standings = collect([]);
            try {
                $standings = Standing::with('team')
                    ->where('league_id', $league->id)
                    ->where('is_current', true)
                    ->orderBy('position')
                    ->get();
            } catch(\Exception $e) {
                // Standings not available, continue without them
                $standings = collect([]);
            }
            
            return view('leagues.show', compact(
                'league',
                'upcomingMatches',
                'liveMatches', 
                'recentMatches',
                'teams',
                'standings'
            ));
            
        } catch(\Exception $e) {
            \Log::error('League show error: ' . $e->getMessage());
            
            return view('leagues.show', [
                'league' => $league,
                'upcomingMatches' => collect([]),
                'liveMatches' => collect([]),
                'recentMatches' => collect([]),
                'teams' => collect([]),
                'standings' => collect([])
            ]);
        }
    }

    // NEW METHOD: Dedicated standings page
    public function standings(League $league): View
    {
        $standings = Standing::with('team')
            ->where('league_id', $league->id)
            ->where('is_current', true)
            ->orderBy('position')
            ->get();

        return view('leagues.standings', compact('league', 'standings'));
    }
}
