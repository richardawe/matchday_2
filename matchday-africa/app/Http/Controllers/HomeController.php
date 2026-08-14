<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Blog;
use App\Models\MatchPreview;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\Team;
use App\Models\PredictionSet;

class HomeController extends Controller
{
    public function index()
    {
        try {
            // Get today's matches WITH goal scorer relationships
            $today = now()->format('Y-m-d');
            $todaysMatches = FootballMatch::with([
                'homeTeam', 
                'awayTeam', 
                'league',
                'events' => function($query) {
                    $query->whereIn('type', ['goal', 'penalty_goal', 'own_goal'])
                          ->orderBy('minute', 'asc');
                }
            ])
            ->whereDate('match_date', $today)
            ->orderBy('match_date', 'asc')
            ->get();

            // Get featured leagues
            $featuredLeagues = League::where('is_featured', true)
                ->orderBy('name', 'asc')
                ->get();

            // Get live matches WITH goal scorer relationships
            $liveMatches = FootballMatch::with([
                'homeTeam', 
                'awayTeam', 
                'league',
                'events' => function($query) {
                    $query->whereIn('type', ['goal', 'penalty_goal', 'own_goal'])
                          ->orderBy('minute', 'asc');
                }
            ])
            ->whereIn('status', ['LIVE', '1H', '2H', 'HT'])
            ->orderBy('match_date', 'asc')
            ->get();

            // Get featured blog posts
            $featuredBlogs = Blog::where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', Carbon::now())
                ->orderBy('published_at', 'desc')
                ->take(3)
                ->get();

            // Get match previews for featured matches
            $matchPreviews = MatchPreview::with(['match.homeTeam', 'match.awayTeam', 'match.league'])
                ->whereHas('match', function($query) {
                    $query->whereIn('status', ['LIVE', '1H', '2H', 'HT'])
                          ->orWhereDate('match_date', Carbon::today());
                })
                ->active()
                ->orderBy('generated_at', 'desc')
                ->take(5)
                ->get();

            // Get featured match previews
            $featuredPreviews = MatchPreview::with(['match.homeTeam', 'match.awayTeam', 'match.league'])
                ->featured()
                ->active()
                ->whereHas('match', function($query) {
                    $query->where('match_date', '>=', Carbon::today())
                          ->where('match_date', '<=', Carbon::today()->addDays(7));
                })
                ->orderBy('generated_at', 'desc')
                ->take(3)
                ->get();

            // Check if we have any data
            $hasMatchesToday = $todaysMatches->count() > 0;
            $hasLiveMatches = $liveMatches->count() > 0;
            $hasFeaturedBlogs = $featuredBlogs->count() > 0;
            $hasMatchPreviews = $matchPreviews->count() > 0;
            $hasFeaturedPreviews = $featuredPreviews->count() > 0;

            $followedTeams = collect();
            $personalMatches = collect();
            $openPredictionSets = collect();
            if (auth()->check()) {
                $teamIds = auth()->user()->favorites()->where('favorable_type', Team::class)->pluck('favorable_id');
                $followedTeams = Team::whereIn('id', $teamIds)->get();
                $personalMatches = FootballMatch::with(['homeTeam','awayTeam','league'])
                    ->where(fn($q)=>$q->whereIn('home_team_id',$teamIds)->orWhereIn('away_team_id',$teamIds))
                    ->whereBetween('match_date',[now()->subHours(3),now()->addDays(7)])->orderBy('match_date')->take(8)->get();
                $openPredictionSets = PredictionSet::where('status','active')->where('prediction_deadline','>',now())->withCount('matches')->take(3)->get();
            }

            return view('home', compact(
                'todaysMatches',
                'featuredLeagues', 
                'liveMatches',
                'featuredBlogs',
                'matchPreviews',
                'featuredPreviews',
                'today',
                'hasMatchesToday',
                'hasLiveMatches',
                'hasFeaturedBlogs',
                'hasMatchPreviews',
                'hasFeaturedPreviews','followedTeams','personalMatches','openPredictionSets'
            ));
        } catch (\Exception $e) {
            // Log the error and return a simple view
            Log::error('HomeController error: ' . $e->getMessage());
            
            return view('home', [
                'todaysMatches' => collect(),
                'featuredLeagues' => collect(),
                'liveMatches' => collect(),
                'featuredBlogs' => collect(),
                'matchPreviews' => collect(),
                'featuredPreviews' => collect(),
                'today' => now()->format('Y-m-d'),
                'hasMatchesToday' => false,
                'hasLiveMatches' => false,
                'hasFeaturedBlogs' => false,
                'hasMatchPreviews' => false,
                'hasFeaturedPreviews' => false,
                'followedTeams' => collect(), 'personalMatches' => collect(), 'openPredictionSets' => collect()
            ]);
        }
    }
}
