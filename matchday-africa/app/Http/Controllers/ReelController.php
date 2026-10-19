<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Services\ReelService;

class ReelController extends Controller
{
    /** The finished reel; byte ranges are supported so phones can seek and stream. */
    public function show(FootballMatch $match, ReelService $reels)
    {
        $file = $reels->path($match);
        abort_unless($file, 404);

        return response()->file($file, ['Content-Type' => 'video/mp4', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** The reel's story for the GitHub renderer (signed URL, art as public links). */
    public function props(FootballMatch $match, ReelService $reels)
    {
        return response()->json($reels->props($match, urls: true));
    }
}
