<?php

namespace App\Services\Slideshow;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Standing;
use App\Models\Team;
use App\Support\AfricanScope;
use App\Support\Armies;
use App\Support\ClubName;
use App\Support\PlayerNames;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Facts for slideshows, read only from the database: kick-off times, venue, form, standings,
 * goals and assists from match events, minutes and starts from API-Football line-ups and ratings.
 * A fact that isn't stored comes back null and the slide or line is dropped; nothing is estimated.
 */
class SlideshowFacts
{
    /** Leagues where every match is synced with its events, so season totals from events are complete. */
    public static function seasonTotalsTrusted(?League $league): bool
    {
        return self::isPremierLeague($league);
    }

    public static function isPremierLeague(?League $league): bool
    {
        return $league && ($league->short_code === 'PL' || $league->espn_slug === 'eng.1');
    }

    public static function kickoff(FootballMatch $match): Carbon
    {
        return Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
    }

    /** ['uk' => 'Sat 15 Nov, 15:00 GMT', 'wat' => '16:00 WAT', 'day' => 'Sat 15 Nov'] */
    public static function times(FootballMatch $match): array
    {
        $ko = self::kickoff($match);
        $uk = $ko->copy()->setTimezone('Europe/London');

        return [
            'utc' => $ko->toIso8601ZuluString(),
            'day' => $uk->format('D j M'),
            'uk' => $uk->format('H:i T'),
            'wat' => $ko->copy()->setTimezone('Africa/Lagos')->format('H:i').' WAT',
        ];
    }

    public static function finished(FootballMatch $match): bool
    {
        return in_array($match->status, ['FINISHED', 'FT', 'AWARDED'], true) && $match->home_score !== null && $match->away_score !== null;
    }

    /** A club's (or national team's) two colours: armies config, the club table, then the nation's flag. */
    public static function colours(?Team $team): ?array
    {
        if (!$team) {
            return null;
        }
        if ($army = Armies::for($team)) {
            return array_slice($army['colours'], 0, 2);
        }
        $key = Armies::key((string) $team->name);
        $table = (array) config('slideshow.club_colours');
        if (isset($table[$key])) {
            return $table[$key];
        }
        $short = mb_strtolower(trim(ClubName::short($team)));
        if (isset($table[$short])) {
            return $table[$short];
        }

        return AfricanScope::nationForTeam($team->name)['colours'] ?? null;
    }

