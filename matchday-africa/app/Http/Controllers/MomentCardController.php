<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\MatchMoment;
use App\Services\BanterCardRenderer;
use App\Services\FixtureCardRenderer;
use App\Services\StoryCardRenderer;

class MomentCardController extends Controller
{
    /** A chapter card PNG, drawn on first request and cached on disk after that. */
    public function show(MatchMoment $moment, string $voice, string $format, StoryCardRenderer $cards)
    {
        return response()->file($cards->path($moment, $voice, $format), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** The pre-match fixture card (16:9) that X previews carry. */
    public function fixture(FootballMatch $match, FixtureCardRenderer $cards)
    {
        return response()->file($cards->fixturePath($match), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** The full-time banter card (9:16) for one side, for WhatsApp status. */
    public function banter(FootballMatch $match, string $side, string $voice, BanterCardRenderer $cards)
    {
        abort_unless(BanterCardRenderer::ready($match), 404);

        return response()->file($cards->cardPath($match, $side, $voice), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
