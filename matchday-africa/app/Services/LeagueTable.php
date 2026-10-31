<?php

namespace App\Services;

use App\Models\PredictionGroup;
use App\Models\UserPrediction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** A prediction league's table: this week's points (Monday to Sunday, Lagos) or all time. */
class LeagueTable
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the week's bounds in Lagos time */
    public function week(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('Africa/Lagos');
        $start = $at->setTimezone('Africa/Lagos')->startOfWeek(CarbonImmutable::MONDAY);

        return [$start, $start->addWeek()->subSecond()];
    }

    public function rows(PredictionGroup $group, string $period = 'week'): Collection
    {
        $members = $group->members()->get(['users.id', 'users.name']);
        $query = UserPrediction::whereIn('user_id', $members->pluck('id'));
        if ($period === 'week') {
            // matches.match_date holds UTC wall-clock values.
            [$start, $end] = $this->week();
            $query->whereHas('match', fn ($q) => $q->whereBetween('match_date', [
                $start->utc()->format('Y-m-d H:i:s'), $end->utc()->format('Y-m-d H:i:s'),
            ]));
        }
        $totals = $query->selectRaw('user_id, SUM(points_earned) as points, COUNT(*) as picks')->groupBy('user_id')->get()->keyBy('user_id');

        return $members->map(fn ($user) => [
            'user' => $user,
            'points' => (int) ($totals[$user->id]->points ?? 0),
            'picks' => (int) ($totals[$user->id]->picks ?? 0),
        ])->sortBy([['points', 'desc'], ['picks', 'desc']])->values();
    }
}
