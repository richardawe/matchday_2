<?php

/*
|--------------------------------------------------------------------------
| Club armies (crest-free)
|--------------------------------------------------------------------------
|
| Each Premier League club's warrior army for story cards, reels and the arena.
| Art comes only from the rights-safe set in public/war/downloads/rights-safe
| (no club crests); `art` is that file's name without .png, or null where no
| crest-free statue exists yet. Colours are the club's kit colours, used for
| the stripe bands and light; keys are lower-case club names without "FC".
|
*/

return [
    'source' => 'war/downloads/rights-safe',
    'optimised' => 'war/army',
    'widths' => [480, 960],

    'clubs' => [
        'arsenal' => ['army' => 'Roman Legion', 'art' => 'roman-legion', 'colours' => ['#EF0107', '#FFFFFF']],
        'aston villa' => ['army' => 'Anglo-Saxon Housecarls', 'art' => 'anglo-saxon-housecarl', 'colours' => ['#670E36', '#95BFE5']],
        'afc bournemouth' => ['army' => 'Barbary Corsairs', 'art' => 'barbary-corsair', 'colours' => ['#DA291C', '#111111']],
        'brentford' => ['army' => 'Prussian Line Infantry', 'art' => 'prussian-infantry', 'colours' => ['#E30613', '#FFFFFF']],
        'brighton & hove albion' => ['army' => 'Byzantine Cataphracts', 'art' => 'byzantine-cataphract', 'colours' => ['#0057B8', '#FFFFFF']],
        'chelsea' => ['army' => 'Ottoman Janissaries', 'art' => 'ottoman-janissary', 'colours' => ['#034694', '#DBA111']],
        'coventry city' => ['army' => 'Zulu Impi', 'art' => 'zulu-impi', 'colours' => ['#77B6E8', '#FFFFFF']],
        'crystal palace' => ['army' => 'Saxon Fyrd', 'art' => 'saxon-fyrd', 'colours' => ['#1B458F', '#C4122E']],
        'everton' => ['army' => 'Norman Knights', 'art' => 'norman-knight', 'colours' => ['#003399', '#FFFFFF']],
        'fulham' => ['army' => 'Venetian Marines', 'art' => 'venetian-marine', 'colours' => ['#F5F5F5', '#CC0000']],
        'hull city' => ['army' => 'Numidian Cavalry', 'art' => 'numidian-cavalry', 'colours' => ['#F18A01', '#111111']],
        'ipswich town' => ['army' => 'Rus’ Varangian Guard', 'art' => 'rus-varangian-guard', 'colours' => ['#0044A9', '#FFFFFF']],
        'leeds united' => ['army' => 'White Rose Yorkist Army', 'art' => 'white-rose-yorkist', 'colours' => ['#FFCD00', '#1D428A']],
        'liverpool' => ['army' => 'Norse Vikings', 'art' => 'norse-viking', 'colours' => ['#C8102E', '#00B2A9']],
        'manchester city' => ['army' => 'Mongol Horde', 'art' => 'mongol-horde', 'colours' => ['#6CABDD', '#1C2C5B']],
        'manchester united' => ['army' => 'Napoleonic Grenadiers', 'art' => 'napoleonic-grenadier', 'colours' => ['#DA291C', '#FBE122']],
        'newcastle united' => ['army' => 'Norse-Northumbrian Raiders', 'art' => 'northumbrian-raider', 'colours' => ['#241F20', '#FFFFFF']],
        'nottingham forest' => ['army' => 'Robin Hood’s Outlaws', 'art' => 'robin-hood-outlaw', 'colours' => ['#DD0000', '#FFFFFF']],
        'sunderland' => ['army' => 'Northern Rebel Clans', 'art' => 'northern-rebel', 'colours' => ['#EB172B', '#FFFFFF']],
        'tottenham hotspur' => ['army' => 'Spartan Hoplites', 'art' => 'spartan-hoplite', 'colours' => ['#132257', '#FFFFFF']],
        'west ham united' => ['army' => 'Rebel Irons', 'art' => null, 'colours' => ['#7A263A', '#1BB1E7']],
        'wolverhampton wanderers' => ['army' => 'Wolf Guard', 'art' => null, 'colours' => ['#FDB913', '#231F20']],
    ],

    // Names providers use that differ from the keys above.
    'aliases' => [
        'bournemouth' => 'afc bournemouth',
        'brighton' => 'brighton & hove albion',
        'spurs' => 'tottenham hotspur',
        'tottenham' => 'tottenham hotspur',
        'man city' => 'manchester city',
        'man united' => 'manchester united',
        'man utd' => 'manchester united',
        'newcastle' => 'newcastle united',
        'west ham' => 'west ham united',
        'wolves' => 'wolverhampton wanderers',
        'leeds' => 'leeds united',
        'hull' => 'hull city',
        'ipswich' => 'ipswich town',
        'coventry' => 'coventry city',
        'nottm forest' => 'nottingham forest',
    ],
];
