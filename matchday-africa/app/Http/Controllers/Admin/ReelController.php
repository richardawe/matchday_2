<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Services\ReelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/** The reel desk: every recent Premier League match, its reel, and whether its goals are all in. */
class ReelController extends Controller
{
    public function index(ReelService $reels)
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->whereHas('league', fn ($q) => $q->where('short_code', 'PL')->orWhere('espn_slug', 'eng.1'))
            ->whereIn('status', ['FINISHED', 'FT'])
            ->where('match_date', '>=', now('UTC')->subDays(21))
            ->latest('match_date')->get();
        $goals = MatchEvent::whereIn('match_id', $matches->pluck('id'))->where('type', 'goal')
            ->selectRaw('match_id, count(*) n')->groupBy('match_id')->pluck('n', 'match_id');

        return view('admin.reels.index', [
            'matches' => $matches,
            'goals' => $goals,
            'renderer' => config('reel.renderer'),
            'posters' => $matches->mapWithKeys(fn ($m) => [$m->id => (bool) $reels->posterPath($m)]),
        ]);
    }

    public function queue(FootballMatch $match, ReelService $reels): RedirectResponse
    {
        $queued = $reels->queue($match);

        return back()->with($queued ? 'success' : 'error',
            $queued ? 'Queued: the scheduler renders it within a few minutes.' : 'This match can\'t have a reel (not finished, or a club has no army art).');
    }

    public function queueRange(Request $request): RedirectResponse
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        Artisan::call('reel:queue', ['--from' => $data['from'], '--to' => $data['to']]);

        return back()->with('success', trim(Artisan::output()));
    }
}
