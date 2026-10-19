<?php

namespace App\Services\Espn;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Translates ESPN scoreboard and summary JSON into our match and event fields, using
 * football-data.org's status vocabulary so the rest of the app doesn't change.
 */
class EspnMatchMapper
{
    private const STAT_COLUMNS = [
        'possessionPct' => 'possession',
        'totalShots' => 'shots',
        'shotsOnTarget' => 'shots_on_target',
        'wonCorners' => 'corners',
        'foulsCommitted' => 'fouls',
        'yellowCards' => 'yellow_cards',
        'redCards' => 'red_cards',
    ];

    /** One scoreboard event → normalised match array (teams, kickoff, live fields). */
    public function match(array $event): ?array
    {
        $competition = data_get($event, 'competitions.0', []);
        $home = collect(data_get($competition, 'competitors', []))->firstWhere('homeAway', 'home');
        $away = collect(data_get($competition, 'competitors', []))->firstWhere('homeAway', 'away');
        if (!$home || !$away || !data_get($event, 'id') || !data_get($event, 'date')) {
            return null;
        }

        $status = $this->status(data_get($event, 'status.type', []));
        [$minute, $extra] = $this->clock((string) data_get($event, 'status.displayClock', ''));
        $started = in_array($status, ['IN_PLAY', 'PAUSED', 'PENALTY_SHOOTOUT', 'FINISHED'], true);

        return [
            'espn_id' => (int) data_get($event, 'id'),
            'kickoff' => Carbon::parse(data_get($event, 'date'))->utc(),
            'status' => $status,
            'raw_status' => data_get($event, 'status.type.name'),
            'minute' => $status === 'IN_PLAY' || $status === 'PAUSED' ? $minute : null,
            'extra_minute' => $status === 'IN_PLAY' ? $extra : null,
            'home' => $this->team($home),
            'away' => $this->team($away),
            'home_score' => $started ? $this->score($home) : null,
            'away_score' => $started ? $this->score($away) : null,
            'venue_name' => data_get($competition, 'venue.fullName'),
            'venue_city' => data_get($competition, 'venue.address.city'),
            'attendance' => data_get($competition, 'attendance') ?: null,
            'details' => $this->scoreboardEvents($competition, (string) data_get($home, 'team.id')),
        ];
    }

    /** ESPN status → our status, or null when we don't recognise it (leave the match alone). */
    public function status(array $type): ?string
    {
        $name = strtoupper((string) ($type['name'] ?? ''));
        $state = $type['state'] ?? null;

        if (str_contains($name, 'POSTPONED') || str_contains($name, 'DELAYED')) {
            return 'POSTPONED';
        }
        if (str_contains($name, 'CANCEL') || str_contains($name, 'ABANDON') || str_contains($name, 'FORFEIT')) {
            return 'CANCELLED';
        }

        return match ($state) {
            'pre' => 'SCHEDULED',
            'in' => match (true) {
                str_contains($name, 'SHOOTOUT') => 'PENALTY_SHOOTOUT',
                str_contains($name, 'HALFTIME') || str_contains($name, 'END_OF') => 'PAUSED',
                default => 'IN_PLAY',
            },
            'post' => 'FINISHED',
            default => null,
        };
    }

    /** "45'+2'" → [45, 2]; "63'" → [63, null]. */
    public function clock(string $display): array
    {
        if (!preg_match("/(\d+)'?(?:\s*\+\s*(\d+))?/", $display, $m)) {
            return [null, null];
        }

        return [(int) $m[1], isset($m[2]) ? (int) $m[2] : null];
    }

    /** Goals and cards from the scoreboard's `details` list. */
    public function scoreboardEvents(array $competition, string $homeTeamId): array
    {
        $events = [];
        foreach (data_get($competition, 'details', []) as $index => $detail) {
            if (data_get($detail, 'shootout')) {
                continue;
            }
            $type = match (true) {
                (bool) data_get($detail, 'scoringPlay') => 'goal',
                (bool) data_get($detail, 'redCard') => 'red_card',
                (bool) data_get($detail, 'yellowCard') => 'yellow_card',
                default => null,
            };
            if (!$type) {
                continue;
            }
            [$minute, $extra] = $this->clock((string) data_get($detail, 'clock.displayValue', ''));
            $events[] = [
                'type' => $type,
                'sub_type' => Str::snake(Str::slug((string) data_get($detail, 'type.text', ''), ' ')),
                'minute' => $minute ?? 0,
                'extra_minute' => $extra,
                'clock' => (float) data_get($detail, 'clock.value', 0),
                'side' => (string) data_get($detail, 'team.id') === $homeTeamId ? 'home' : 'away',
                'player_name' => data_get($detail, 'athletesInvolved.0.displayName'),
                'assist_player_name' => null,
                'related_player_name' => null,
                'is_own_goal' => (bool) data_get($detail, 'ownGoal'),
                'is_penalty' => (bool) data_get($detail, 'penaltyKick'),
                'description' => data_get($detail, 'type.text'),
                'sort_order' => $index,
            ];
        }

        return $events;
    }

