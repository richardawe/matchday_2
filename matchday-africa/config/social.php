<?php

// Matchday Africa's own accounts. Shown in the header, footer, match and reel pages.
return [
    'accounts' => [
        'x' => ['name' => 'X', 'handle' => '@matchdayafrica', 'url' => 'https://x.com/matchdayafrica'],
        'tiktok' => ['name' => 'TikTok', 'handle' => '@matchday_africa', 'url' => 'https://www.tiktok.com/@matchday_africa'],
        'instagram' => ['name' => 'Instagram', 'handle' => '@matchdaydotafrica', 'url' => 'https://www.instagram.com/matchdaydotafrica/'],
    ],
    // The WhatsApp channel invite link (https://whatsapp.com/channel/...). The CTA hides while empty.
    'whatsapp_channel' => env('WHATSAPP_CHANNEL_URL'),

    // Whether the site itself may post to Buffer, X, Instagram or TikTok. Off: captions, cards and
    // growth_posts drafts are still made, and a separate posting bot reads them from /api/social-queue.
    // Reels, YouTube Shorts and YouTube LIVE are not affected.
    'autopost_enabled' => (bool) env('SOCIAL_AUTOPOST_ENABLED', false),

    // Bearer token for /api/social-queue (the posting bot). Empty: the API refuses every request.
    'queue_token' => env('SOCIAL_QUEUE_TOKEN'),

    // How far back the social queue looks, in days.
    'queue_days' => 14,
];
