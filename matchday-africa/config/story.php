<?php

/*
|--------------------------------------------------------------------------
| Match-story videos (story:render)
|--------------------------------------------------------------------------
|
| 1080x1920 vertical videos of 61-75 seconds told from the site's own data after full time
| (hook, build-up, one beat per goal or red card, stats, table or next fixture, banter, a closing
| question), and a radio_video per published Matchday Radio edition (the episode audio with
| animated captions from the transcript). Drawn frame by frame with GD and encoded by ffmpeg's
| image2pipe, so the reel renderer's small ffmpeg is enough. Off until STORY_VIDEOS=true.
|
*/

return [
    'enabled' => (bool) env('STORY_VIDEOS', false),

    // Competitions whose finished matches get a story automatically (league short code or ESPN slug).
    // Admins and the agent API can render any finished match.
    'competitions' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORY_COMPETITIONS', 'PL,eng.1'))))),
    // Wait this long after the match is marked finished (events settle), and give up after this many hours.
    'delay_minutes' => (int) env('STORY_DELAY_MINUTES', 2),
    'within_hours' => 12,
    // Renders allowed at once (each minute's run starts one while fewer are rendering).
    'concurrency' => max(1, (int) env('STORY_CONCURRENCY', 2)),
    'max_attempts' => 3,
    // A story is drawn again once when the score or events change within this many minutes of its render.
    'refresh_minutes' => 45,
    // Radio videos for published editions from the last N days.
    'radio_days' => 3,

    'size' => [1080, 1920],
    'fps' => (int) env('STORY_FPS', 30),
    'timeout' => (int) env('STORY_TIMEOUT', 900),
    'crf' => 26,
    // The Ken Burns background is redrawn every N frames (3 = ten times a second); identical frames reuse their JPEG.
    'zoom_every' => max(1, (int) env('STORY_ZOOM_EVERY', 3)),

    // Length rules for match stories (seconds): never under min, aim for the target window, never over max.
    'min_seconds' => 61,
    'target' => [65, 75],
    'max_seconds' => 75,
    // Each cut lasts between these (the hook is fixed).
    'cut' => [3.0, 5.0],
    'hook_seconds' => 3.0,

    // TikTok/Reels/Shorts safe zone for text: top 150 px, bottom 380 px and right 140 px stay clear.
    'safe' => ['left' => 90, 'right' => 940, 'top' => 150, 'bottom' => 1540],

    'colours' => ['ink' => '#0B0B0B', 'yellow' => '#FFD23F', 'paper' => '#FFFFFF', 'muted' => '#A7A7A7'],

    // Our own music, nobody else's. The owner adds the file; until then stories render silent and
    // the Content studio says so. Looked for in this order (relative to the app root).
    'music' => [
        'title' => 'Matchday original beat v1',
        'paths' => array_values(array_filter([
            env('STORY_MUSIC_PATH'),
            'resources/audio/matchday_original_beat_v1.mp3',
            'storage/app/music/matchday_original_beat_v1.mp3',
            'public/assets/matchday_original_beat_v1.mp3',
        ])),
        'loudness' => ['i' => -14, 'tp' => -2, 'lra' => 11],
        'fade_out' => 2.0,
    ],

    'languages' => ['en', 'fr'],
    'hashtags' => '#MatchdayAfrica #AfricanFootball',
];