    /** Last results before the match, newest first: [['result' => 'W', 'score' => '2-1', 'opponent' => 'Chelsea'], …]. */
    public static function form(Team $team, FootballMatch $before, int $limit = 5): array
    {
        return FootballMatch::with(['homeTeam', 'awayTeam'])
            ->where(fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->where('id', '!=', $before->id)
            ->where('match_date', '<', $before->match_date)
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->orderByDesc('match_date')->limit($limit)->get()
            ->map(function (FootballMatch $m) use ($team) {
                $home = $m->home_team_id === $team->id;
                [$for, $against] = $home ? [(int) $m->home_score, (int) $m->away_score] : [(int) $m->away_score, (int) $m->home_score];

                return ['result' => $for > $against ? 'W' : ($for < $against ? 'L' : 'D'), 'score' => "{$for}-{$against}",
                    'opponent' => ClubName::short($home ? $m->awayTeam : $m->homeTeam)];
            })->all();
    }

    /** The team's current table row in this league: ['position', 'points', 'played'], or null. */
    public static function standing(Team $team, ?League $league): ?array
    {
        if (!$league) {
            return null;
        }
        $row = Standing::where('team_id', $team->id)->where('league_id', $league->id)
            ->when(Standing::query()->where('league_id', $league->id)->where('is_current', true)->exists(), fn ($q) => $q->where('is_current', true))
            ->orderByDesc('season_year')->orderByDesc('updated_at')->first();
        if (!$row || !$row->position) {
            return null;
        }

        return ['position' => (int) $row->position, 'points' => $row->points !== null ? (int) $row->points : null,
            'played' => $row->matches_played !== null ? (int) $row->matches_played : null];
    }

    /** Goals scored in a match, in order: ['minute' => "41'", 'player' => …, 'side' => 'home', 'own_goal' => bool, 'penalty' => bool]. */
    public static function goals(FootballMatch $match): array
    {
        return MatchEvent::where('match_id', $match->id)->where('type', 'goal')
            ->orderBy('minute')->orderBy('extra_minute')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MatchEvent $e) => [
                'minute' => $e->minute !== null ? $e->minute.($e->extra_minute ? '+'.$e->extra_minute : '')."'" : null,
                'player' => trim((string) $e->player_name) ?: null,
                'assist' => trim((string) $e->assist_player_name) ?: null,
                'side' => $e->team_id === $match->home_team_id ? 'home' : 'away',
                'own_goal' => (bool) $e->is_own_goal,
                'penalty' => (bool) $e->is_penalty,
            ])->all();
    }

    /**
     * What one player did in one match, from stored data only: goals and assists from the match's
     * events; starter, minutes and rating from API-Football (only when it is about this match).
     */
    public static function playerInMatch(Player $player, FootballMatch $match): array
    {
        $events = MatchEvent::where('match_id', $match->id)->where('type', 'goal')->where('team_id', $player->team_id)->get();
        $goals = $events->filter(fn ($e) => !$e->is_own_goal && $e->player_name && PlayerNames::same($player->name, $e->player_name))->count();
        $assists = $events->filter(fn ($e) => $e->assist_player_name && PlayerNames::same($player->name, $e->assist_player_name))->count();
        $lineup = data_get($player->metadata, 'api_football_lineup');
        $stats = data_get($player->metadata, 'api_football_stats');
        $lineupHere = (int) data_get($lineup, 'match_id') === $match->id;
        $statsHere = (int) data_get($stats, 'match_id') === $match->id;
        $minutes = $statsHere ? data_get($stats, 'statistics.games.minutes') : null;
        $rating = $statsHere ? data_get($stats, 'statistics.games.rating') : null;
        $played = $goals > 0 || $assists > 0 || $lineupHere || ($minutes !== null && (int) $minutes > 0);

        return [
            'goals' => $goals,
            'assists' => $assists,
            'starter' => $lineupHere ? (bool) data_get($lineup, 'starter') : null,
            'in_squad' => $lineupHere ?: null,
            'minutes' => $minutes !== null && is_numeric($minutes) ? (int) $minutes : null,
            'rating' => $rating !== null && is_numeric($rating) ? round((float) $rating, 1) : null,
            'played' => $played,
        ];
    }

    /**
     * Season goals and assists in this league from match events. Only for leagues whose every match
     * is synced with events (seasonTotalsTrusted); null elsewhere, so no partial count is shown as a total.
     */
    public static function playerSeason(Player $player, ?League $league, ?Carbon $until = null): ?array
    {
        if (!self::seasonTotalsTrusted($league) || !$player->team_id) {
            return null;
        }
        $until ??= now('UTC');
        $start = Carbon::create($until->month >= 7 ? $until->year : $until->year - 1, 7, 1, 0, 0, 0, 'UTC');
        $matchIds = FootballMatch::where('league_id', $league->id)
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])
            ->where('match_date', '>=', $start->format('Y-m-d H:i:s'))
            ->where('match_date', '<=', $until->format('Y-m-d H:i:s'))
            ->pluck('id');
        if ($matchIds->isEmpty()) {
            return null;
        }
        $events = MatchEvent::whereIn('match_id', $matchIds)->where('type', 'goal')->where('team_id', $player->team_id)->get();

        return [
            'goals' => $events->filter(fn ($e) => !$e->is_own_goal && $e->player_name && PlayerNames::same($player->name, $e->player_name))->count(),
            'assists' => $events->filter(fn ($e) => $e->assist_player_name && PlayerNames::same($player->name, $e->assist_player_name))->count(),
            'league' => $league->name,
        ];
    }

    /** In-scope players at both clubs, best hook first: starter here, then goals/assists, then season goals. */
    public static function inScopePlayers(FootballMatch $match): Collection
    {
        $match->loadMissing(['league']);
        $after = self::finished($match);

        return AfricanScope::playersAt([$match->home_team_id, $match->away_team_id])
            ->map(function (Player $player) use ($match, $after) {
                $here = self::playerInMatch($player, $match);
                $season = self::playerSeason($player, $match->league, self::kickoff($match)->copy()->subMinute());

                return ['player' => $player, 'match' => $here, 'season' => $season,
                    'score' => ($after ? ($here['goals'] * 30 + $here['assists'] * 20 + ($here['played'] ? 10 : 0)) : 0)
                        + ($here['starter'] ? 8 : 0) + ($season['goals'] ?? 0) * 3 + ($season['assists'] ?? 0) * 2
                        + (AfricanScope::isNational($player) ? 1 : 0)];
            })
            // After full time, only players the data shows taking part (when any line-up data exists).
            ->sortByDesc('score')->values();
    }
}
