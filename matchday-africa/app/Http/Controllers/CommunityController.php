<?php

namespace App\Http\Controllers;

use App\Models\MatchChat;
use App\Models\UserPrediction;
use Carbon\CarbonImmutable;

/** /community: where fans meet: this week's top predictors and the match rooms. */
class CommunityController extends Controller
{
    public function index()
    {
        // This week, Monday to Sunday in Lagos; matches.match_date holds UTC wall-clock values.
        $start = CarbonImmutable::now('Africa/Lagos')->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addWeek()->subSecond();
        $top = UserPrediction::with('user:id,name')
            ->whereHas('match', fn ($q) => $q->whereBetween('match_date', [$start->utc()->format('Y-m-d H:i:s'), $end->utc()->format('Y-m-d H:i:s')]))
            ->selectRaw('user_id, SUM(points_earned) as points, COUNT(*) as picks')
            ->groupBy('user_id')->orderByDesc('points')->orderByDesc('picks')->take(10)->get()
            ->filter(fn ($row) => $row->user);

        return view('community.index', [
            'top' => $top,
            'chats' => MatchChat::with(['user:id,name', 'match.homeTeam', 'match.awayTeam'])->latest()->take(6)->get()->filter(fn ($c) => $c->match),
            'weekStart' => $start,
        ]);
    }
}
