<?php

namespace App\Services\Stream;

use App\Models\FootballMatch;
use App\Models\GriotChallengeMatch;
use App\Models\GriotPost;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\Griot\GriotChallengeService;
use App\Services\Griot\GriotTeller;
use App\Services\Griot\GriotTimeline;
use App\Services\PlayerPhotos;
use App\Services\ReelService;
use App\Support\Armies;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the stream screen shows right now, as one JSON snapshot: the live matches, the next
 * kick-off, later fixtures and today's results. The page compares snapshots to find new goals
 * and red cards, so every goal and card carries an id that stays the same between snapshots:
 * goals are numbered per side ("12:home:2" is the home side's second goal), which also works
 * before the feed has named the scorer; a goal ruled out drops the score and its id disappears.
 *
 * match_date holds UTC wall-clock values (AGENTS rule 5), so every time here is compared in UTC.
 */
class StreamFeed
{
    private const LIVE = ['LIVE', 'IN_PLAY', 'PAUSED', '1H', '2H', 'HT', 'ET', 'PENALTY_SHOOTOUT'];
    private const FINISHED = ['FINISHED', 'FT', 'AWARDED'];

    public function snapshot(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $wall = fn (Carbon $at) => $at->format('Y-m-d H:i:s');

        $live = $this->matches()
            ->whereIn('status', self::LIVE)
            ->whereBetween('match_date', [$wall($now->copy()->subHours(4)), $wall($now->copy()->addMinutes(30))])
            ->orderBy('match_date')->orderBy('id')->get();

        $upcoming = $this->matches()
            ->whereNotIn('status', array_merge(self::LIVE, self::FINISHED, ['POSTPONED', 'CANCELLED', 'SUSPENDED']))
            ->whereBetween('match_date', [$wall($now->copy()->subMinutes(15)), $wall($now->copy()->addDays(7))])
            ->orderBy('match_date')->orderBy('id')->limit(4)->get();

        $results = $this->matches()
            ->whereIn('status', self::FINISHED)
            ->whereBetween('match_date', [$wall($now->copy()->subHours((int) config('stream.results_hours', 12) + 3)), $wall($now)])
            ->orderByDesc('match_date')->orderByDesc('id')->limit(4)->get();

        $dataAt = $live->map(fn (FootballMatch $m) => $m->last_api_update ?? $m->updated_at)->filter()->max();
        $stale = $live->isNotEmpty() && (!$dataAt || $dataAt->diffInSeconds($now, true) > (int) config('stream.stale_seconds', 90));

        return [
            'now' => $now->toIso8601String(),
            'stale' => $stale,
            'live' => $live->map(fn (FootballMatch $m) => $this->live($m))->values()->all(),
            'next' => $upcoming->first() ? $this->fixture($upcoming->first(), true) : null,
            'later' => $upcoming->slice(1)->map(fn (FootballMatch $m) => $this->fixture($m))->values()->all(),
            'results' => $results->map(fn (FootballMatch $m) => $this->fixture($m, true))->values()->all(),
            'reel' => $live->isEmpty() ? $this->reel($results) : null,
        ];
    }

    /** Matches in the streamed competitions, with what the snapshot reads. */
    private function matches(): Builder
    {
        $codes = config('stream.competitions', []);

        return FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereHas('league', fn ($q) => $q->whereIn('short_code', $codes)->orWhereIn('espn_slug', $codes));
    }

    private function live(FootballMatch $match): array
    {
        $events = MatchEvent::where('match_id', $match->id)->whereIn('type', ['goal', 'red_card'])
            ->orderBy('minute')->orderBy('extra_minute')->orderBy('sort_order')->orderBy('id')->get();

        return $this->fixture($match, true) + [
            'clock' => $this->clock($match),
            'goals' => $this->goals($match, $events->where('type', 'goal')),
            'reds' => $events->where('type', 'red_card')->map(fn (MatchEvent $e) => [
                'id' => "{$match->id}:red:".($e->football_data_id ?: $e->id),
                'side' => $this->side($match, $e),
                'minute' => $this->minute($e),
                'player' => $this->name($e->player_name),
                'photo' => PlayerPhotos::forName($e->team_id, $e->player_name),
            ])->values()->all(),
            'stats' => [
                'possession' => $this->pair($match->home_possession, $match->away_possession),
                'shots' => $this->pair($match->home_shots, $match->away_shots),
            ],
            'griot' => $this->griot($match),
        ];
    }

    /**
     * One entry per goal in the score, numbered per side, with the scorer when the feed has named
     * them. A goal event's team is the side it counts for (ESPN files own goals under the team
     * that benefits), so goals are matched to the score side by side, in order.
     */
    public function goals(FootballMatch $match, Collection $goalEvents): array
    {
        $bySide = ['home' => [], 'away' => []];
        foreach ($goalEvents as $event) {
            $bySide[$this->side($match, $event)][] = $event;
        }

        $goals = [];
        foreach (['home' => (int) $match->home_score, 'away' => (int) $match->away_score] as $side => $count) {
            for ($n = 1; $n <= $count; $n++) {
                $event = $bySide[$side][$n - 1] ?? null;
                $goals[] = [
                    'id' => "{$match->id}:{$side}:{$n}",
                    'side' => $side,
                    'minute' => $event ? $this->minute($event) : null,
                    'scorer' => $event ? $this->name($event->player_name) : null,
                    // An own goal is filed under the side it counts for, so look the scorer up in both squads.
                    'photo' => $event ? (PlayerPhotos::forName($event->team_id, $event->player_name)
                        ?? ($event->is_own_goal ? PlayerPhotos::forName($side === 'home' ? $match->away_team_id : $match->home_team_id, $event->player_name) : null)) : null,
                    'own_goal' => (bool) $event?->is_own_goal,
                    'penalty' => (bool) $event?->is_penalty,
                ];
            }
        }

        return $goals;
    }

    private function fixture(FootballMatch $match, bool $full = false): array
    {
        $kickoff = Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
        $row = [
            'id' => $match->id,
            'status' => $match->status,
            'kickoff' => $kickoff->toIso8601String(),
            'kickoff_lagos' => $kickoff->copy()->setTimezone('Africa/Lagos')->format('H:i'),
            'score' => $match->home_score === null ? null : [(int) $match->home_score, (int) $match->away_score],
            'home' => $this->team($match->homeTeam, $full),
            'away' => $this->team($match->awayTeam, $full),
        ];
        if ($full) {
            $row['competition'] = $match->league?->name;
            $row['venue'] = $match->venue_name;
            $row['call'] = $this->call($match);
        }

        return $row;
    }

    private function team(?Team $team, bool $full): array
    {
        $name = trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) ($team?->display_name ?: $team?->name ?: 'TBC')));
        $army = $team ? Armies::for($team) : null;
        $colours = $army['colours'] ?? array_values(array_filter([$team?->primary_color, $team?->secondary_color]));
        $colour = $colours[0] ?? '#4a443b';
        $row = [
            'name' => $name,
            'short' => Str::upper($team?->short_code ?: Str::substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)),
            'colour' => $colour,
            'ink' => $this->ink($colour),
        ];
        if ($full) {
            $art = $army['art'] ?? null;
            $row['army'] = $army['army'] ?? null;
            $row['art'] = $art ? $army['cutout'] : null;
            $row['moods'] = $art ? collect(['warrior', 'victory', 'dejected', 'defeated'])
                ->mapWithKeys(fn ($mood) => [$mood => is_file(public_path(config('armies.optimised')."/moods/{$art}-{$mood}.webp"))
                    ? asset(config('armies.optimised')."/moods/{$art}-{$mood}.webp") : null])->filter()->all() : [];
        }

        return $row;
    }

    /** "67′", "45+2′", "HT", "ET" or "Pens". */
    private function clock(FootballMatch $match): string
    {
        return match ($match->status) {
            'HT', 'PAUSED' => 'HT',
            'PENALTY_SHOOTOUT' => 'Pens',
            default => $match->minute ? $match->minute.'′' : 'Live',
        };
    }

    /** The newest full-time reel among the results, for the between-matches screen. */
    private function reel(Collection $results): ?array
    {
        $reels = app(ReelService::class);
        foreach ($results as $match) {
            try {
                if ($url = $reels->url($match)) {
                    $short = fn (array $team) => $team['short'];
                    $row = $this->fixture($match);

                    return ['url' => $url, 'label' => "{$short($row['home'])} {$match->home_score}–{$match->away_score} {$short($row['away'])}"];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    /** The Griot's latest line on this match, with his voice clip when there is one. */
    private function griot(FootballMatch $match): ?array
    {
        $timeline = app(GriotTimeline::class);
        if (!$timeline->ready()) {
            return null;
        }
        $post = GriotPost::where('match_id', $match->id)->orderByDesc('seq')->orderByDesc('id')->first();
        if (!$post) {
            return null;
        }
        [$headline, $line] = app(GriotTeller::class)->tell($post, 'en', $match);

        return ['id' => $post->id, 'kind' => $post->kind, 'clock' => $post->clock, 'headline' => $headline, 'line' => $line, 'audio' => $post->audioUrl()];
    }

    /** The Griot's call when the match is in this round of Beat the Griot. */
    private function call(FootballMatch $match): ?array
    {
        if (!GriotChallengeService::ready()) {
            return null;
        }
        $pick = GriotChallengeMatch::where('match_id', $match->id)->latest('id')->first();

        return $pick ? [(int) $pick->home_pick, (int) $pick->away_pick] : null;
    }

    private function side(FootballMatch $match, MatchEvent $event): string
    {
        return $event->team_id && $event->team_id === $match->away_team_id ? 'away' : 'home';
    }

    private function minute(MatchEvent $event): ?string
    {
        return $event->minute === null ? null : $event->minute.($event->extra_minute ? '+'.$event->extra_minute : '').'′';
    }

    private function name(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }

    private function pair($home, $away): ?array
    {
        return $home === null || $away === null ? null : [(int) $home, (int) $away];
    }

    /** Black or white text, whichever reads better on the club colour. */
    private function ink(string $hex): string
    {
        $rgb = sscanf(ltrim($hex, '#').'000000', '%02x%02x%02x');
        $lum = fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lum($rgb[0]) + 0.7152 * $lum($rgb[1]) + 0.0722 * $lum($rgb[2]);

        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.05) ? '#ffffff' : '#0b0b0b';
    }
}
