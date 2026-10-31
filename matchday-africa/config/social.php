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
];
