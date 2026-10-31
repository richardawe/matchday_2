<?php

namespace App\Services\Griot;

/**
 * Chooses the moments worth telling from a match's standard plays.
 *
 * Rules propose candidates generously; then either the learned picker (a small logistic model
 * trained by griot-ml/train_picker.py on ESPN and StatsBomb matches) or the strict rules decide
 * which optional ones to keep. Goals, red cards, VAR calls and the whistles are always kept.
 * griot-ml/griot_plays.py holds the same candidate and feature code; keep the two in step
 * (tests/Fixtures/griot/picker-parity.json checks it).
 */
class MomentPicker
{
    public const FEATURES = ['kind_big_chance', 'kind_pressure', 'kind_injury', 'kind_woodwork', 'minute', 'late', 'second_half',
        'score_diff', 'level', 'zone_six', 'zone_box', 'zone_outside', 'x', 'saved', 'close', 'penalty', 'head',
        'side_shots', 'opp_shots', 'side_on_target'];

    private const SHOTS = ['goal', 'own_goal', 'penalty_goal', 'penalty_miss', 'shot_saved', 'shot_missed', 'shot_blocked', 'woodwork'];
    private const GOALS = ['goal', 'own_goal', 'penalty_goal'];
    private const ALWAYS = ['kickoff', 'goal', 'red_card', 'var', 'half_time', 'full_time'];

    private ?array $model = null;

    public function __construct(?array $model = null, private array $settings = [])
    {
        $this->model = $model;
        $this->settings += ['pressure_shots' => 3, 'pressure_minutes' => 6, 'cooldown_minutes' => 10, 'threshold' => 0.5];
    }

    public static function fromConfig(): self
    {
        $path = (string) config('griot.picker_model');
        $model = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $valid = is_array($model) && ($model['features'] ?? null) === self::FEATURES && isset($model['weights'], $model['bias']);

        return new self($valid ? $model : null, [
            'pressure_shots' => (int) config('griot.pressure_shots', 3), 'pressure_minutes' => (int) config('griot.pressure_minutes', 6),
            'cooldown_minutes' => (int) config('griot.cooldown_minutes', 10), 'threshold' => (float) config('griot.picker_threshold', 0.5),
        ]);
    }

    public function name(): string
    {
        return $this->model ? 'model:'.($this->model['version'] ?? '1') : 'rules';
    }

    /**
     * @param array<int, array> $plays standard plays in feed order (arrays with the match_plays columns)
     * @param bool $finished whether the match is over (full time is only told then)
     * @return array<int, array> posts: key, kind, side, period, minute, extra_minute, seq, home_score, away_score, facts, play_keys, confidence
     */
    public function pick(array $plays, bool $finished = false): array
    {
        $chosen = [];
        $lastOptional = ['home' => -99, 'away' => -99];
        foreach ($this->candidates($plays, $finished) as $candidate) {
            if (in_array($candidate['kind'], self::ALWAYS, true)) {
                $chosen[] = $candidate + ['confidence' => 1.0];
                continue;
            }
            $confidence = $this->model ? $this->score($candidate['features']) : ($candidate['strict'] ? 1.0 : 0.0);
            $threshold = $this->model ? $this->settings['threshold'] : 0.5;
            $side = $candidate['side'] ?? 'home';
            $spaced = $candidate['kind'] === 'injury' || $candidate['kind'] === 'woodwork'
                || $candidate['time'] - $lastOptional[$side] >= $this->settings['cooldown_minutes'];
            if ($confidence >= $threshold && $spaced) {
                if (!in_array($candidate['kind'], ['injury', 'woodwork'], true)) {
                    $lastOptional[$side] = $candidate['time'];
                }
                $chosen[] = $candidate + ['confidence' => round($confidence, 3)];
            }
        }

        return array_map(fn ($c) => array_diff_key($c, ['features' => 1, 'strict' => 1, 'time' => 1]), $chosen);
    }

