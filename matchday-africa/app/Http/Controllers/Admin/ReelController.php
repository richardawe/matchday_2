<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Services\BanterService;
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

    /** Save edited bubble lines (same rules as the AI's) and re-render. */
    public function saveBanter(Request $request, FootballMatch $match, BanterService $banter, ReelService $reels): RedirectResponse
    {
        $lines = $request->validate(['lines' => 'required|array', 'lines.*' => 'required|string|max:'.BanterService::MAX_LENGTH])['lines'];
        $bubbles = data_get($match->metadata, 'reel.banter', []);
        $facts = $banter->factsFor($match);
        foreach ($bubbles as $i => $bubble) {
            $text = trim($lines[$i] ?? $bubble['text']);
            if ($text !== $bubble['text']) {
                if (!$banter->check($text, $facts)) {
                    return back()->with('error', "\"{$text}\" breaks a banter rule: armies only (no other names), real numbers only, nothing on the blocklist, 60 characters at most.");
                }
                $bubbles[$i] = ['text' => $text, 'source' => 'edited'] + $bubble;
            }
        }
        $reels->setBanter($match, $bubbles);
        $reels->queue($match);

        return back()->with('success', 'Lines saved; the reel re-renders within a few minutes.');
    }

    /** Draw new lines (written and AI) and re-render. */
    public function newBanter(FootballMatch $match, BanterService $banter, ReelService $reels): RedirectResponse
    {
        $reels->setBanter($match, $banter->compose($match));
        $reels->queue($match);

        return back()->with('success', 'New lines drawn; the reel re-renders within a few minutes.');
    }

    public function queueRange(Request $request): RedirectResponse
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        Artisan::call('reel:queue', ['--from' => $data['from'], '--to' => $data['to']]);

        return back()->with('success', trim(Artisan::output()));
    }
}
