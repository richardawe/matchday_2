<?php

/*
|--------------------------------------------------------------------------
| The Griot's 20-second full-time reel (Remotion, in reel/)
|--------------------------------------------------------------------------
|
| renderer: off | server | github
|   server  this host runs `node reel/render.mjs` (prove it first with `cd reel && npm ci && node check.mjs`)
|   github  the server asks GitHub Actions (.github/workflows/render-reel.yml) to render and
|           upload the MP4 to public/generated/reels by FTP
|
*/

return [
    'renderer' => env('REEL_RENDERER', 'off'),
    'node' => env('REEL_NODE', 'node'),
    'timeout' => (int) env('REEL_TIMEOUT', 1200),
    // Wait this long after full time so late corrections (VAR, stoppage-time events) make the cut.
    'delay_minutes' => (int) env('REEL_DELAY_MINUTES', 3),
    'voice' => env('REEL_VOICE', 'en'),
    // Speech bubbles (BanterService): written lines from config/banter.php, and up to `max_ai`
    // lines per reel from the AI service when `ai` is on. Each army keeps one language per match.
    'banter' => [
        'enabled' => (bool) env('REEL_BANTER', false),
        'ai' => (bool) env('REEL_BANTER_AI', false),
        'max_ai' => 2,
        'langs' => array_values(array_filter(array_map('trim', explode(',', env('REEL_BANTER_LANGS', 'en,pcm'))))),
    ],
    // Kokoro text-to-speech reads the bubbles aloud, one voice per army (reel/voice.mjs).
    'voiceover' => [
        'enabled' => (bool) env('REEL_VOICEOVER', false),
        // Where the Kokoro model (~90 MB) is kept, outside node_modules so reinstalls keep it.
        'cache' => env('REEL_TTS_CACHE', storage_path('app/tts-cache')),
        'home' => env('REEL_VOICE_HOME', 'am_fenrir'),
        'away' => env('REEL_VOICE_AWAY', 'bm_george'),
    ],
    'github' => [
        'repo' => env('REEL_GITHUB_REPO', 'richardawe/matchday_2'),
        'token' => env('REEL_GITHUB_TOKEN'),
        'workflow' => 'render-reel.yml',
        'ref' => env('REEL_GITHUB_REF', 'main'),
    ],
];