    /** Goals, cards and substitutions from a match summary's `keyEvents`. */
    public function summaryEvents(array $summary, string $homeTeamId): array
    {
        $events = [];
        foreach (data_get($summary, 'keyEvents', []) as $index => $key) {
            $slug = (string) data_get($key, 'type.type', '');
            $type = match (true) {
                (bool) data_get($key, 'scoringPlay') || str_starts_with($slug, 'goal') || str_contains($slug, 'own-goal') || $slug === 'penalty---scored' => 'goal',
                str_contains($slug, 'red-card') => 'red_card',
                $slug === 'yellow-card' => 'yellow_card',
                $slug === 'substitution' => 'substitution',
                default => null,
            };
            if (!$type || data_get($key, 'shootout')) {
                continue;
            }
            [$minute, $extra] = $this->clock((string) data_get($key, 'clock.displayValue', ''));
            $text = (string) data_get($key, 'type.text', '');
            $events[] = [
                'type' => $type,
                'sub_type' => Str::snake(Str::slug($text, ' ')),
                'minute' => $minute ?? 0,
                'extra_minute' => $extra,
                'clock' => (float) data_get($key, 'clock.value', 0),
                'side' => (string) data_get($key, 'team.id') === $homeTeamId ? 'home' : 'away',
                'player_name' => data_get($key, 'participants.0.athlete.displayName'),
                'assist_player_name' => $type === 'goal' ? data_get($key, 'participants.1.athlete.displayName') : null,
                'related_player_name' => $type === 'substitution' ? data_get($key, 'participants.1.athlete.displayName') : null,
                'is_own_goal' => str_contains(strtolower($text), 'own goal'),
                'is_penalty' => str_contains(strtolower($text), 'penalty'),
                'description' => $text,
                'sort_order' => $index,
            ];
        }

        return $events;
    }

    /** Team stats from a match summary → our home_/away_ columns. */
    public function statistics(array $summary, string $homeTeamId): array
    {
        $columns = [];
        foreach (data_get($summary, 'boxscore.teams', []) as $side) {
            $prefix = (string) data_get($side, 'team.id') === $homeTeamId ? 'home' : 'away';
            foreach (data_get($side, 'statistics', []) as $stat) {
                $column = self::STAT_COLUMNS[$stat['name'] ?? ''] ?? null;
                $value = data_get($stat, 'displayValue');
                if ($column && is_numeric($value)) {
                    $columns["{$prefix}_{$column}"] = (int) round((float) $value);
                }
            }
        }

        return $columns;
    }

    /** Line-ups in the same shape the match page already renders for API-Football. */
    public function lineups(array $summary): array
    {
        $player = fn (array $row) => [
            'name' => data_get($row, 'athlete.displayName'),
            'number' => data_get($row, 'jersey'),
            'position' => data_get($row, 'position.abbreviation'),
        ];

        return collect(data_get($summary, 'rosters', []))
            ->filter(fn ($side) => !empty($side['roster']))
            ->map(fn ($side) => [
                'team' => data_get($side, 'team.displayName'),
                'formation' => data_get($side, 'formation'),
                'coach' => null,
                'starters' => collect($side['roster'])->where('starter', true)->map($player)->values()->all(),
                'substitutes' => collect($side['roster'])->where('starter', false)->map($player)->values()->all(),
            ])->values()->all();
    }

    private function team(array $competitor): array
    {
        return [
            'espn_id' => (int) data_get($competitor, 'team.id'),
            'name' => (string) (data_get($competitor, 'team.displayName') ?: data_get($competitor, 'team.name')),
            'short_code' => data_get($competitor, 'team.abbreviation'),
        ];
    }

    private function score(array $competitor): ?int
    {
        $score = data_get($competitor, 'score');

        return is_numeric($score) ? (int) $score : null;
    }
}
