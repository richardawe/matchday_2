<?php

return [
    'enabled' => (bool) env('RADIO_ENABLED', false),
    'auto_publish' => (bool) env('RADIO_AUTO_PUBLISH', false),
    'timezone' => 'Africa/Lagos',
    'hours' => [6, 12, 18],
    // Explicitly approved news sources only; RSS availability is not a reuse licence.
    'news_sources' => array_values(array_filter(array_map('trim', explode(',', env('RADIO_NEWS_SOURCES', ''))))),
    'node' => env('RADIO_NODE', env('REEL_NODE', 'node')),
    'ffmpeg' => env('RADIO_FFMPEG', 'ffmpeg'),
    'voice' => env('RADIO_VOICE', 'bm_george'),
    'cache' => env('RADIO_TTS_CACHE', storage_path('app/tts-cache')),
    'timeout' => 1200,
    'max_attempts' => 3,
    'bitrate' => 24,
    'disclosure' => 'This bulletin is written with AI and voiced using Kokoro. Scores and reports reflect the sources available at recording time.',
];
