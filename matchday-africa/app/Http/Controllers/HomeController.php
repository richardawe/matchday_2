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
use Illuminate\Http\JsonResponse;
use App\Models\Player;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    public function pulse(): JsonResponse
    {
        $now=now();$relations=['homeTeam','awayTeam','league'];
        $live=FootballMatch::with($relations)->crediblyLive($now)->orderBy('match_date')->take(4)->get();
        $upcoming=FootballMatch::with($relations)->whereIn('status',['SCHEDULED','TIMED'])->whereBetween('match_date',[$now,$now->copy()->addDays(7)])->orderBy('match_date')->take(4)->get();
        $results=FootballMatch::with($relations)->whereIn('status',['FINISHED','AWARDED'])->whereBetween('match_date',[$now->copy()->subDays(2),$now])->orderByDesc('match_date')->take(4)->get();
        return response()->json(['html'=>view('partials.home-pulse',['liveMatches'=>$live,'upcomingMatches'=>$upcoming,'recentResults'=>$results])->render(),'has_live'=>$live->isNotEmpty(),'updated_at'=>$now->toIso8601String()]);
    }

    public function index()
    {
        try {
            $now = now();
            $today = $now->toDateString();
            $matchRelations = ['homeTeam', 'awayTeam', 'league'];
            $todaysMatches = FootballMatch::with([
                'homeTeam', 
                'awayTeam', 
                'league',
                'events' => function($query) {
                    $query->whereIn('type', ['goal', 'penalty_goal', 'own_goal'])
                          ->orderBy('minute', 'asc');
                }
            ])
            ->whereBetween('match_date', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
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
            ->crediblyLive($now)
            ->orderBy('match_date', 'asc')
            ->get();

            $upcomingMatches = FootballMatch::with($matchRelations)
                ->whereIn('status', ['SCHEDULED', 'TIMED'])
                ->whereBetween('match_date', [$now, $now->copy()->addDays(7)])
                ->orderBy('match_date')
                ->take(6)
                ->get();

            $recentResults = FootballMatch::with($matchRelations)
                ->whereIn('status', ['FINISHED', 'AWARDED'])
                ->whereBetween('match_date', [$now->copy()->subDay(), $now])
                ->orderByDesc('match_date')
                ->take(6)
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

            $premierLeagueMatches = $todaysMatches->filter(fn ($match) =>
                strtoupper((string) $match->league?->short_code) === 'PL'
                || (strtolower((string) $match->league?->name) === 'premier league'
                    && in_array(strtoupper((string) $match->league?->country_code), ['GB', 'GBR', 'ENG'], true))
            )->values();
            $premierLeagueMatches->each(function($match){
                $match->setAttribute('war_home',$this->warriorFor($match->homeTeam?->name));
                $match->setAttribute('war_away',$this->warriorFor($match->awayTeam?->name));
            });
            $todayTeamIds = $todaysMatches->flatMap(fn ($match) => [$match->home_team_id, $match->away_team_id])->filter()->unique();
            $africanPlayersInFocus = Player::with('team')->active()
                ->whereIn('team_id', $todayTeamIds)->whereIn('nationality_code', DiscoveryController::AFRICA)
                ->orderBy('name')->take(18)->get();
            $matchByTeam=$todaysMatches->mapWithKeys(fn($match)=>[$match->home_team_id=>$match,$match->away_team_id=>$match]);
            $africanPlayersInFocus->each(function($player)use($matchByTeam,$today){
                $player->setAttribute('focus_match',$matchByTeam->get($player->team_id));
                $fresh=data_get($player->metadata,'api_football_stats.date')===$today;
                $stats=$fresh?data_get($player->metadata,'api_football_stats.statistics',[]):[];
                $player->setAttribute('focus_stats',$stats);
                $rows=collect($stats)->flatMap(fn($values,$group)=>is_array($values)?collect($values)->filter(fn($value)=>$value!==null&&$value!=='')->map(fn($value,$label)=>['label'=>Str::headline($group).' · '.Str::headline($label),'value'=>is_bool($value)?($value?'Yes':'No'):$value]):[])->values();
                $player->setAttribute('focus_stat_rows',$rows);
            });

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
                'upcomingMatches',
                'recentResults',
                'featuredBlogs',
                'matchPreviews',
                'featuredPreviews',
                'today',
                'hasMatchesToday',
                'hasLiveMatches',
                'hasFeaturedBlogs',
                'hasMatchPreviews',
                'hasFeaturedPreviews','followedTeams','personalMatches','openPredictionSets',
                'premierLeagueMatches','africanPlayersInFocus'
            ));
        } catch (\Exception $e) {
            // Log the error and return a simple view
            Log::error('HomeController error: ' . $e->getMessage());
            
            return view('home', [
                'todaysMatches' => collect(),
                'featuredLeagues' => collect(),
                'liveMatches' => collect(),
                'upcomingMatches' => collect(),
                'recentResults' => collect(),
                'featuredBlogs' => collect(),
                'matchPreviews' => collect(),
                'featuredPreviews' => collect(),
                'today' => now()->format('Y-m-d'),
                'hasMatchesToday' => false,
                'hasLiveMatches' => false,
                'hasFeaturedBlogs' => false,
                'hasMatchPreviews' => false,
                'hasFeaturedPreviews' => false,
                'followedTeams' => collect(), 'personalMatches' => collect(), 'openPredictionSets' => collect(),
                'premierLeagueMatches' => collect(), 'africanPlayersInFocus' => collect()
            ]);
        }
    }

    private function warriorFor(?string $name): array
    {
        $key=strtolower(preg_replace('/ FC$/i','',(string)$name));
        $roster=[
            'arsenal'=>['arsenal.png','Roman Legion'],'coventry city'=>['coventry.png','Zulu Impi'],
            'aston villa'=>['aston-villa.png','Anglo-Saxon Housecarls'],'afc bournemouth'=>['bournemouth.png','Barbary Corsairs'],
            'bournemouth'=>['bournemouth.png','Barbary Corsairs'],'brentford'=>['brentford.png','Prussian Line Infantry'],
            'brighton & hove albion'=>['brighton.png','Byzantine Cataphracts'],'chelsea'=>['chelsea.png','Ottoman Janissaries'],
            'crystal palace'=>['crystal-palace.png','Saxon Fyrd'],'everton'=>['everton.png','Norman Knights'],
            'fulham'=>['fulham.png','Venetian Marines'],'hull city'=>['hull.png','Numidian Cavalry'],
            'ipswich town'=>['ipswich.png','Rus’ Varangian Guard'],'leeds united'=>['leeds.png','White Rose Yorkist Army'],
            'liverpool'=>['liverpool.png','Norse Vikings'],'manchester city'=>['manchester-city.png','Mongol Horde'],
            'manchester united'=>['manchester-united.png','Napoleonic Grenadiers'],'newcastle united'=>['newcastle.png','Norse-Northumbrian Raiders'],
            'nottingham forest'=>['nottingham-forest.png','Robin Hood’s Outlaws'],'sunderland'=>['sunderland.png','Northern Rebel Clans'],
            'tottenham hotspur'=>['tottenham.png','Spartan Hoplites'],'west ham united'=>['west-ham.png','Rebel Irons'],
            'wolverhampton wanderers'=>['wolves.png','Wolf Guard'],
        ];
        $entry=$roster[$key]??[null,'The Home Army'];
        return ['image'=>$entry[0]?asset('war/warriors/'.$entry[0]):null,'faction'=>$entry[1]];
    }
}