    /** Every moment that might be worth a post, with its features and whether the strict rules keep it. */
    public function candidates(array $plays, bool $finished = false): array
    {
        $out = [];
        $score = ['home' => 0, 'away' => 0];
        $shots = []; // [time, side, type, key, player]
        $spells = [];
        $halfTimeDone = false;
        foreach ($plays as $play) {
            $type = $play['type'];
            $side = $play['side'] ?? null;
            $time = $this->time($play);
            $base = ['side' => $side, 'period' => (int) $play['period'], 'minute' => (int) $play['minute'], 'extra_minute' => $play['extra_minute'] ?? null,
                'seq' => (int) $play['seq'], 'time' => $time, 'play_keys' => [$play['provider_key']]];
            $key = fn (string $kind) => "{$kind}:{$play['provider']}:{$play['provider_key']}";

            if ($type === 'period_start' && (int) $play['period'] === 1 && !$out) {
                $out[] = $base + ['key' => 'kickoff', 'kind' => 'kickoff', 'side' => null, 'facts' => [], 'strict' => true, 'features' => []]
                    + ['home_score' => 0, 'away_score' => 0];
                continue;
            }
            if ($type === 'period_end') {
                $last = (int) $play['period'] >= 2 || preg_match('/^Match ends/i', (string) ($play['text'] ?? ''));
                if (!$halfTimeDone && (int) $play['period'] === 1) {
                    $halfTimeDone = true;
                    $out[] = $base + ['key' => 'half_time', 'kind' => 'half_time', 'side' => null, 'home_score' => $score['home'], 'away_score' => $score['away'],
                        'facts' => $this->totals($shots, $score), 'strict' => true, 'features' => []];
                } elseif ($last && $finished) {
                    $out = array_values(array_filter($out, fn ($c) => $c['kind'] !== 'full_time'));
                    $out[] = array_merge($base, ['key' => 'full_time', 'kind' => 'full_time', 'side' => $score['home'] === $score['away'] ? null : ($score['home'] > $score['away'] ? 'home' : 'away'),
                        'home_score' => $score['home'], 'away_score' => $score['away'], 'facts' => $this->totals($shots, $score), 'strict' => true, 'features' => []]);
                }
                continue;
            }
            if (!$side) {
                continue;
            }
            $opp = $side === 'home' ? 'away' : 'home';
            $before = $score;

            if (in_array($type, self::SHOTS, true)) {
                $shots[] = [$time, $side, $type, $play['provider_key'], $play['player'] ?? null];
            }
            $recent = array_values(array_filter($shots, fn ($s) => $s[0] >= $time - $this->settings['pressure_minutes'] && $s[0] <= $time));
            $sideShots = array_values(array_filter($recent, fn ($s) => $s[1] === $side));
            $context = [
                'side_shots' => count($sideShots),
                'opp_shots' => count(array_filter($recent, fn ($s) => $s[1] === $opp)),
                'side_on_target' => count(array_filter($sideShots, fn ($s) => in_array($s[2], ['shot_saved', 'goal', 'penalty_goal'], true))),
            ];
            $features = fn (string $kind) => $this->features($kind, $play, $time, $before[$side] - $before[$opp], $context);

            if (in_array($type, self::GOALS, true)) {
                $score[$side]++;
                $spells[$side] = null;
                $out[] = $base + ['key' => $key('goal'), 'kind' => 'goal', 'home_score' => $score['home'], 'away_score' => $score['away'],
                    'facts' => array_filter([
                        'player' => $play['player'] ?? null,
                        'assist' => $type === 'own_goal' ? null : ($play['player2'] ?? null),
                        'own_goal' => $type === 'own_goal', 'penalty' => $type === 'penalty_goal' || !empty($play['detail']['penalty']),
                        'angle' => $this->angle($before[$side], $before[$opp], $this->clock($play)),
                        'zone' => $play['detail']['zone'] ?? null, 'body' => $play['detail']['body'] ?? null,
                        'shots_before' => max(0, $context['side_shots'] - 1),
                    ], fn ($v) => $v !== null && $v !== false),
                    'strict' => true, 'features' => []];
                continue;
            }
            $scoreNow = ['home_score' => $score['home'], 'away_score' => $score['away']];
            if ($type === 'red_card') {
                $out[] = $base + $scoreNow + ['key' => $key('red_card'), 'kind' => 'red_card', 'facts' => ['player' => $play['player'] ?? null], 'strict' => true, 'features' => []];
            } elseif ($type === 'var_no_goal') {
                $out[] = $base + $scoreNow + ['key' => $key('var'), 'kind' => 'var', 'facts' => array_filter(['player' => $play['player'] ?? null]), 'strict' => true, 'features' => []];
            } elseif ($type === 'woodwork') {
                $out[] = $base + $scoreNow + ['key' => $key('woodwork'), 'kind' => 'woodwork',
                    'facts' => array_filter(['player' => $play['player'] ?? null, 'zone' => $play['detail']['zone'] ?? null, 'body' => $play['detail']['body'] ?? null]),
                    'strict' => true, 'features' => $features('woodwork')];
            } elseif ($type === 'substitution' && !empty($play['detail']['injury'])) {
                $out[] = $base + $scoreNow + ['key' => $key('injury'), 'kind' => 'injury',
                    'facts' => array_filter(['player_on' => $play['player'] ?? null, 'player_off' => $play['player2'] ?? null]),
                    'strict' => true, 'features' => $features('injury')];
            } elseif (in_array($type, ['shot_saved', 'shot_missed', 'penalty_miss'], true)
                && ($type === 'penalty_miss' || in_array($play['detail']['zone'] ?? null, ['six_yard', 'box'], true) || !empty($play['detail']['close']))) {
                $strict = $type === 'penalty_miss' || ($play['detail']['zone'] ?? null) === 'six_yard'
                    || ($type === 'shot_saved' && (float) ($play['x'] ?? 0) >= 92);
                $out[] = $base + $scoreNow + ['key' => $key('big_chance'), 'kind' => 'big_chance',
                    'facts' => array_filter(['player' => $play['player'] ?? null, 'outcome' => $type === 'shot_saved' ? 'saved' : ($type === 'penalty_miss' ? 'penalty_missed' : 'missed'),
                        'zone' => $play['detail']['zone'] ?? null, 'body' => $play['detail']['body'] ?? null, 'close' => !empty($play['detail']['close'])]),
                    'strict' => $strict, 'features' => $features('big_chance')];
            }
            // A spell of pressure: shots by one side, each within a few minutes of the last. Told once,
            // keyed by its first shot, and retold with the fuller count as the spell grows.
            if (in_array($type, self::SHOTS, true)) {
                $spell = $spells[$side] ?? null;
                // A spell ends after a quiet spell of minutes, or once it has run twice that long.
                if (!$spell || $time - $spell['last'] > $this->settings['pressure_minutes'] || $time - $spell['start'] >= 2 * $this->settings['pressure_minutes']) {
                    $spell = ['first' => $play['provider_key'], 'start' => $time, 'shots' => []];
                }
                $spell['last'] = $time;
                $spell['shots'][] = [$play['provider_key'], $play['player'] ?? null, in_array($type, ['shot_saved', 'goal', 'penalty_goal'], true)];
                $spells[$side] = in_array($type, self::GOALS, true) ? null : $spell;
                $count = count($spell['shots']);
                if ($count >= 2 && !in_array($type, self::GOALS, true)) {
                    $pressureKey = "pressure:{$side}:{$play['provider']}:{$spell['first']}";
                    $out = array_values(array_filter($out, fn ($c) => $c['key'] !== $pressureKey));
                    $onTarget = count(array_filter($spell['shots'], fn ($s) => $s[2]));
                    $out[] = $base + $scoreNow + ['key' => $pressureKey, 'kind' => 'pressure',
                        'play_keys' => array_column($spell['shots'], 0),
                        'facts' => ['shots' => $count, 'on_target' => $onTarget, 'span' => max(1, (int) ceil($time - $spell['start'])),
                            'players' => array_values(array_unique(array_filter(array_column($spell['shots'], 1))))],
                        'strict' => $count >= $this->settings['pressure_shots'],
                        'features' => $this->features('pressure', $play, $time, $before[$side] - $before[$opp],
                            ['side_shots' => $count, 'opp_shots' => $context['opp_shots'], 'side_on_target' => $onTarget])];
                }
            }
        }

        return $out;
    }

