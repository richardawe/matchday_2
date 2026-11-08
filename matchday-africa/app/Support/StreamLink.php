<?php

namespace App\Support;

use App\Models\YoutubeBroadcast;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Where "Watch live" goes: the matchday broadcast on YouTube while it is live (public or
 * unlisted, never a private test), otherwise the stream screen itself (/stream), which shows the
 * live scores, the Griot and a countdown to the next kick-off. Hidden while STREAM_PAGE is off.
 */
class StreamLink
{
    public static function enabled(): bool
    {
        return (bool) config('stream.enabled');
    }

    public static function url(): string
    {
        return self::broadcast()?->watchUrl() ?? route('stream.show');
    }

    /** True while a matchday broadcast is live on YouTube. */
    public static function onYouTube(): bool
    {
        return self::broadcast() !== null;
    }

    private static function broadcast(): ?YoutubeBroadcast
    {
        // Looked up once per request (the page asks several times).
        app()->scopedIf('stream-link.broadcast', function () {
            try {
                return Schema::hasTable('youtube_broadcasts')
                    ? (YoutubeBroadcast::where('status', 'live')->where('privacy', '!=', 'private')
                        ->where('window_key', 'not like', 'test-%')->latest('starts_at')->first() ?? false)
                    : false;
            } catch (Throwable) {
                return false;
            }
        });

        return app('stream-link.broadcast') ?: null;
    }
}
