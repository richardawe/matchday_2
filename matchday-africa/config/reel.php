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
    // With the server renderer: when several matches finish together, render one here and send the
    // rest to GitHub Actions (github; needs REEL_GITHUB_TOKEN) so every reel lands within minutes.
    'overflow' => env('REEL_OVERFLOW', 'off'),
    'node' => env('REEL_NODE', 'node'),
    'timeout' => (int) env('REEL_TIMEOUT', 1200),
    // Wait this long after full time so late corrections (VAR, stoppage-time events) make the cut.
    'delay_minutes' => (int) env('REEL_DELAY_MINUTES', 3),
    'voice' => env('REEL_VOICE', 'en'),
    // classic: the dark 20-second reel. v2: the bright comic reel, 20–45 s, told from the live
    // Griot timeline when it has posts (woodwork, big chances, VAR…), each army in a mood per moment.
    'style' => env('REEL_STYLE', 'classic'),
    // Spoken commentary over the reel (Kokoro, from radio/): one factual line per scene. Needs the
    // radio runtime (Node 22.13+, radio/node_modules); server renderer only. A failed voice step
    // leaves the reel without commentary rather than failing it.
    'narration' => (bool) env('REEL_NARRATION', false),
    'narration_voice' => env('REEL_NARRATION_VOICE', 'bm_george'),
    'github' => [
        'repo' => env('REEL_GITHUB_REPO', 'richardawe/matchday_2'),
        'token' => env('REEL_GITHUB_TOKEN'),
        'workflow' => 'render-reel.yml',
        'ref' => env('REEL_GITHUB_REF', 'main'),
    ],
];
