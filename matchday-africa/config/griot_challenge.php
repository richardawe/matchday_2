<?php

// Beat the Griot: every Premier League round the Griot picks three matches, calls each score and
// dares fans to beat its points. Ten fans who beat it are drawn at random for 1GB of data; they
// send their number to one of Matchday's social accounts and an admin credits them by hand.
return [
    'enabled' => (bool) env('GRIOT_CHALLENGE', false),
    // Full-time banter cards for WhatsApp status on match pages (BanterCardRenderer).
    'banter' => (bool) env('BANTER_CARDS', false),
    'matches' => 3,
    'winners' => (int) env('GRIOT_CHALLENGE_WINNERS', 10),
    'prize' => env('GRIOT_CHALLENGE_PRIZE', '1GB of data'),
    // Nigerian numbers for now (MTN, Airtel, Glo, 9mobile); Kenya and South Africa later.
    'networks' => ['MTN', 'Airtel', 'Glo', '9mobile'],
    'country' => 'Nigeria',
    // Calls close at each match's kick-off; a round is planned only when its first match is at
    // least this far away, so fans have time to play.
    'min_lead_hours' => 6,
    // Leagues the Griot picks from (League short_code / espn_slug).
    'leagues' => ['PL', 'eng.1'],
    // Clubs fans in Africa follow most: a small boost when choosing the three matches.
    'big_clubs' => ['arsenal', 'chelsea', 'liverpool', 'manchester city', 'manchester united', 'tottenham hotspur', 'newcastle united'],
];
