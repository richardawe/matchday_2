<?php

/*
|--------------------------------------------------------------------------
| Competition order and regions
|--------------------------------------------------------------------------
|
| How the home page and match centre rank competitions, most important first.
| Keys are a league's football-data.org code (short_code) or ESPN slug. Leagues
| not listed sort after these, in the "other" region.
|
*/

return [
    'regions' => [
        'africa' => 'Africa',
        'europe' => 'Europe',
        'international' => 'International',
        'other' => 'More football',
    ],

    'order' => [
        'PL' => 'europe',
        'caf.nations' => 'africa',
        'WC' => 'international',
        'fifa.world' => 'international',
        'fifa.worldq.caf' => 'africa',
        'caf.nations_qual' => 'africa',
        'CL' => 'europe',
        'uefa.champions' => 'europe',
        'caf.champions' => 'africa',
        'caf.confed' => 'africa',
        'rsa.1' => 'africa',
        'nga.1' => 'africa',
        'gha.1' => 'africa',
        'ken.1' => 'africa',
        'uga.1' => 'africa',
        'zam.1' => 'africa',
        'PD' => 'europe',
        'SA' => 'europe',
        'BL1' => 'europe',
        'FL1' => 'europe',
        'uefa.europa' => 'europe',
        'uefa.europa.conf' => 'europe',
        'ELC' => 'europe',
        'eng.fa' => 'europe',
        'eng.league_cup' => 'europe',
        'DED' => 'europe',
        'PPL' => 'europe',
        'tur.1' => 'europe',
    ],
];
