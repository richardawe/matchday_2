<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Services\Griot\GriotChallengeService;
use App\Services\HomeShowcase;
use App\Services\PlayerPages;
use App\Services\ReelService;
use App\Support\ClubName;

/** Shareable pages for the 20-second reels: the latest reels and one page per reel. */
class ReelPageController extends Controller
{
    public function index(HomeShowcase $showcase)
    {
        $reels = $showcase->reels(60, 45);
        $all = collect([$reels['featured']])->filter()->concat($reels['rail'])->sortByDesc('played')->values();

        $slideshows = \App\Models\Slideshow::ready() ? \App\Models\Slideshow::published()->latest('approved_at')->limit(24)->get() : collect();

        $featured = app(\App\Services\SocialVideos::class)->row();

        return view('reels.index', ['reels' => $all, 'slideshows' => $slideshows, 'featured' => $featured]);
    }

    public function show(FootballMatch $match, ReelService $reels, HomeShowcase $showcase)
    {
        abort_unless($reels->path($match), 404);
        $match->load(['homeTeam', 'awayTeam', 'league']);
        $reel = $showcase->reelCard($match);
        $list = $showcase->reels(8);
        $more = collect([$list['featured']])->filter()->concat($list['rail'])
            ->reject(fn ($r) => $r['id'] === $match->id)->take(6)->values();

        return view('reels.show', ['match' => $match, 'reel' => $reel, 'more' => $more, 'cta' => $this->cta($match)])->with('content', [
            'title' => "{$reel['title']} in 20 seconds: the match reel | Matchday Africa",
            'description' => "Watch every goal and red card from {$match->homeTeam?->name} v {$match->awayTeam?->name} ({$match->league?->name}) in a 20-second reel, made minutes after full time.",
            'url' => $reel['page'],
            'type' => 'video.other',
            'image' => \App\Services\FixtureCardRenderer::url($match),
            'image_width' => '1200', 'image_height' => '675',
            'video' => $reel['video'],
            'json_ld' => array_filter(['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => "{$reel['title']}: the 20-second reel",
                'description' => "Every goal and red card from {$match->homeTeam?->name} v {$match->awayTeam?->name} in twenty seconds.",
                'thumbnailUrl' => [$reel['poster'] ?? \App\Services\FixtureCardRenderer::url($match)], 'uploadDate' => data_get($match->metadata, 'reel.ready_at') ?? $match->updated_at?->toIso8601String(),
                'duration' => 'PT20S', 'contentUrl' => $reel['video'], 'embedUrl' => $reel['page']]),
        ]);
    }

    /**
     * What to do after watching: an open Beat the Griot round, else call the next match of either club.
     * Guests can do both without an account.
     */
    private function cta(FootballMatch $match): ?array
    {
        if (GriotChallengeService::ready() && app(GriotChallengeService::class)->open()) {
            return ['label' => 'Beat the Griot', 'href' => route('griot.challenge', [], false)];
        }
        $next = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->whereIn('status', PlayerPages::UPCOMING)
            ->where('match_date', '>', now('UTC')->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereIn('home_team_id', array_filter([$match->home_team_id, $match->away_team_id]))
                ->orWhereIn('away_team_id', array_filter([$match->home_team_id, $match->away_team_id])))
            ->orderBy('match_date')->first();

        return $next ? ['label' => 'Call the score: '.ClubName::short($next->homeTeam).' v '.ClubName::short($next->awayTeam),
            'href' => route('matches.show', $next, false).'#prediction-arena'] : null;
    }
}
