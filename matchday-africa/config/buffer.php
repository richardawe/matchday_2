<?php

/*
|--------------------------------------------------------------------------
| Buffer (X, Instagram, TikTok)
|--------------------------------------------------------------------------
|
| Sends posts to Buffer's GraphQL API (https://developers.buffer.com). Make a personal API key
| at publish.buffer.com/settings/api, then list your channel IDs with
| `php artisan buffer:publish --channels`. Media goes by public URL, so APP_URL must be the live
| https address. The client is the one ngr.ltd and housedata.uk run in production (BufferClient).
|
*/

return [
    'key' => env('BUFFER_API_KEY'),
    'endpoint' => env('BUFFER_ENDPOINT', 'https://api.buffer.com/graphql'),
    // shareNow posts straight away (best for live goals); addToQueue uses your Buffer schedule.
    'mode' => env('BUFFER_MODE', 'shareNow'),
    'channels' => [
        'x' => env('BUFFER_CHANNEL_X'),
        'instagram' => env('BUFFER_CHANNEL_INSTAGRAM'),
        'tiktok' => env('BUFFER_CHANNEL_TIKTOK'),
    ],
    // Live Griot chapters and reels: what goes where. Cards are 4:5 images; reels are 9:16 videos.
    'routes' => [
        'goal' => ['x', 'instagram'],
        'red_card' => ['x'],
        'full_time' => ['x'],
        'reel' => ['x', 'instagram', 'tiktok'],
    ],
    // Only chapters this recent are sent, so switching Buffer on doesn't flood old matches.
    'max_age_minutes' => 30,
    'hashtags' => '#PremierLeague #MatchdayAfrica',

    /*
    | API budget. The free plan allows 3,000 requests a month on its one key; every request is
    | counted per UTC month in `buffer_usage`. At the soft limit, calls that can wait (channel
    | listings) stop; at the hard limit posting stops too.
    */
    'budget' => [
        'monthly_limit' => (int) env('BUFFER_MONTHLY_REQUESTS', 3000),
        'soft_limit' => (int) env('BUFFER_SOFT_LIMIT', 2500),
        'hard_limit' => (int) env('BUFFER_HARD_LIMIT', 2900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily posting slots (every channel)
    |--------------------------------------------------------------------------
    |
    | Each channel (X, Instagram, TikTok) posts at most once per slot, so with three slots each
    | account gets at most three posts a Lagos day, spread morning, afternoon and evening. Live
    | goal cards and reels use the same slots: a slot already used holds them back.
    |
    */
    'slots' => array_values(array_filter(array_map('trim', explode(',', env('BUFFER_SLOTS', '08:00,13:00,19:00'))))),

    /*
    |--------------------------------------------------------------------------
    | The planned timeline (growth_posts)
    |--------------------------------------------------------------------------
    |
    | `twitter:engage` plans posts into growth_posts, one row per subject and channel: new news
    | articles, a preview of each top fixture before kick-off, the day's fixtures in the morning
    | and the results at night. X takes all of them; Instagram only those with an image (the
    | fixture cards); TikTok takes video only, so it gets the full-time reels and nothing here.
    | With `auto` on they are queued and `buffer:publish` sends the most valuable due post when
    | a channel's slot is free. With `auto` off they stay drafts on the admin page.
    |
    */
    'x' => [
        'auto' => (bool) env('BUFFER_AUTO', false),
        // Which planned post wins a free slot: lower first, then the earliest due.
        'priority' => ['results' => 1, 'preview' => 2, 'fixtures' => 3, 'news' => 4, 'custom' => 0],
        'timezone' => 'Africa/Lagos',
        // Previews go out this long before kick-off, for at most this many fixtures a day
        // (most important competitions first, config/competitions.php).
        'preview_lead_minutes' => 180,
        'previews_per_day' => (int) env('BUFFER_PREVIEWS_PER_DAY', 3),
        // Lagos times for the round-ups.
        'fixtures_at' => '08:00',
        'results_at' => '22:45',
        // Articles published longer ago than this are not shared (so switching on doesn't flood).
        'news_max_age_hours' => 6,
        'hashtags' => '#MatchdayAfrica',
        // Competition tags, by football-data.org code or ESPN slug.
        'competition_tags' => [
            'PL' => '#PremierLeague', 'eng.1' => '#PremierLeague',
            'CL' => '#UCL', 'uefa.champions' => '#UCL',
            'uefa.europa' => '#UEL',
            'PD' => '#LaLiga', 'SA' => '#SerieA', 'BL1' => '#Bundesliga', 'FL1' => '#Ligue1',
            'caf.nations' => '#AFCON', 'caf.nations_qual' => '#AFCONQualifiers',
            'fifa.worldq.caf' => '#WCQ', 'WC' => '#WorldCup', 'fifa.world' => '#WorldCup',
            'caf.champions' => '#CAFCL', 'nga.1' => '#NPFL',
        ],
    ],
];
