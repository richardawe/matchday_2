<?php

/*
|--------------------------------------------------------------------------
| YouTube LIVE for Matchday Live (/admin/youtube)
|--------------------------------------------------------------------------
|
| The channel is connected once from /admin/youtube (OAuth; the refresh token is stored encrypted).
| youtube:broadcasts then schedules one broadcast per matchday on the channel's reusable stream
| key; each starts when the picture arrives and stops at the end of the window. Something that
| renders /stream (OBS or a cloud machine) sends the picture to the stream key shown on the page.
|
*/

return [
    'client_id' => env('YOUTUBE_CLIENT_ID'),
    'client_secret' => env('YOUTUBE_CLIENT_SECRET'),

    // The Matchday Africa channel: connecting any other channel is refused and nothing is uploaded elsewhere.
    'channel_id' => env('YOUTUBE_CHANNEL_ID') ?: 'UCJ9Asmir1hBOHHQs9qSCkHg',

    // Schedule a broadcast for every matchday automatically (otherwise only from the admin page).
    'auto' => (bool) env('YOUTUBE_AUTO', false),

    // public, unlisted or private. Start unlisted until the first stream has been checked.
    'privacy' => in_array(env('YOUTUBE_PRIVACY', 'unlisted'), ['public', 'unlisted', 'private'], true) ? env('YOUTUBE_PRIVACY', 'unlisted') : 'unlisted',

    // A matchday window: from this long before the first kick-off to this long after the last.
    'before_minutes' => (int) env('STREAM_PRE_KICKOFF_MIN', 15),
    'after_minutes' => 135 + (int) env('STREAM_POST_MATCH_MIN', 10),

    // Broadcasts are made this far ahead.
    'ahead_hours' => 48,

    // Automatic broadcasts run only while at least this many streamed matches are on at once
    // (the stream screen then shows them all together). The admin page can still schedule any matchday.
    'min_matches' => max(1, (int) env('YOUTUBE_MIN_MATCHES', 3)),

    // How long a match counts as "on" from kick-off: two halves, half-time and stoppage.
    'match_minutes' => 115,

    // youtube covers live broadcasts (and uploads); youtube.upload is the uploader's own scope.
    'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.upload'],

    /*
    | Every new reel as a YouTube Short (youtube:uploads, App\Services\YouTube\ReelUploads). An upload
    | costs 1,600 of the project's 10,000 daily quota units, so at most daily_uploads go up per
    | YouTube day (it resets at midnight Pacific time); the rest wait for the next day.
    */
    'uploads' => [
        'enabled' => (bool) env('YOUTUBE_UPLOADS', false),
        'daily_uploads' => (int) env('YOUTUBE_DAILY_UPLOADS', 6),
        'quota_timezone' => 'America/Los_Angeles',
        // One try and three retries, waiting this many minutes before each retry.
        'retry_minutes' => [5, 20, 60],
        'privacy' => 'public',
        'category' => '17', // Sports
        'chunk_bytes' => 4 * 1024 * 1024, // a multiple of 256 KiB, as resumable uploads need
    ],
];
