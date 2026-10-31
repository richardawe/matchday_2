<?php

namespace App\Services\Espn;

/**
 * ESPN match commentary → standard Griot plays (config/griot.php). The commentary arrives in the
 * same summary response as keyEvents, so reading it costs no extra request. The provider's text is
 * read for details (where, how, why) and kept for training, never published.
 * griot-ml/griot_plays.py mirrors this mapping for training; keep them in step.
 */
class EspnCommentaryMapper
{
    private const TYPES = [
        'goal' => 'goal', 'own-goal' => 'own_goal', 'penalty---scored' => 'penalty_goal',
        'penalty---missed' => 'penalty_miss', 'penalty---saved' => 'penalty_miss',
        'shot-on-target' => 'shot_saved', 'shot-off-target' => 'shot_missed', 'shot-blocked' => 'shot_blocked',
        'shot-hit-woodwork' => 'woodwork', 'deleted-after-review' => 'var_no_goal',
        'yellow-card' => 'yellow_card', 'red-card' => 'red_card', 'substitution' => 'substitution',
        'foul' => 'foul', 'handball' => 'handball', 'corner-awarded' => 'corner', 'offside' => 'offside',
        'start-delay' => 'delay', 'end-delay' => 'other', 'kickoff' => 'period_start', 'start-2nd-half' => 'period_start',
        'halftime' => 'period_end', 'end-regular-time' => 'period_end',
    ];

    /** @return array<int, array> plays in feed order */
    public function plays(array $summary): array
    {
        $sides = [];
        foreach (data_get($summary, 'header.competitions.0.competitors', []) as $competitor) {
            $sides[$this->norm((string) data_get($competitor, 'team.displayName'))] = data_get($competitor, 'homeAway') === 'away' ? 'away' : 'home';
        }

        $plays = [];
        $seen = [];
        $period = 1;
        foreach (data_get($summary, 'commentary', []) as $line) {
            $text = trim((string) data_get($line, 'text', ''));
            $play = data_get($line, 'play');
            $slug = (string) data_get($play, 'type.type', '');
            $id = (string) data_get($play, 'id', '');
            // A foul arrives twice under one play id ("Foul by…", "…wins a free kick"): keep the first.
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $type = self::TYPES[$slug] ?? (str_starts_with($slug, 'goal') ? 'goal' : (str_starts_with($slug, 'var') ? 'var_decision' : null));
            $type ??= $this->untyped($text);
            if ($type === null) {
                continue;
            }
            $period = (int) (data_get($play, 'period.number') ?: $this->periodFromText($text, $period));
            [$minute, $extra] = $this->clock((string) data_get($line, 'time.displayValue', ''), $type, $period);
            $participants = collect(data_get($play, 'participants', []))->pluck('athlete.displayName')->filter()->values();

            $plays[] = [
                'provider' => 'espn',
                'provider_key' => $id !== '' ? $id : 'seq:'.data_get($line, 'sequence', count($plays)),
                'seq' => (int) data_get($line, 'sequence', count($plays)),
                'period' => $period,
                'minute' => $minute,
                'extra_minute' => $extra,
                'type' => $type,
                'side' => $sides[$this->norm((string) data_get($play, 'team.displayName'))] ?? null,
                'player' => $participants[0] ?? null,
                'player2' => $participants[1] ?? $this->assist($text),
                'x' => is_numeric(data_get($play, 'fieldPositionX')) ? round((float) data_get($play, 'fieldPositionX'), 1) : null,
                'y' => is_numeric(data_get($play, 'fieldPositionY')) ? round((float) data_get($play, 'fieldPositionY'), 1) : null,
                'detail' => $this->detail($text, $type),
                'text' => $text,
                'happened_at' => data_get($play, 'wallclock'),
            ];
        }

        return $plays;
    }

    /** Lines without a typed play: whistles and added time. */
    private function untyped(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/^(First|Second) Half begins/i', $text) => 'period_start',
            (bool) preg_match('/^(First|Second) Half ends|^Match ends/i', $text) => 'period_end',
            (bool) preg_match('/^Fourth official has announced \d+ minutes?/i', $text) => 'added_time',
            default => null,
        };
    }

    private function periodFromText(string $text, int $current): int
    {
        return preg_match('/^Second Half|^Match ends/i', $text) ? 2 : $current;
    }

    /** "45'+2'" → [45, 2]; whistles without a clock take the period's edge. */
    private function clock(string $display, string $type, int $period): array
    {
        if (preg_match("/(\d+)'?(?:\s*\+\s*(\d+))?/", $display, $m)) {
            return [(int) $m[1], isset($m[2]) ? (int) $m[2] : null];
        }

        return [$period >= 2 ? ($type === 'period_start' ? 45 : 90) : ($type === 'period_start' ? 0 : 45), null];
    }

    private function assist(string $text): ?string
    {
        return preg_match('/Assisted by ([^.]+?)(?: with| following|\.)/u', $text, $m) ? trim($m[1]) : null;
    }

    private function detail(string $text, string $type): array
    {
        $t = strtolower($text);
        $detail = array_filter([
            'zone' => match (true) {
                str_contains($t, 'six yard box') || str_contains($t, 'very close range') => 'six_yard',
                str_contains($t, 'outside the box') || str_contains($t, 'long range') || str_contains($t, 'more than 35 yards') => 'outside',
                str_contains($t, 'the box') || str_contains($t, 'difficult angle') => 'box',
                default => null,
            },
            'body' => match (true) {
                str_contains($t, 'header') => 'head',
                str_contains($t, 'left footed') => 'left',
                str_contains($t, 'right footed') => 'right',
                default => null,
            },
            'free_kick' => str_contains($t, 'free kick') && in_array($type, ['shot_saved', 'shot_missed', 'shot_blocked', 'woodwork', 'goal'], true),
            'penalty' => str_contains($t, 'penalty') && $type !== 'foul',
            'close' => str_contains($t, 'close, but') || str_contains($t, 'just a bit'),
            'injury' => str_contains($t, 'injury'),
            'cross' => str_contains($t, 'with a cross'),
        ]);
        if ($type === 'added_time' && preg_match('/(\d+) minutes?/', $text, $m)) {
            $detail['minutes'] = (int) $m[1];
        }

        return $detail;
    }

    private function norm(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(str_replace('&', 'and', $name))));
    }
}
