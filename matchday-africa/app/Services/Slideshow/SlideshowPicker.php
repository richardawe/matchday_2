<?php

namespace App\Services\Slideshow;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Slideshow;
use App\Support\AfricanScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides which slideshows to make and in what order. Every qualifying subject gets a record
 * (nothing is skipped silently); the priority decides what renders, gets reviewed and uses the
 * YouTube quota first:
 *   band 4  the Premier League
 *   band 3  other European leagues and UEFA club competitions in config/competitions.php `order`
 *   band 2  African competitions (League::region africa: AFCON, CAF qualifiers) and other listed ones
 *   band 1  anything else with fixture data
 * then, within a band, the competition's place in `order`, kick-off proximity, the number of
 * in-scope players and hooks that are in the data.
 */
class SlideshowPicker
{
    public function __construct(private SlideshowDeck $deck) {}

    public static function band(?League $league): int
    {
        if (!$league) {
            return 1;
        }
        if (SlideshowFacts::isPremierLeague($league)) {
            return 4;
        }
        $order = (array) config('competitions.order');
        $listed = array_key_exists((string) $league->short_code, $order) || array_key_exists((string) $league->espn_slug, $order);
        if ($listed && $league->region === 'europe') {
            return 3;
        }
        if ($listed || $league->region === 'africa') {
            return 2;
        }

        return 1;
    }

    /** Higher first. */
    public function priority(string $type, ?FootballMatch $match, int $players = 0, int $hooks = 0, ?Carbon $now = null): int
    {
        $now ??= now('UTC');
        $band = $match ? self::band($match->league) : 4; // Team of the Week is a Premier League deck
        $rank = $match?->league ? min(99, (int) $match->league->competition_rank) : 0;
        $score = $band * 10_000 + (99 - $rank) * 10;
        $score += match ($type) {
            'post_match' => 1_400, 'milestone' => 1_300, 'totw' => 1_200,
            default => $this->proximity($match, $now),
        };

        return $score + min(5, $players) * 20 + min(3, $hooks) * 15;
    }

    /** Kick-off proximity for previews: today, then tomorrow, then later this week. */
    private function proximity(?FootballMatch $match, Carbon $now): int
    {
        if (!$match) {
            return 0;
        }
        $kickoff = SlideshowFacts::kickoff($match)->setTimezone('Europe/London');
        $today = $now->copy()->setTimezone('Europe/London')->startOfDay();
        $days = (int) floor($today->diffInDays($kickoff->copy()->startOfDay(), false));

        return match (true) {
            $days <= 0 => 1_000,
            $days === 1 => 800,
            $days <= 6 => 500 - $days * 20,
            default => 0,
        };
    }

    /**
     * Upcoming matches (kick-off within config slideshow.preview_hours) with an in-scope player in
     * either squad, or an African national team: [['match', 'type', 'players', 'priority'], …].
     */
    public function previews(?Carbon $now = null): Collection
    {
        $now ??= now('UTC');
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereIn('status', ['SCHEDULED', 'TIMED', 'NS', 'PRE'])
            ->where('match_date', '>', $now->format('Y-m-d H:i:s'))
            ->where('match_date', '<=', $now->copy()->addHours((int) config('slideshow.preview_hours', 48))->format('Y-m-d H:i:s'))
            ->get();

        return $this->qualify($matches, $now, 'preview');
    }

    /** Matches finished in the last few hours with an in-scope player or an African national team. */
    public function postMatches(?Carbon $now = null): Collection
    {
        $now ??= now('UTC');
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->where('match_date', '<=', $now->copy()->subMinutes((int) config('slideshow.post_match_after_minutes', 120))->format('Y-m-d H:i:s'))
            ->where('match_date', '>=', $now->copy()->subHours((int) config('slideshow.post_match_within_hours', 12))->format('Y-m-d H:i:s'))
            ->get()
            ->reject(fn (FootballMatch $m) => $m->statusInferred());

        return $this->qualify($matches, $now, 'post_match');
    }

