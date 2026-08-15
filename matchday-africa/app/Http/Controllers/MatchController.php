<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Services\MythGrammarService;
use Illuminate\Http\JsonResponse;

class MatchController extends Controller
{
    public function chronicle(FootballMatch $match, MythGrammarService $myth): JsonResponse
    {
        $match->load(['homeTeam', 'awayTeam', 'events.team']);
        $story = $myth->tell($match);
        $active = in_array($match->status, FootballMatch::LIVE_STATUSES, true);
        $fresh = $match->last_api_update && $match->last_api_update->gte(now()->subMinutes(5));

        return response()->json([
            'signature' => $story['signature'],
            'html' => view('partials.match-chronicle', ['match' => $match, 'mythStory' => $story, 'fresh' => $fresh])->render(),
            'score' => ['home' => $match->home_score, 'away' => $match->away_score],
            'status' => $match->status_display,
            'minute' => $match->minute,
            'active' => $active,
            'fresh' => $fresh,
            'updated_at' => $match->last_api_update?->toIso8601String(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function index(Request $request)
    {
        try {
            $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
                ->orderBy('match_date', 'asc');

            // Get filter parameter - default to 'today'
            $filter = $request->get('filter', 'today');
            $today = Carbon::today();
            $tomorrow = Carbon::tomorrow();

            // Apply filters based on the filter parameter
            switch ($filter) {
                case 'live':
                    $query->whereIn('status', ['LIVE', 'IN_PLAY', 'PAUSED', '1H', '2H', 'HT']);
                    break;
                    
                case 'today':
                    $query->whereDate('match_date', $today);
                    break;
                    
                case 'tomorrow':
                    $query->whereDate('match_date', $tomorrow);
                    break;
                    
                case 'all':
                default:
                    // Show all matches - no additional filter
                    break;
            }

            // Apply other existing filters (league, team, etc.)
            if ($request->filled('league_id')) {
                $query->where('league_id', $request->league_id);
            }

            if ($request->filled('team_id')) {
                $query->where(function($q) use ($request) {
                    $q->where('home_team_id', $request->team_id)
                      ->orWhere('away_team_id', $request->team_id);
                });
            }

            if ($request->filled('date_from')) {
                $query->whereDate('match_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('match_date', '<=', $request->date_to);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Get paginated results
            $matches = $query->paginate(20)->withQueryString();

            // Get filter counts for display
            $liveCounts = FootballMatch::whereIn('status', ['LIVE', 'IN_PLAY', 'PAUSED', '1H', '2H', 'HT'])->count();
            $todayCounts = FootballMatch::whereDate('match_date', $today)->count();
            $tomorrowCounts = FootballMatch::whereDate('match_date', $tomorrow)->count();

            // Get filter options for dropdowns
            $leagues = League::where('is_active', true)->orderBy('name')->get();
            $teams = Team::where('is_active', true)->orderBy('name')->get();

            return view('matches.index', compact(
                'matches', 
                'leagues', 
                'teams',
                'liveCounts',
                'todayCounts',
                'tomorrowCounts',
                'filter'
            ));

        } catch (\Exception $e) {
            \Log::error('Matches index error: ' . $e->getMessage());
            
            return view('matches.index', [
                'matches' => new LengthAwarePaginator(
                    collect(),
                    0,
                    20,
                    max(1, (int) request()->input('page', 1)),
                    [
                        'path' => request()->url(),
                        'query' => request()->query(),
                    ]
                ),
                'leagues' => collect([]),
                'teams' => collect([]),
                'liveCounts' => 0,
                'todayCounts' => 0,
                'tomorrowCounts' => 0,
                'filter' => 'today'
            ]);
        }
    }

    public function show(FootballMatch $match)
    {
        try {
            $match->load(['homeTeam', 'awayTeam', 'league', 'events']);

            // Get head-to-head matches
            $headToHead = FootballMatch::where(function($query) use ($match) {
                $query->where('home_team_id', $match->home_team_id)
                      ->where('away_team_id', $match->away_team_id);
            })->orWhere(function($query) use ($match) {
                $query->where('home_team_id', $match->away_team_id)
                      ->where('away_team_id', $match->home_team_id);
            })->where('id', '!=', $match->id)
              ->with(['homeTeam', 'awayTeam'])
              ->orderBy('match_date', 'desc')
              ->limit(5)
              ->get();

            // Get upcoming matches for both teams
            $upcomingMatches = FootballMatch::where(function($query) use ($match) {
                $query->where('home_team_id', $match->home_team_id)
                      ->orWhere('away_team_id', $match->home_team_id)
                      ->orWhere('home_team_id', $match->away_team_id)
                      ->orWhere('away_team_id', $match->away_team_id);
            })->where('id', '!=', $match->id)
              ->where('match_date', '>', now())
              ->with(['homeTeam', 'awayTeam', 'league'])
              ->orderBy('match_date', 'asc')
              ->limit(5)
              ->get();

            // Get recent chats for this match
            $recentChats = collect([]);
            if (class_exists('App\Models\MatchChat')) {
                $recentChats = \App\Models\MatchChat::where('match_id', $match->id)
                    ->with('user')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get();
            }

            // Get the preview for the current match
            $matchPreview = \App\Models\MatchPreview::where('match_id', $match->id)
                ->active()
                ->first();

            // Get featured previews for similar matches
            $featuredPreviews = \App\Models\MatchPreview::with(['match.homeTeam', 'match.awayTeam', 'match.league'])
                ->featured()
                ->active()
                ->whereHas('match', function($query) use ($match) {
                    $query->where('league_id', $match->league_id)
                          ->where('id', '!=', $match->id)
                          ->where('match_date', '>=', now())
                          ->where('match_date', '<=', now()->addDays(7));
                })
                ->orderBy('generated_at', 'desc')
                ->take(3)
                ->get();

            // Ensure all variables are defined
            $events = $match->events ?? collect([]);
            $momentum = max(10, min(90, 50 + (($match->home_score ?? 0)-($match->away_score ?? 0))*12 + $events->where('team_id',$match->home_team_id)->count()*3 - $events->where('team_id',$match->away_team_id)->count()*3));
            $predictionSet = \App\Models\PredictionSet::where('status','active')->where('prediction_deadline','>',now())
                ->whereHas('matches',fn($q)=>$q->where('match_id',$match->id))->first();
            $mythStory = app(MythGrammarService::class)->tell($match);

            return view('matches.show', compact(
                'match', 
                'events', 
                'headToHead', 
                'upcomingMatches', 
                'recentChats',
                'matchPreview',
                'featuredPreviews','momentum','predictionSet','mythStory'
            ))->with('content', $match);

        } catch (\Exception $e) {
            \Log::error('Match show error: ' . $e->getMessage());
            
            return view('matches.show', [
                'match' => $match,
                'events' => collect([]),
                'headToHead' => collect([]),
                'upcomingMatches' => collect([]),
                'recentChats' => collect([]),
                'matchPreview' => null, 'momentum' => 50, 'predictionSet' => null, 'mythStory' => null,
                'featuredPreviews' => collect([])
            ])->with('content', $match);
        }
    }
}
