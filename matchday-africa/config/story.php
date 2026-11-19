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

// Sizes, the safe zone, cut lengths, colours and the music bed come from the design tokens
// (resources/design/tokens.json, docs/design-language.md); nothing visual is set here.
$design = json_decode((string) file_get_contents(__DIR__.'/../resources/design/tokens.json'), true);

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

    'size' => [$design['canvas']['width'], $design['canvas']['height']],
    'fps' => (int) env('STORY_FPS', $design['canvas']['fps']),
    'timeout' => (int) env('STORY_TIMEOUT', 900),
    'crf' => 26,

    // Length rules for match stories (seconds): never under min, aim for the target window, never over max.
    'min_seconds' => 61,
    'target' => [65, 75],
    'max_seconds' => 75,
    // Each cut lasts between these (the hook is fixed): design tokens motion.cut.
    'cut' => [$design['motion']['cut']['min'], $design['motion']['cut']['max']],
    'hook_seconds' => $design['motion']['cut']['hook'],
    // Players tried for the opening photo (featured first, then the next best who took part) before flags.
    'photo_candidates' => (int) env('STORY_PHOTO_CANDIDATES', 8),

    // TikTok/Reels/Shorts safe zone for text (design tokens grid.safe): top 250 px, bottom 380 px and right 140 px stay clear.
    'safe' => $design['grid']['safe'],

    // Our own music, nobody else's (design tokens music). STORY_MUSIC_PATH can point at another copy of the same track.
    'music' => [
        'title' => $design['music']['title'],
        'paths' => array_values(array_filter([env('STORY_MUSIC_PATH'), $design['music']['file']])),
        'loudness' => $design['music']['loudness'],
        'fade_in' => $design['music']['fade_in'],
        'fade_out' => $design['music']['fade_out'],
    ],

    'languages' => ['en', 'fr'],
    'hashtags' => '#MatchdayAfrica #AfricanFootball',
];
