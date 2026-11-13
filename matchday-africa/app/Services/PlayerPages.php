<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Player;
use App\Services\Griot\GriotChallengeService;
use App\Services\Slideshow\SlideshowFacts;
use App\Support\AfricanScope;
use App\Support\ClubName;
use App\Support\PlayerNames;
use App\Support\SocialLink;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the player pages and the weekly roster show, from the database only: the player's club,
 * nationality or confirmed heritage, this season's league numbers when they are complete, the club's
 * fixtures, and the next match to call. A number we don't have is left out, never shown as zero.
 */
class PlayerPages
{
    public const FINISHED = ['FINISHED', 'FT', 'AWARDED'];
    public const UPCOMING = ['SCHEDULED', 'TIMED', 'NS', 'PRE'];

    /** The player's page, optionally tagged for a social channel (utm_medium=social). */
    public static function url(Player $player, ?string $channel = null, string $campaign = 'player', ?string $content = null): string
    {
        $url = route('discovery.show', $player);

        return $channel ? SocialLink::to($url, $channel, $campaign, $content ?? "player-{$player->id}") : $url;
    }

    /** Where a post about this player should land: the player's page when they have one, else the match page. */
    public static function landing(Player $player, ?FootballMatch $match = null): string
    {
        return AfricanScope::inScope($player) || !$match ? route('discovery.show', $player, false) : route('matches.show', $match, false);
    }

    /** The Premier League (short code PL or ESPN slug eng.1). */
    public static function premierLeague(): ?League
    {
        return League::where('short_code', 'PL')->orWhere('espn_slug', 'eng.1')->orderByRaw("case when short_code = 'PL' then 0 else 1 end")->first();
    }

    /** July 1st of the season containing $at (UTC). */
    public static function seasonStart(?Carbon $at = null): Carbon
    {
        $at ??= now('UTC');

        return Carbon::create($at->month >= 7 ? $at->year : $at->year - 1, 7, 1, 0, 0, 0, 'UTC');
    }

    /**
     * This season's league numbers for the player's club. Goals and assists come from match events
     * (only where every match is synced with events); starts from stored API-Football line-ups, and
     * only when every finished league match of the club has one. Keys missing = not known.
     */
    public function season(Player $player, ?League $league): array
    {
        if (!$league || !$player->team_id) {
            return [];
        }
        $out = [];
        if ($totals = SlideshowFacts::playerSeason($player, $league)) {
            $out['goals'] = $totals['goals'];
            $out['assists'] = $totals['assists'];
        }
        $matches = $this->clubMatches($player->team_id, $league)->filter(fn ($m) => in_array($m->status, self::FINISHED, true));
        if ($matches->isNotEmpty() && ($starts = $this->starts($player, $matches)) !== null) {
            $out += $starts;
        }
        $stats = data_get($player->metadata, 'api_football_stats');
        if ($stats && data_get($stats, 'date') && Carbon::parse(data_get($stats, 'date'))->gte(now('UTC')->subDays(10))
            && is_numeric(data_get($stats, 'statistics.games.minutes'))) {
            $out['last_match_minutes'] = (int) data_get($stats, 'statistics.games.minutes');
            $out['last_match_id'] = (int) data_get($stats, 'match_id');
        }

        return $out;
    }

    /**
     * Starts and matchday squads from the line-ups stored on each match; null unless every one of the
     * club's finished matches has its line-up (a partial count is never shown as a season total).
     */
    private function starts(Player $player, Collection $matches): ?array
    {
        $starts = 0;
        $squads = 0;
        foreach ($matches as $m) {
            $side = collect(data_get($m->metadata, 'api_football_lineups'))->first(fn ($s) => $this->sameClub((string) ($s['team'] ?? ''), $player));
            if (!$side) {
                return null;
            }
            $named = fn (array $rows) => collect($rows)->contains(fn ($r) => PlayerNames::same($player->name, (string) ($r['name'] ?? '')));
            $starts += $named($side['starters'] ?? []) ? 1 : 0;
            $squads += $named(array_merge($side['starters'] ?? [], $side['substitutes'] ?? [])) ? 1 : 0;
        }

        return ['starts' => $starts, 'matchday_squads' => $squads];
    }