    public function features(string $kind, array $play, float $time, int $diff, array $context): array
    {
        $zone = $play['detail']['zone'] ?? null;
        $clock = $this->clock($play);

        return [
            'kind_big_chance' => (float) ($kind === 'big_chance'), 'kind_pressure' => (float) ($kind === 'pressure'),
            'kind_injury' => (float) ($kind === 'injury'), 'kind_woodwork' => (float) ($kind === 'woodwork'),
            'minute' => round(min(1.1, $clock / 90), 3), 'late' => (float) ($clock >= 80), 'second_half' => (float) ((int) $play['period'] >= 2),
            'score_diff' => round(max(-3, min(3, $diff)) / 3, 3), 'level' => (float) ($diff === 0),
            'zone_six' => (float) ($zone === 'six_yard'), 'zone_box' => (float) ($zone === 'box'), 'zone_outside' => (float) ($zone === 'outside'),
            'x' => isset($play['x']) ? round((float) $play['x'] / 100, 3) : 0.0,
            'saved' => (float) ($play['type'] === 'shot_saved'), 'close' => (float) !empty($play['detail']['close']),
            'penalty' => (float) ($play['type'] === 'penalty_miss' || !empty($play['detail']['penalty'])),
            'head' => (float) (($play['detail']['body'] ?? null) === 'head'),
            'side_shots' => min(1, $context['side_shots'] / 5), 'opp_shots' => min(1, $context['opp_shots'] / 5),
            'side_on_target' => min(1, $context['side_on_target'] / 3),
        ];
    }

