<?php

namespace App\Http\Controllers;

use App\Models\League;
use App\Models\Player;
use App\Models\Slideshow;
use App\Models\StatsbombPlayerMatch;
use App\Services\PlayerPages;
use App\Support\AfricanScope;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * /african-players: African internationals and players of confirmed African descent. The index,
 * the weekly roster per league (Premier League first), each player's page and the AFCON history.
 */
class DiscoveryController extends Controller
{
    public const AFRICA = ['NGA', 'GHA', 'SEN', 'CIV', 'MAR', 'EGY', 'TUN', 'ALG', 'CMR', 'MLI', 'GUI', 'GAB', 'RSA', 'ZAM', 'ZIM', 'COD',
        'CGO', 'BFA', 'BEN', 'TOG', 'KEN', 'UGA', 'TZA', 'ANG', 'MOZ', 'CPV', 'GMB', 'SLE', 'EQG', 'GNB', 'LBR', 'MWI', 'NAM', 'NER',
        'RWA', 'SDN', 'SSD', 'BDI', 'CAF', 'CHA', 'COM', 'DJI', 'ERI', 'ETH', 'LES', 'LBY', 'MAD', 'MRI', 'MTN', 'SEY', 'SOM', 'SWZ', 'BOT',
        // FIFA codes the player sync writes where the list above has ISO ones (Tanzania, Gambia, Niger), and São Tomé.
        'TAN', 'GAM', 'NIG', 'STP'];

    public function __construct(private PlayerPages $pages) {}

    public function index(Request $r)
    {
        $q = AfricanScope::scope(Player::with('team')->active());
        if (AfricanScope::heritageReady()) {
            $q->with('heritages');
        }
        if ($r->filled('country')) {
            $code = strtoupper((string) $r->country);
            $q->where(fn ($w) => $w->where('nationality_code', $code)
                ->when(AfricanScope::heritageReady(), fn ($w) => $w->orWhereHas('heritages', fn ($h) => $h->confirmed()->where('country_code', $code))));
        }
        if ($r->filled('search')) {
            $q->where('name', 'like', '%'.$r->search.'%');
        }
        $players = $q->orderBy('name')->paginate(24)->withQueryString();
        $countries = collect((array) config('slideshow.nations'))
            ->filter(fn ($row, $code) => in_array($code, self::AFRICA, true))
            ->map(fn ($row, $code) => ['code' => $code, 'name' => $row[0]])->unique('name')->sortBy('name')->values();
        $page = $players->currentPage() > 1 ? ' (page '.$players->currentPage().')' : '';

        return view('discovery.index', compact('players', 'countries'))->with('content', [
            'title' => 'African players and players of African descent'.$page.' | Matchday Africa',
            'description' => 'Every African international and player of confirmed African descent in the leagues Matchday Africa covers, Premier League first: clubs, fixtures and numbers.',
            'url' => route('discovery.index'),
        ]);
    }

    /** The weekly roster: /african-players/premier-league, or /african-players/league/{code} for another league. */
    public function roster(?string $code = null)
    {
        $league = $code
            ? League::where('short_code', $code)->orWhere('espn_slug', $code)->first()
            : PlayerPages::premierLeague();
        abort_unless($league, 404);
        $roster = $this->pages->roster($league);
        $asOf = Carbon::parse($roster['as_of'])->setTimezone('Europe/London');
        $isPl = $code === null || \App\Services\Slideshow\SlideshowFacts::isPremierLeague($league);

        return view('discovery.roster', [
            'roster' => $roster, 'league' => $league, 'asOf' => $asOf, 'leagues' => $this->pages->leagues(),
            'cta' => $this->rosterCta($roster),
            'pageUrl' => $isPl ? route('discovery.roster') : route('discovery.league', $league->short_code ?: $league->espn_slug),
        ])->with('content', [
            'title' => "African players in the {$league->name} this week, club by club | Matchday Africa",
            'description' => "Every African international and player of confirmed African descent at {$league->name} clubs, grouped by club, with this season's numbers. As of {$asOf->format('j M Y')}.",
            'url' => $isPl ? route('discovery.roster') : route('discovery.league', $league->short_code ?: $league->espn_slug),
        ]);
    }

    public function history(Request $r)
    {
        $q = StatsbombPlayerMatch::query();
        if ($r->filled('search')) {
            $q->where('player_name', 'like', '%'.$r->search.'%');
        }
        $players = $q->selectRaw('player_name, nickname, country, team, count(*) appearances, sum(started) starts, sum(minutes) minutes, sum(goals) goals, sum(assists) assists, sum(shots) shots, sum(passes) passes, sum(completed_passes) completed_passes, sum(key_passes) key_passes, sum(tackles) tackles, sum(interceptions) interceptions')
            ->groupBy('player_name', 'nickname', 'country', 'team')->orderByDesc('goals')->orderByDesc('assists')->paginate(30)->withQueryString();

        return view('discovery.history', compact('players'))->with('content', [
            'title' => 'AFCON player history from StatsBomb open data | Matchday Africa',
            'description' => 'Appearances, goals, assists and more for every player in the AFCON matches StatsBomb has released as open data.',
            'url' => route('discovery.history'),
        ]);
    }

