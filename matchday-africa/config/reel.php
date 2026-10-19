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
    'github' => [
        'repo' => env('REEL_GITHUB_REPO', 'richardawe/matchday_2'),
        'token' => env('REEL_GITHUB_TOKEN'),
        'workflow' => 'render-reel.yml',
        'ref' => env('REEL_GITHUB_REF', 'main'),
    ],
];
