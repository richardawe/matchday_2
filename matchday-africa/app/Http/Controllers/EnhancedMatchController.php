<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Models\FootballMatch;
use App\Models\League;

class EnhancedMatchController extends Controller
{
    /**
     * Display matches with focus on today and tomorrow
     */
    public function index(Request $request): View
    {
        $query = FootballMatch::with(['league', 'homeTeam', 'awayTeam']);
        
        // Default to showing today and tomorrow if no filters
        if (!$request->hasAny(['date', 'league', 'status'])) {
            $query->whereBetween('match_date', [
                now()->startOfDay(),
                now()->addDay()->endOfDay()
            ]);
        } else {
            // Apply user filters
            if ($request->filled('date')) {
                $query->whereDate('match_date', $request->date);
            }
            
            if ($request->filled('league')) {
                $query->where('league_id', $request->league);
            }
            
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
        }
        
        $matches = $query->orderBy('match_date', 'asc')->paginate(20);
        
        // Get leagues for filter dropdown
        $leagues = League::where('is_active', true)
            ->withCount('matches')
            ->orderBy('matches_count', 'desc')
            ->get();
        
        // Get quick stats for today/tomorrow
        $todayMatches = FootballMatch::whereDate('match_date', now())->count();
        $tomorrowMatches = FootballMatch::whereDate('match_date', now()->addDay())->count();
        $liveMatches = FootballMatch::whereIn('status', ['LIVE', '1H', '2H', 'HT'])->count();
        
        return view('matches.index', compact(
            'matches', 
            'leagues', 
            'todayMatches', 
            'tomorrowMatches', 
            'liveMatches'
        ));
    }

    /**
     * Show specific match with comprehensive data
     */
    public function show(FootballMatch $match): View
    {
        $match->load(['league', 'homeTeam', 'awayTeam', 'events', 'chats.user']);
        
        // Get head-to-head history
        $headToHead = FootballMatch::where(function($query) use ($match) {
            $query->where([
                ['home_team_id', $match->home_team_id],
                ['away_team_id', $match->away_team_id]
            ])->orWhere([
                ['home_team_id', $match->away_team_id],
                ['away_team_id', $match->home_team_id]
            ]);
        })
        ->where('id', '!=', $match->id)
        ->where('status', 'FINISHED')
        ->orderBy('match_date', 'desc')
        ->limit(5)
        ->get();
        
        // Get upcoming matches for both teams
        $upcomingMatches = FootballMatch::where(function($query) use ($match) {
            $query->where('home_team_id', $match->home_team_id)
                  ->orWhere('away_team_id', $match->home_team_id)
                  ->orWhere('home_team_id', $match->away_team_id)
                  ->orWhere('away_team_id', $match->away_team_id);
        })
        ->where('id', '!=', $match->id)
        ->where('match_date', '>', $match->match_date)
        ->orderBy('match_date')
        ->limit(5)
        ->get();
        
        // Get recent chats
        $recentChats = $match->chats()
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();
        
        return view('matches.show', compact(
            'match',
            'headToHead',
            'upcomingMatches',
            'recentChats'
        ));
    }
}