    public function score(array $features): float
    {
        $z = (float) $this->model['bias'];
        foreach (self::FEATURES as $i => $name) {
            $z += (float) ($this->model['weights'][$i] ?? 0) * (float) ($features[$name] ?? 0);
        }

        return 1 / (1 + exp(-$z));
    }

    /** Minutes on the clock: 45+2 → 47, 90+3 → 93. */
    public function clock(array $play): float
    {
        return (float) $play['minute'] + (float) ($play['extra_minute'] ?? 0);
    }

    /** Clock time for windows and cooldowns, with a gap at each break so a spell never spans half-time. */
    public function time(array $play): float
    {
        return $this->clock($play) + 20 * (max(1, (int) $play['period']) - 1);
    }

    private function angle(int $before, int $against, float $time): string
    {
        return match (true) {
            $before === 0 && $against === 0 => $time >= 85 ? 'late_winner' : 'opener',
            $before + 1 === $against => 'equaliser',
            $before === $against => $time >= 85 ? 'late_winner' : 'lead',
            $before > $against => 'extend',
            default => 'consolation',
        };
    }

    private function totals(array $shots, array $score): array
    {
        $count = fn ($side, $types) => count(array_filter($shots, fn ($s) => $s[1] === $side && ($types === null || in_array($s[2], $types, true))));
        $onTarget = ['shot_saved', 'goal', 'penalty_goal'];

        return [
            'home_shots' => $count('home', array_diff(self::SHOTS, ['own_goal'])), 'away_shots' => $count('away', array_diff(self::SHOTS, ['own_goal'])),
            'home_on_target' => $count('home', $onTarget), 'away_on_target' => $count('away', $onTarget),
        ];
    }
}