    public function show(Player $player)
    {
        $player->load(['team']);
        if (AfricanScope::heritageReady()) {
            $player->load('heritages');
        }
        // African internationals, and players whose African heritage an editor has confirmed with a source.
        abort_unless(AfricanScope::inScope($player), 404);

        $league = PlayerPages::premierLeague();
        $inLeague = $league && $player->team_id && $this->pages->clubMatches($player->team_id, $league)->isNotEmpty();
        $season = $inLeague ? $this->pages->season($player, $league) : [];
        $fixtures = $this->pages->fixtures($player, $inLeague ? $league : null);
        $history = StatsbombPlayerMatch::where('player_id', $player->id)->orWhere('player_name', $player->name)->latest('match_date')->get();
        $slideshows = Slideshow::forPlayer($player->id);
        $label = AfricanScope::label($player);
        $heritage = AfricanScope::isNational($player) ? null : AfricanScope::confirmedHeritage($player);
        $photo = $this->photo($player);
        $club = $player->team ? \App\Support\ClubName::full($player->team) : null;
        $description = trim($player->name.' ('.$label.')'.($club ? ", {$club}" : '').'. '
            .($inLeague ? 'This season\'s '.$league->name.' numbers, fixtures and slideshows.' : 'Fixtures, numbers and slideshows.'));

        return view('discovery.show', [
            'player' => $player, 'history' => $history, 'slideshows' => $slideshows, 'label' => $label, 'heritage' => $heritage,
            'season' => $season, 'league' => $inLeague ? $league : null, 'fixtures' => $fixtures, 'cta' => $this->pages->cta($player),
            'photo' => $photo, 'club' => $club,
        ])->with('content', [
            'title' => "{$player->name}: {$label}".($club ? ", {$club}" : '').' | Matchday Africa',
            'description' => $description,
            'url' => route('discovery.show', $player),
            'type' => 'profile',
            'image' => $photo['url'] ?? null,
            'json_ld' => array_filter([
                '@context' => 'https://schema.org', '@type' => 'Person', 'name' => $player->name,
                'url' => route('discovery.show', $player), 'image' => $photo['url'] ?? null,
                'jobTitle' => 'Footballer',
                'nationality' => $player->nationality ? ['@type' => 'Country', 'name' => $player->nationality] : null,
                'memberOf' => $club ? ['@type' => 'SportsTeam', 'name' => $club, 'sport' => 'Football'] : null,
                'birthDate' => $player->date_of_birth?->toDateString(),
            ]),
        ]);
    }

    /** The page photo: the credited Commons photo when one was found for slideshows, else the site's own. */
    private function photo(Player $player): ?array
    {
        $commons = data_get($player->metadata, 'commons_photo');
        if (($commons['status'] ?? null) === 'ok' && is_file(storage_path('app/'.$commons['path']))) {
            return ['url' => route('discovery.photo', $player), 'credit' => $commons['credit'] ?? null, 'source' => $commons['source_url'] ?? null];
        }

        return $player->photo_url ? ['url' => $player->photo_url, 'credit' => null, 'source' => null] : null;
    }

    /** The larger Commons photo for the page (licensed for reuse with its credit, which the page shows). */
    public function photoFile(Player $player)
    {
        $commons = data_get($player->metadata, 'commons_photo');
        abort_unless(AfricanScope::inScope($player) && ($commons['status'] ?? null) === 'ok', 404);
        $file = storage_path('app/'.$commons['path']);
        abort_unless(is_file($file), 404);

        return response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=604800']);
    }

    private function rosterCta(array $roster): ?array
    {
        $teamIds = collect($roster['clubs'])->pluck('team_id')->filter()->all();
        $next = $teamIds ? \App\Models\FootballMatch::with(['homeTeam', 'awayTeam'])->where('league_id', $roster['league']->id)
            ->whereIn('status', PlayerPages::UPCOMING)->where('match_date', '>', now('UTC')->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
            ->orderBy('match_date')->first() : null;
        if ($next) {
            return ['label' => 'Call the score: '.\App\Support\ClubName::short($next->homeTeam).' v '.\App\Support\ClubName::short($next->awayTeam),
                'href' => route('matches.show', $next).'#prediction-arena'];
        }

        return \App\Services\Griot\GriotChallengeService::ready() ? ['label' => 'Beat the Griot', 'href' => route('griot.challenge')] : null;
    }
}
