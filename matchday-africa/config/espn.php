<?php

/*
|--------------------------------------------------------------------------
| ESPN public scoreboard feed
|--------------------------------------------------------------------------
|
| Undocumented feed used as a free live-score stopgap; see docs/ESPN_LIVE_DATA_PLAN.md
| for the coverage research and the terms-of-use risk. Keep ESPN_ENABLED=false until
| `php artisan migrate` has added the espn_* columns.
|
| Each league: our name and country, the football-data.org code
| (and competition ID) when that provider already owns the fixture list (ESPN then only
| links to and updates those matches),
| and whether ESPN publishes full match summaries (stats, subs, line-ups).
|
*/

return [
    'enabled' => (bool) env('ESPN_ENABLED', false),
    // Shadow mode: only record ESPN's view in match metadata and log where it disagrees with ours.
    'shadow' => (bool) env('ESPN_SHADOW', false),
    'base_url' => env('ESPN_BASE_URL', 'https://site.api.espn.com/apis/site/v2/sports/soccer'),
    'timeout' => (int) env('ESPN_TIMEOUT', 8),

    // How long ESPN's last update stays authoritative over slower providers.
    'authority_minutes' => 10,
    // Leagues are polled live from this long before kick-off until this long after.
    'live_window' => ['before_minutes' => 15, 'after_minutes' => 180],
    // Scoreboard passes per scheduler minute (cPanel cron cannot run more often).
    'live_passes' => 3,
    'fixtures_days_back' => 2,
    'fixtures_days_ahead' => 10,
    // Pause between fixture-sync requests.
    'request_pause_ms' => 250,
    // Circuit breaker: pause ESPN after this many consecutive failures.
    'breaker_failures' => 5,
    'breaker_minutes' => 15,

    'leagues' => [
        'eng.1' => ['name' => 'Premier League', 'country' => 'England', 'country_code' => 'ENG', 'fd_code' => 'PL', 'fd_id' => 2021, 'details' => true],
        'eng.2' => ['name' => 'Championship', 'country' => 'England', 'country_code' => 'ENG', 'fd_code' => 'ELC', 'fd_id' => 2016, 'details' => true],
        'eng.fa' => ['name' => 'FA Cup', 'country' => 'England', 'country_code' => 'ENG', 'fd_code' => null, 'details' => true],
        'eng.league_cup' => ['name' => 'EFL Cup', 'country' => 'England', 'country_code' => 'ENG', 'fd_code' => null, 'details' => true],
        'esp.1' => ['name' => 'Primera Division', 'country' => 'Spain', 'country_code' => 'ESP', 'fd_code' => 'PD', 'fd_id' => 2014, 'details' => true],
        'ita.1' => ['name' => 'Serie A', 'country' => 'Italy', 'country_code' => 'ITA', 'fd_code' => 'SA', 'fd_id' => 2019, 'details' => true],
        'ger.1' => ['name' => 'Bundesliga', 'country' => 'Germany', 'country_code' => 'DEU', 'fd_code' => 'BL1', 'fd_id' => 2002, 'details' => true],
        'fra.1' => ['name' => 'Ligue 1', 'country' => 'France', 'country_code' => 'FRA', 'fd_code' => 'FL1', 'fd_id' => 2015, 'details' => true],
        'ned.1' => ['name' => 'Eredivisie', 'country' => 'Netherlands', 'country_code' => 'NLD', 'fd_code' => 'DED', 'fd_id' => 2003, 'details' => true],
        'por.1' => ['name' => 'Primeira Liga', 'country' => 'Portugal', 'country_code' => 'PRT', 'fd_code' => 'PPL', 'fd_id' => 2017, 'details' => true],
        'tur.1' => ['name' => 'Süper Lig', 'country' => 'Turkey', 'country_code' => 'TUR', 'fd_code' => null, 'details' => true],
        'uefa.champions' => ['name' => 'UEFA Champions League', 'country' => 'Europe', 'country_code' => null, 'fd_code' => 'CL', 'fd_id' => 2001, 'details' => true],
        'uefa.europa' => ['name' => 'UEFA Europa League', 'country' => 'Europe', 'country_code' => null, 'fd_code' => null, 'details' => true],
        'uefa.europa.conf' => ['name' => 'UEFA Conference League', 'country' => 'Europe', 'country_code' => null, 'fd_code' => null, 'details' => true],
        'caf.nations' => ['name' => 'Africa Cup of Nations', 'country' => 'Africa', 'country_code' => null, 'fd_code' => null, 'details' => true],
        'caf.nations_qual' => ['name' => 'AFCON Qualifying', 'country' => 'Africa', 'country_code' => null, 'fd_code' => null, 'details' => true],
        'fifa.worldq.caf' => ['name' => 'World Cup Qualifying - CAF', 'country' => 'Africa', 'country_code' => null, 'fd_code' => null, 'details' => true],
        'fifa.world' => ['name' => 'FIFA World Cup', 'country' => 'World', 'country_code' => null, 'fd_code' => 'WC', 'fd_id' => 2000, 'details' => true],
        'caf.champions' => ['name' => 'CAF Champions League', 'country' => 'Africa', 'country_code' => null, 'fd_code' => null, 'details' => false],
        'caf.confed' => ['name' => 'CAF Confederation Cup', 'country' => 'Africa', 'country_code' => null, 'fd_code' => null, 'details' => false],
        'rsa.1' => ['name' => 'South African Premiership', 'country' => 'South Africa', 'country_code' => 'ZAF', 'fd_code' => null, 'details' => true],
        // Domestic African leagues: ESPN coverage lags (still on 2025-26 in Oct 2026), shown whenever it appears.
        'nga.1' => ['name' => 'Nigerian Professional League', 'country' => 'Nigeria', 'country_code' => 'NGA', 'fd_code' => null, 'details' => false],
        'gha.1' => ['name' => 'Ghana Premier League', 'country' => 'Ghana', 'country_code' => 'GHA', 'fd_code' => null, 'details' => false],
        'ken.1' => ['name' => 'Kenyan Premier League', 'country' => 'Kenya', 'country_code' => 'KEN', 'fd_code' => null, 'details' => false],
        'uga.1' => ['name' => 'Ugandan Premier League', 'country' => 'Uganda', 'country_code' => 'UGA', 'fd_code' => null, 'details' => false],
        'zam.1' => ['name' => 'Zambian Super League', 'country' => 'Zambia', 'country_code' => 'ZMB', 'fd_code' => null, 'details' => false],
    ],

    // ESPN display name => our (football-data.org) name, only where token matching can't tell
    // they're the same club. "Atlético Madrid" vs "Club Atlético de Madrid" already matches.
    'team_aliases' => [
        'Bayern Munich' => 'FC Bayern München',
        'FC Cologne' => '1. FC Köln',
        'Lyon' => 'Olympique Lyonnais',
        'Sporting CP' => 'Sporting Clube de Portugal',
        'Inter Milan' => 'FC Internazionale Milano',
    ],
];