    private function qualify(Collection $matches, Carbon $now, string $type): Collection
    {
        return $matches->map(function (FootballMatch $match) use ($now, $type) {
            $players = SlideshowFacts::inScopePlayers($match);
            $national = AfricanScope::nationForTeam($match->homeTeam?->name) || AfricanScope::nationForTeam($match->awayTeam?->name);
            if ($players->isEmpty() && !$national) {
                return null;
            }
            $hooks = $players->filter(fn ($p) => ($p['season']['goals'] ?? 0) > 0 || $p['match']['goals'] > 0 || $p['match']['assists'] > 0)->count();
            $kind = $type === 'preview' && $national ? 'national' : $type;

            return ['match' => $match, 'type' => $kind, 'players' => $players,
                'priority' => $this->priority($kind === 'national' ? 'preview' : $kind, $match, $players->count(), $hooks, $now)];
        })->filter()->sortByDesc('priority')->values();
    }

    /** Creates the records for every qualifying subject. Returns how many new records. */
    public function generate(string $what = 'all', ?Carbon $now = null): int
    {
        $now ??= now('UTC');
        $made = 0;
        if (in_array($what, ['all', 'daily', 'preview'], true)) {
            foreach ($this->previews($now) as $row) {
                $made += $this->record("{$row['type']}:{$row['match']->id}", $row['type'], $row['match'], $row['priority'], $row['players']->first()['player'] ?? null) ? 1 : 0;
            }
        }
        if (in_array($what, ['all', 'post_match'], true)) {
            foreach ($this->postMatches($now) as $row) {
                $made += $this->record("post_match:{$row['match']->id}", 'post_match', $row['match'], $row['priority'], $row['players']->first()['player'] ?? null) ? 1 : 0;
                $made += $this->milestones($row['match'], $row['players'], $now);
            }
        }
        if ($what === 'totw' || ($what === 'daily' && $now->copy()->setTimezone('Europe/London')->isMonday())) {
            $made += $this->totw($now) ? 1 : 0;
        }

        return $made;
    }

    /** Monday's Team of the Week from last week's Premier League matches; null when nobody in scope scored or assisted. */
    public function totw(?Carbon $now = null): ?Slideshow
    {
        $now ??= now('UTC');
        $start = $now->copy()->setTimezone('Europe/London')->startOfWeek()->subWeek();
        $startUtc = Carbon::parse($start->toDateString(), 'UTC');
        $rows = $this->deck->totwRows($startUtc);
        if ($rows->isEmpty()) {
            return null;
        }

        return $this->record('totw:'.$start->toDateString(), 'totw', null, $this->priority('totw', null, $rows->count(), $rows->count(), $now),
            $rows->first()['player'], ['week_start' => $start->toDateString(), 'range' => $this->deck->weekRange($startUtc)]);
    }

    /** A milestone deck when an in-scope player's goal in this match takes their season total to 5, 10, 15 … */
    private function milestones(FootballMatch $match, Collection $players, Carbon $now): int
    {
        $made = 0;
        foreach ($players as $entry) {
            if ($entry['match']['goals'] < 1) {
                continue;
            }
            $season = SlideshowFacts::playerSeason($entry['player'], $match->league, SlideshowFacts::kickoff($match)->addHours(4));
            $goals = (int) ($season['goals'] ?? 0);
            $before = $goals - $entry['match']['goals'];
            $mark = collect(SlideshowDeck::MILESTONES)->first(fn ($m) => $before < $m && $goals >= $m);
            if ($mark && $mark === $goals) {
                $made += $this->record("milestone:{$entry['player']->id}:{$goals}", 'milestone', $match,
                    $this->priority('milestone', $match, 1, 1, $now), $entry['player'], ['goals' => $goals]) ? 1 : 0;
            }
        }

        return $made;
    }

    /** Creates a record once per key (a later run never duplicates or resets one). Returns it when new. */
    private function record(string $key, string $type, ?FootballMatch $match, int $priority, $player, array $facts = []): ?Slideshow
    {
        $show = Slideshow::firstOrCreate(['key' => $key], [
            'type' => $type, 'match_id' => $match?->id, 'player_id' => $player?->id, 'status' => 'pending',
            'priority' => $priority, 'facts' => $facts ?: null,
        ]);

        return $show->wasRecentlyCreated ? $show : null;
    }
}
