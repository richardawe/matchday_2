<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\MatchMoment;
use App\Support\Armies;
use Illuminate\Support\Collection;

/**
 * Turns a Premier League match's live state and incidents into story chapters
 * (match_moments): kick-off, each goal and red card, half-time and full time.
 * Safe to run repeatedly: chapters are keyed, and a goal the feed withdraws (VAR) is removed.
 */
class MatchStoryService
{
    private const STARTED = ['IN_PLAY', 'PAUSED', 'LIVE', '1H', '2H', 'HT', 'ET', 'PENALTY_SHOOTOUT', 'FINISHED', 'FT'];

    /** False until `php artisan migrate` has created the table, so pages keep working before that. */
    public function ready(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasTable('match_moments');
    }

    /** Live and recently finished Premier League matches. */
    public function syncRecent(): int
    {
        $matches = FootballMatch::with(['league', 'homeTeam', 'awayTeam', 'events'])
            ->whereIn('status', self::STARTED)
            ->where('match_date', '>=', now('UTC')->subHours(5))
            ->get()
            ->filter(fn ($match) => $this->tellsStory($match));

        return $matches->sum(fn ($match) => $this->sync($match));
    }

    /** Chapters only for matches where both clubs have an army. */
    public function tellsStory(FootballMatch $match): bool
    {
        $league = $match->league;

        return ($league?->short_code === 'PL' || $league?->espn_slug === 'eng.1')
            && Armies::for($match->homeTeam) && Armies::for($match->awayTeam);
    }

    /** Returns how many chapters were created. */
    public function sync(FootballMatch $match): int
    {
        if (!in_array($match->status, self::STARTED, true)) {
            return 0;
        }
        $match->loadMissing(['events']);
        $created = 0;
        $put = function (string $key, array $values) use ($match, &$created) {
            $moment = MatchMoment::firstOrNew(['key' => "{$match->id}:{$key}"]);
            $created += $moment->exists ? 0 : 1;
            $moment->fill(['match_id' => $match->id] + $values)->save();
        };

        $put('kickoff', ['type' => 'kickoff', 'home_score' => 0, 'away_score' => 0]);

        $incidents = $match->events
            ->whereIn('type', ['goal', 'red_card'])
            ->sortBy([['minute', 'asc'], ['extra_minute', 'asc'], ['sort_order', 'asc']])
            ->values();
        [$home, $away] = [0, 0];
        foreach ($incidents as $event) {
            $side = $event->team_id === $match->home_team_id ? 'home' : 'away';
            $angle = null;
            if ($event->type === 'goal') {
                [$before, $against] = $side === 'home' ? [$home, $away] : [$away, $home];
                $side === 'home' ? $home++ : $away++;
                $angle = $this->goalAngle($before, $against, (int) $event->minute, $match);
            }
            $put("{$event->type}:{$event->id}", [
                'type' => $event->type, 'side' => $side, 'minute' => $event->minute, 'extra_minute' => $event->extra_minute,
                'player_name' => $event->player_name, 'assist_name' => $event->assist_player_name,
                'home_score' => $home, 'away_score' => $away, 'angle' => $angle, 'match_event_id' => $event->id,
            ]);
        }
        // Withdrawn incidents (a goal ruled out after the feed reported it).
        // Deleting the event nulls match_event_id, so orphaned rows count as withdrawn too.
        MatchMoment::where('match_id', $match->id)->whereIn('type', ['goal', 'red_card'])
            ->where(fn ($q) => $q->whereNull('match_event_id')->orWhereNotIn('match_event_id', $incidents->pluck('id')->all() ?: [0]))
            ->delete();

        $score = ['home_score' => (int) ($match->home_score ?? $home), 'away_score' => (int) ($match->away_score ?? $away)];
        if (in_array($match->status, ['PAUSED', 'HT'], true) && (int) $match->minute <= 50) {
            $put('half_time', ['type' => 'half_time', 'minute' => 45] + $score);
        }
        if (in_array($match->status, ['FINISHED', 'FT'], true)) {
            $put('full_time', ['type' => 'full_time', 'minute' => 90, 'side' => $this->winner($score)] + $score);
        }

        return $created;
    }

    /** The chapters of a match in story order. */
    public function chapters(FootballMatch $match): Collection
    {
        $order = array_flip(MatchMoment::TYPES);

        return MatchMoment::where('match_id', $match->id)->get()->sortBy([
            fn ($a, $b) => ($a->type === 'kickoff' ? -1 : ($a->type === 'full_time' ? 999 : ($a->minute ?? 0)))
                <=> ($b->type === 'kickoff' ? -1 : ($b->type === 'full_time' ? 999 : ($b->minute ?? 0))),
            fn ($a, $b) => ($a->extra_minute ?? 0) <=> ($b->extra_minute ?? 0),
            fn ($a, $b) => $order[$a->type] <=> $order[$b->type],
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();
    }

    private function goalAngle(int $before, int $against, int $minute, FootballMatch $match): string
    {
        return match (true) {
            $before === 0 && $against === 0 => $minute >= 85 ? 'late_winner' : 'opener',
            $before + 1 === $against => 'equaliser',
            $before === $against => $minute >= 85 ? 'late_winner' : 'lead',
            $before > $against => 'extend',
            default => 'consolation',
        };
    }

    private function winner(array $score): ?string
    {
        return $score['home_score'] === $score['away_score'] ? null : ($score['home_score'] > $score['away_score'] ? 'home' : 'away');
    }
}
