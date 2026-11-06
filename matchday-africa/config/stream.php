<?php

/*
|--------------------------------------------------------------------------
| Matchday Live: the vertical stream screen (/stream)
|--------------------------------------------------------------------------
|
| A 1080×1920 page that a YouTube (or TikTok) live stream shows: live scores,
| the armies, the Griot's lines, goal and red-card moments, and a countdown
| between matches. The page asks /stream/feed for a fresh snapshot every few
| seconds and changes in place, so the stream never shows a reload.
| Off until STREAM_PAGE=true. Art stays crest-free (armies, colour tiles).
|
*/

return [
    'enabled' => (bool) env('STREAM_PAGE', false),

    // League short codes (football-data.org) or ESPN slugs whose matches go on stream.
    'competitions' => array_values(array_filter(array_map('trim', explode(',', (string) env('STREAM_COMPETITIONS', 'PL,eng.1'))))),

    // How often the page asks for a new snapshot.
    'poll_seconds' => (int) env('STREAM_POLL_SECONDS', 3),

    // Show "Updating…" when the scores feed has not moved for this long during a live match.
    'stale_seconds' => (int) env('STREAM_STALE_SECONDS', 90),

    // How long a goal or red card owns the screen, and how long each live match is featured.
    'moment_seconds' => 8,
    'rotate_seconds' => 20,

    // Finished matches stay in the results list for this many hours.
    'results_hours' => 12,
];
