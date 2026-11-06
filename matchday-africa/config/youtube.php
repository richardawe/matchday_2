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

    // Schedule a broadcast for every matchday automatically (otherwise only from the admin page).
    'auto' => (bool) env('YOUTUBE_AUTO', false),

    // public, unlisted or private. Start unlisted until the first stream has been checked.
    'privacy' => in_array(env('YOUTUBE_PRIVACY', 'unlisted'), ['public', 'unlisted', 'private'], true) ? env('YOUTUBE_PRIVACY', 'unlisted') : 'unlisted',

    // A matchday window: from this long before the first kick-off to this long after the last.
    'before_minutes' => (int) env('STREAM_PRE_KICKOFF_MIN', 15),
    'after_minutes' => 135 + (int) env('STREAM_POST_MATCH_MIN', 10),

    // Broadcasts are made this far ahead.
    'ahead_hours' => 48,

    'scopes' => ['https://www.googleapis.com/auth/youtube'],
];
