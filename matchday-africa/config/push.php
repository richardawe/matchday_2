<?php

/*
|--------------------------------------------------------------------------
| Web push and match alerts
|--------------------------------------------------------------------------
|
| Signed-in users press "Alert me" on any match and get kick-off, goals, red cards, half-time,
| full time and the reel on their phone (web push) and in their inbox. Off until PUSH_ENABLED=true
| and the VAPID keys exist: `php artisan push:keys --write` creates them in .env.
|
*/

return [
    'enabled' => (bool) env('PUSH_ENABLED', false),
    'vapid' => [
        'public_key' => env('PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('PUSH_VAPID_PRIVATE_KEY'),
        // Who push services contact about misuse: a mailto: or https: URL.
        'subject' => env('PUSH_VAPID_SUBJECT', 'mailto:hello@matchday.africa'),
    ],
    // How long a push service may hold a message for an offline phone. A goal is old news fast.
    'ttl' => (int) env('PUSH_TTL', 1800),
    // A subscription is dropped after this many failed sends in a row (410 Gone drops it at once).
    'max_failures' => 5,
    // Kick-off reminder, minutes before.
    'reminder_minutes' => 30,
];
