<?php

namespace App\Services\Griot;

use App\Models\FootballMatch;
use App\Models\Standing;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The Griot's score call for a match, from the site's own results: each side's goals scored and
 * conceded over its last ten finished games give two Poisson rates (with a small home edge). The
 * Griot first takes the likeliest result (home win, draw, away win), then the likeliest score
 * within it, so its calls read like a person's (2–1, 1–0) rather than a run of 1–1s.
 *
 * It also returns the facts its line may quote (recent wins, form, table places): nothing else
 * goes into the line, so it never states anything the data doesn't say.
 */
class GriotForecaster
{
    private const RECENT = 10;
    private const FORM = 5;
    private const HOME_EDGE = 1.08;
    private const AWAY_DAMP = 0.94;
    // League-wide fallbacks when a side has fewer than three results on record.
    private const DEFAULT_HOME = 1.5;
    private const DEFAULT_AWAY = 1.2;

    /** @return array{home: int, away: int, facts: array<string, mixed>} */
    public function forecast(FootballMatch $match): array
    {
        $before = Carbon::parse($match->match_date->format('Y-m-d H:i:s'));
        $home = $this->record((int) $match->home_team_id, $before);
        $away = $this->record((int) $match->away_team_id, $before);

        $lambdaHome = $this->rate($home['scored'], $away['conceded'], self::DEFAULT_HOME) * self::HOME_EDGE;
        $lambdaAway = $this->rate($away['scored'], $home['conceded'], self::DEFAULT_AWAY) * self::AWAY_DAMP;
        [$h, $a] = $this->call($lambdaHome, $lambdaAway);

        return ['home' => $h, 'away' => $a, 'facts' => [
            'home_form' => $home['form'], 'away_form' => $away['form'],
            'home_wins' => substr_count($home['form'], 'W'), 'away_wins' => substr_count($away['form'], 'W'),
            'home_position' => $this->position($match, (int) $match->home_team_id),
            'away_position' => $this->position($match, (int) $match->away_team_id),
            'lambda' => [round($lambdaHome, 2), round($lambdaAway, 2)],
        ]];
    }

    /** The likeliest result, then the likeliest score with that result. @return array{0: int, 1: int} */
    public function call(float $lambdaHome, float $lambdaAway): array
    {
        $grid = [];
        $totals = ['H' => 0.0, 'D' => 0.0, 'A' => 0.0];
        for ($h = 0; $h <= 6; $h++) {
            for ($a = 0; $a <= 6; $a++) {
                $p = $this->poisson($h, $lambdaHome) * $this->poisson($a, $lambdaAway);
                $result = $h > $a ? 'H' : ($h === $a ? 'D' : 'A');
                $grid[] = [$h, $a, $p, $result];
                $totals[$result] += $p;
            }
        }
        arsort($totals);
        $result = array_key_first($totals);
        $best = collect($grid)->where(3, $result)->sortByDesc(2)->first();

        return [$best[0], $best[1]];
    }

    /** @return array{scored: ?float, conceded: ?float, form: string} */
    private function record(int $teamId, Carbon $before): array
    {
        /** @var Collection<int, FootballMatch> $games */
        $games = FootballMatch::query()
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->whereIn('status', ['FINISHED', 'FT'])
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->where('match_date', '<', $before)
            ->orderByDesc('match_date')->limit(self::RECENT)
            ->get(['id', 'home_team_id', 'away_team_id', 'home_score', 'away_score']);
        if ($games->count() < 3) {
            return ['scored' => null, 'conceded' => null, 'form' => $this->form($games, $teamId)];
        }
        $for = $games->sum(fn ($g) => $g->home_team_id === $teamId ? $g->home_score : $g->away_score);
        $against = $games->sum(fn ($g) => $g->home_team_id === $teamId ? $g->away_score : $g->home_score);

        return ['scored' => $for / $games->count(), 'conceded' => $against / $games->count(), 'form' => $this->form($games, $teamId)];
    }

    /** Newest first, e.g. "WWDLW". */
    private function form(Collection $games, int $teamId): string
    {
        return $games->take(self::FORM)->map(function ($g) use ($teamId) {
            [$mine, $theirs] = $g->home_team_id === $teamId ? [$g->home_score, $g->away_score] : [$g->away_score, $g->home_score];

            return $mine > $theirs ? 'W' : ($mine === $theirs ? 'D' : 'L');
        })->implode('');
    }

    private function rate(?float $attack, ?float $defence, float $default): float
    {
        $values = array_filter([$attack, $defence], fn ($v) => $v !== null);
        $rate = $values ? array_sum($values) / count($values) : $default;

        return max(0.2, min(4.0, $rate));
    }

    private function poisson(int $k, float $lambda): float
    {
        $f = 1;
        for ($i = 2; $i <= $k; $i++) {
            $f *= $i;
        }

        return exp(-$lambda) * $lambda ** $k / $f;
    }

    private function position(FootballMatch $match, int $teamId): ?int
    {
        $position = Standing::where('league_id', $match->league_id)->where('team_id', $teamId)->where('is_current', true)->value('position');

        return $position ? (int) $position : null;
    }
}
