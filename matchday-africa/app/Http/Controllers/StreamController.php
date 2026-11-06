<?php

namespace App\Http\Controllers;

use App\Services\Stream\StreamFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/** Matchday Live: the vertical stream screen and the snapshot it refreshes from. */
class StreamController extends Controller
{
    public function show(): Response
    {
        abort_unless(config('stream.enabled'), 404);

        return response()->view('stream', [
            'pollSeconds' => max(2, (int) config('stream.poll_seconds', 3)),
            'momentSeconds' => (int) config('stream.moment_seconds', 8),
            'rotateSeconds' => (int) config('stream.rotate_seconds', 20),
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function feed(StreamFeed $feed): JsonResponse
    {
        abort_unless(config('stream.enabled'), 404);
        // Every open stream asks every few seconds; one database read a second serves them all.
        $snapshot = Cache::remember('stream:snapshot', now()->addSecond(), fn () => $feed->snapshot());

        return response()->json($snapshot)->header('Cache-Control', 'no-store');
    }
}
