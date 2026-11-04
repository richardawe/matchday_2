<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPrediction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A fan's prediction record: the history page and the dashboard's totals. Calls are made on match
 * pages (MatchPredictionRecorder) and in Beat the Griot; they are scored by PredictionScoringService.
 */
class PredictionService
{
    public function getUserPredictionHistory(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $user->predictions()->with(['match.homeTeam', 'match.awayTeam', 'match.league']);
        if (!empty($filters['date_from'])) {
            $query->where('submitted_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $query->where('submitted_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        if (array_key_exists('is_correct', $filters) && in_array($filters['is_correct'], ['0', '1', 0, 1], true)) {
            $query->where('is_correct', (bool) $filters['is_correct']);
        }

        return $query->orderBy('submitted_at', 'desc')->paginate($perPage);
    }

    /** @return array{total_predictions: int, correct_predictions: int, total_points: int, accuracy_percentage: float, rank: int} */
    public function getUserStats(User $user): array
    {
        $query = $user->predictions();
        $total = $query->count();
        $correct = (clone $query)->where('is_correct', true)->count();
        $points = (int) (clone $query)->sum('points_earned');

        return [
            'total_predictions' => $total,
            'correct_predictions' => $correct,
            'total_points' => $points,
            'accuracy_percentage' => $total > 0 ? round($correct / $total * 100, 2) : 0,
            'rank' => $total > 0 ? $this->rank($points) : 0,
        ];
    }

    /** All-time place by points across every fan's scored calls (1 = top). */
    private function rank(int $points): int
    {
        $ahead = UserPrediction::query()->selectRaw('user_id')->groupBy('user_id')
            ->havingRaw('SUM(points_earned) > ?', [$points])->get()->count();

        return $ahead + 1;
    }
}