    private function sameClub(string $name, Player $player): bool
    {
        return \App\Support\Armies::key($name) === \App\Support\Armies::key((string) $player->team?->name);
    }

    /** The club's matches in this league this season, oldest first. */
    public function clubMatches(int $teamId, League $league): Collection
    {
        return FootballMatch::with(['homeTeam', 'awayTeam', 'league'])->where('league_id', $league->id)
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->where('match_date', '>=', self::seasonStart()->format('Y-m-d H:i:s'))
            ->orderBy('match_date')->get();
    }

    /** ['upcoming' => next 3, 'recent' => last 5 results] for the club, in this league (or any, without one). */
    public function fixtures(Player $player, ?League $league = null): array
    {
        if (!$player->team_id) {
            return ['upcoming' => collect(), 'recent' => collect()];
        }
        $base = fn () => FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->when($league, fn ($q) => $q->where('league_id', $league->id));
        $now = now('UTC')->format('Y-m-d H:i:s');

        return [
            'upcoming' => $base()->whereIn('status', self::UPCOMING)->where('match_date', '>', $now)->orderBy('match_date')->limit(3)->get(),
            'recent' => $base()->whereIn('status', self::FINISHED)->whereNotNull('home_score')->orderByDesc('match_date')->limit(5)->get(),
        ];
    }

    /** The call to action: the club's next match to call, else Beat the Griot when a round is open, else null. */
    public function cta(Player $player): ?array
    {
        $next = $this->fixtures($player)['upcoming']->first();
        if ($next) {
            return ['label' => 'Call the score: '.ClubName::short($next->homeTeam).' v '.ClubName::short($next->awayTeam),
                'href' => route('matches.show', $next).'#prediction-arena', 'match' => $next];
        }

        return GriotChallengeService::ready() ? ['label' => 'Beat the Griot', 'href' => route('griot.challenge'), 'match' => null] : null;
    }

    /** Leagues with in-scope players, Premier League first, then config/competitions.php order. */
    public function leagues(): Collection
    {
        return League::query()->get()
            ->filter(fn (League $l) => $l->competition_rank < count((array) config('competitions.order')))
            ->sortBy(fn (League $l) => [SlideshowFacts::isPremierLeague($l) ? 0 : 1, $l->competition_rank])
            ->values();
    }

    /**
     * The roster for one league: in-scope players at clubs that play in it this season, grouped by club
     * (alphabetical), each with what we know. Cached for six hours; players:roster rebuilds it.
     *
     * @return array{league: League, as_of: string, clubs: array<int, array{club: string, team_id: int, players: array}>, count: int}
     */
    public function roster(League $league, bool $fresh = false): array
    {
        $key = "players:roster:{$league->id}";
        if ($fresh) {
            Cache::forget($key);
        }
        $data = Cache::remember($key, now()->addHours(6), fn () => $this->buildRoster($league));
        $data['league'] = $league;

        return $data;
    }

    private function buildRoster(League $league): array
    {
        $start = self::seasonStart()->format('Y-m-d H:i:s');
        $teamIds = FootballMatch::where('league_id', $league->id)->where('match_date', '>=', $start)
            ->get(['home_team_id', 'away_team_id'])->flatMap(fn ($m) => [$m->home_team_id, $m->away_team_id])->unique()->filter()->values()->all();
        $players = AfricanScope::playersAt($teamIds);
        $clubs = $players->groupBy('team_id')->map(function (Collection $group) use ($league) {
            $team = $group->first()->team;

            return [
                'club' => ClubName::full($team),
                'team_id' => $team?->id,
                'players' => $group->sortBy('name')->map(fn (Player $p) => [
                    'id' => $p->id, 'name' => $p->name, 'position' => $p->position, 'shirt' => $p->shirt_number,
                    'label' => AfricanScope::label($p), 'national' => AfricanScope::isNational($p),
                    'flag' => AfricanScope::isNational($p) ? $p->nationality_flag : '',
                    'photo' => $p->photo_url, 'season' => $this->season($p, $league),
                ])->values()->all(),
            ];
        })->sortBy('club')->values()->all();

        return ['as_of' => now('UTC')->toIso8601ZuluString(), 'clubs' => $clubs, 'count' => $players->count()];
    }
}
