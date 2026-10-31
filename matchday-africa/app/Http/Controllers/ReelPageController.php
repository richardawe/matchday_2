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

        return view('reels.show', ['match' => $match, 'reel' => $reel, 'more' => $more])->with('content', [
            'title' => "{$reel['title']} in 20 seconds: the match reel | Matchday Africa",
            'description' => "Watch every goal and red card from {$match->homeTeam?->name} v {$match->awayTeam?->name} ({$match->league?->name}) in a 20-second reel, made minutes after full time.",
            'url' => $reel['page'],
            'type' => 'video.other',
            'image' => route('matches.card', ['match' => $match, 'v' => substr(md5($match->status.'|'.$match->home_score.'|'.$match->away_score), 0, 8)]),
            'image_width' => '1200', 'image_height' => '675',
            'video' => $reel['video'],
            'json_ld' => array_filter(['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => "{$reel['title']}: the 20-second reel",
                'description' => "Every goal and red card from {$match->homeTeam?->name} v {$match->awayTeam?->name} in twenty seconds.",
                'thumbnailUrl' => [$reel['poster'] ?? route('matches.card', $match)], 'uploadDate' => data_get($match->metadata, 'reel.ready_at') ?? $match->updated_at?->toIso8601String(),
                'duration' => 'PT20S', 'contentUrl' => $reel['video'], 'embedUrl' => $reel['page']]),
        ]);
    }
}
