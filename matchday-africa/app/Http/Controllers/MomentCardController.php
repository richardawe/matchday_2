<?php

namespace App\Http\Controllers;

use App\Models\MatchMoment;
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
}
