<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Services\HomeShowcase;
use App\Services\ReelService;

/** Shareable pages for the 20-second reels: the latest reels and one page per reel. */
class ReelPageController extends Controller
{
    public function index(HomeShowcase $showcase)
    {
        $reels = $showcase->reels(60, 45);
        $all = collect([$reels['featured']])->filter()->concat($reels['rail'])->sortByDesc('played')->values();

        return view('reels.index', ['reels' => $all]);
    }

    public function show(FootballMatch $match, ReelService $reels, HomeShowcase $showcase)
    {
        abort_unless($reels->path($match), 404);
        $match->load(['homeTeam', 'awayTeam', 'league']);
        $reel = $showcase->reelCard($match);
        $list = $showcase->reels(8);
        $more = collect([$list['featured']])->filter()->concat($list['rail'])
            ->reject(fn ($r) => $r['id'] === $match->id)->take(6)->values();

        return view('reels.show', ['match' => $match, 'reel' => $reel, 'more' => $more])->with('content', $match);
    }
}
