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
    // 'griot': the Griot's blended voice over the talking-drum groove (radio/griot-sound.mjs); or a Kokoro voice ID.
    'voice' => env('RADIO_VOICE', 'griot'),
    // OpenRouter model for the script writer and fact checker (e.g. anthropic/claude-haiku-5.5). Empty uses
    // OPENROUTER_MODEL; when set, OPENROUTER_MODEL and its fallbacks stay behind it if it fails.
    'model' => env('RADIO_MODEL'),
    'cache' => env('RADIO_TTS_CACHE', storage_path('app/tts-cache')),
    'timeout' => 1200,
    'max_attempts' => 3,
    'bitrate' => 24,
    // Vertical 1080x1920 video of each published edition for the social queue (radio:video, RADIO_VIDEO_ENABLED).
    // Only editions longer than min_seconds are rendered (TikTok counts long posts from 60 s up).
    'video' => [
        'enabled' => (bool) env('RADIO_VIDEO_ENABLED', false),
        'min_seconds' => 60,
        'fps' => 30,
        'size' => [1080, 1920],
        'max_attempts' => 3,
        'days' => 3,           // only editions published in the last N days are rendered
        'timeout' => 900,
    ],
    // Podcast directory details for /listen/feed.xml: a crest-free square cover and the public contact from the site footer.
    'cover' => 'images/radio/cover.png',
    'owner_email' => env('RADIO_OWNER_EMAIL', 'info@3d7tech.com'),
    'disclosure' => 'This bulletin is written with AI and voiced using Kokoro, with synthesised music and sound effects. Scores and reports reflect the sources available at recording time.',
];
