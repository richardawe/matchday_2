<?php

/*
|--------------------------------------------------------------------------
| Buffer (X, Instagram, TikTok)
|--------------------------------------------------------------------------
|
| Sends the Griot's chapter cards and full-time reels to Buffer's GraphQL API (public beta,
| https://developers.buffer.com). Make a personal API key at publish.buffer.com/settings/api,
| then list your channel IDs with `php artisan buffer:publish --channels`.
| Media goes by public URL, so APP_URL must be the live https address.
|
*/

return [
    'key' => env('BUFFER_API_KEY'),
    'endpoint' => env('BUFFER_ENDPOINT', 'https://api.buffer.com'),
    // shareNow posts straight away (best for live goals); addToQueue uses your Buffer schedule.
    'mode' => env('BUFFER_MODE', 'shareNow'),
    'channels' => [
        'x' => env('BUFFER_CHANNEL_X'),
        'instagram' => env('BUFFER_CHANNEL_INSTAGRAM'),
        'tiktok' => env('BUFFER_CHANNEL_TIKTOK'),
    ],
    // What goes where. Cards are 4:5 images; reels are 9:16 videos (TikTok takes video only).
    'routes' => [
        'goal' => ['x', 'instagram'],
        'red_card' => ['x'],
        'full_time' => ['x'],
        'reel' => ['x', 'instagram', 'tiktok'],
    ],
    // Only chapters this recent are sent, so switching Buffer on doesn't flood old matches.
    'max_age_minutes' => 30,
    'hashtags' => '#PremierLeague #MatchdayAfrica',
];
