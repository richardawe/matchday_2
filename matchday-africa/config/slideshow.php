<?php

/*
|--------------------------------------------------------------------------
| Colour slideshow videos (slideshow:generate, slideshow:render)
|--------------------------------------------------------------------------
|
| 1080×1920 decks of five slides (3.6 s each, 0.4 s crossfades, about 16 s) drawn with GD and put
| together with ffmpeg, with a licensed music bed at −14 LUFS. Built every morning (previews,
| Team of the Week on Mondays) and after full time, only from facts in the database, featuring
| African players and players of confirmed African descent. Off until SLIDESHOW_ENABLED=true.
|
*/

return [
    'enabled' => (bool) env('SLIDESHOW_ENABLED', false),

    // Falls back to the ffmpeg the reel renderer ships (reel/node_modules/@remotion/compositor-*) when this
    // one doesn't run. php artisan slideshow:render --check (or /admin/slideshows) lists anything missing.
    'ffmpeg' => env('SLIDESHOW_FFMPEG') ?: env('RADIO_FFMPEG', 'ffmpeg'),
    'timeout' => (int) env('SLIDESHOW_TIMEOUT', 300),
    // auto: xfade/zoompan filters when the build has them, else PHP-drawn frames joined by the concat
    // demuxer (works with the reel renderer's small ffmpeg). Or force filters / frames.
    'video_mode' => env('SLIDESHOW_VIDEO_MODE', 'auto'),

    'size' => [1080, 1920],
    'fps' => 30,
    'slide_seconds' => 3.6,
    'crossfade_seconds' => 0.4,
    // Ken Burns on slide 01: from 1.0 up to this, lowered further so text never leaves the safe zone.
    'zoom_max' => 1.04,
    // TikTok/Reels/Shorts safe zone: an 800 px column centred in the frame (140 px each side),
    // text between y 150 and 1540, the bottom 380 px clear of text.
    'safe' => ['left' => 140, 'right' => 940, 'top' => 150, 'bottom' => 1540],

    // Previews are made for matches kicking off within this many hours.
    'preview_hours' => (int) env('SLIDESHOW_PREVIEW_HOURS', 48),
    // Post-match decks wait this long after kick-off (the match must also be FINISHED) and stop after this many hours.
    'post_match_after_minutes' => 120,
    'post_match_within_hours' => 12,
    // Renders per slideshow:render run (one per minute keeps the CPU free for the 20-second reels).
    'renders_per_run' => (int) env('SLIDESHOW_RENDERS_PER_RUN', 1),
    'max_attempts' => 3,

    // At most this many approved slideshows go to YouTube a quota day; they share the channel's
    // daily upload quota with the reels (YOUTUBE_DAILY_UPLOADS), which upload first.
    'youtube_daily' => (int) env('SLIDESHOW_YOUTUBE_DAILY', 2),

    'hashtag' => '#MatchdayAfrica',

    /*
    | Music bed. The audio file stays private (storage/app/music, never public on its own); each
    | slideshow stores the credit it was made with, and every caption and YouTube description
    | carries it. This is the credit record for the starting track.
    */
    'music' => [
        'track' => env('SLIDESHOW_MUSIC_TRACK', 'hot-amapiano-beats'),
        'tracks' => [
            'hot-amapiano-beats' => [
                'title' => 'Hot Amapiano Beats',
                'artist' => 'Alex Morgan',
                'licence' => 'CC BY 4.0',
                'licence_url' => 'https://creativecommons.org/licenses/by/4.0/',
                'album' => 'Pop, Afro & Party Instrumentals',
                'source_url' => env('SLIDESHOW_MUSIC_SOURCE_URL') ?: 'https://freemusicarchive.org/music/alex-morgan/pop-afro-party-instrumentals/hot-amapiano-beats/',
                // slideshow:music fetches the file from here (Free Music Archive storage) and checks it against the hash,
                // so the audio never sits in the repository. 320 kbps, 48 kHz stereo, 3:58.
                'download_url' => 'https://files.freemusicarchive.org/storage-freemusicarchive-org/tracks/cUcs6uMOXcjxxrLW0btTwHzwF1kK9Ga4LxlZzfAd.mp3',
                'sha256' => 'e4b9a4b972f48a1060624bee6bf7e63e09fb718c7dcc76001b81dd28e3a4eff4',
                'path' => env('SLIDESHOW_MUSIC_PATH') ?: 'music/hot-amapiano-beats.mp3', // relative to storage/app
                'start_seconds' => (float) env('SLIDESHOW_MUSIC_START', 0),
                // The credit the licence notes require (music/LICENCES.md), used wherever a caption has room.
                'credit' => 'Music: "Hot Amapiano Beats" by Alex Morgan. Licensed under CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/). '
                    .'Source: https://freemusicarchive.org/music/alex-morgan/pop-afro-party-instrumentals/hot-amapiano-beats/. '
                    .'Trimmed, faded and loudness-normalised for this video.',
            ],
        ],
        'loudness' => ['i' => -14, 'tp' => -2, 'lra' => 11], // -2 dBTP leaves room for AAC overshoot on this hot master
        'fade_in' => 0.3,
        'fade_out' => 1.5,
    ],

    /*
    | Slide 01 photos from Wikimedia Commons (through the player's Wikidata image), only under a
    | free licence checked with the Commons API. The site's own player photos are too small for 1080×1920.
    */
    'commons' => [
        'enabled' => (bool) env('SLIDESHOW_COMMONS', true),
        'user_agent' => env('SLIDESHOW_COMMONS_USER_AGENT', 'MatchdayAfricaSlideshows/1.0 (https://matchday.africa)'),
        'min_width' => 700,
        'licences' => ['CC0', 'Public domain', 'PD', 'CC BY', 'CC BY-SA'],
        'retry_days' => 14,
    ],

    // Kit colours for clubs outside config/armies.php (club names only on slides; never crests).
    'club_colours' => [
        'real madrid' => ['#FFFFFF', '#00529F'], 'barcelona' => ['#A50044', '#004D98'], 'atletico madrid' => ['#CB3524', '#272E61'],
        'atlético madrid' => ['#CB3524', '#272E61'], 'sevilla' => ['#FFFFFF', '#D4001F'], 'real betis' => ['#00954C', '#FFFFFF'],
        'villarreal' => ['#FFE667', '#005187'], 'real sociedad' => ['#0067B1', '#FFFFFF'], 'athletic club' => ['#EE2523', '#FFFFFF'],
        'bayern munich' => ['#DC052D', '#FFFFFF'], 'bayern münchen' => ['#DC052D', '#FFFFFF'], 'borussia dortmund' => ['#FDE100', '#000000'],
        'bayer leverkusen' => ['#E32221', '#000000'], 'rb leipzig' => ['#DD0741', '#FFFFFF'], 'eintracht frankfurt' => ['#E1000F', '#000000'],
        'vfb stuttgart' => ['#FFFFFF', '#E32219'], 'borussia mönchengladbach' => ['#000000', '#FFFFFF'], 'vfl wolfsburg' => ['#65B32E', '#FFFFFF'],
        'paris saint-germain' => ['#004170', '#DA291C'], 'marseille' => ['#2FAEE0', '#FFFFFF'], 'olympique de marseille' => ['#2FAEE0', '#FFFFFF'],
        'lyon' => ['#FFFFFF', '#DA0812'], 'olympique lyonnais' => ['#FFFFFF', '#DA0812'], 'monaco' => ['#E51B22', '#FFFFFF'],
        'lille' => ['#E01E13', '#20325F'], 'lens' => ['#FFDD00', '#E51F25'], 'rennes' => ['#E13327', '#000000'], 'nice' => ['#E30613', '#000000'],
        'juventus' => ['#000000', '#FFFFFF'], 'inter' => ['#0068A8', '#000000'], 'internazionale' => ['#0068A8', '#000000'],
        'ac milan' => ['#FB090B', '#000000'], 'milan' => ['#FB090B', '#000000'], 'napoli' => ['#12A0D7', '#FFFFFF'],
        'roma' => ['#8E1F2F', '#F0BC42'], 'as roma' => ['#8E1F2F', '#F0BC42'], 'lazio' => ['#87D8F7', '#FFFFFF'],
        'atalanta' => ['#1E71B8', '#000000'], 'fiorentina' => ['#482E92', '#FFFFFF'],
        'ajax' => ['#D2122E', '#FFFFFF'], 'psv' => ['#ED1C24', '#FFFFFF'], 'feyenoord' => ['#E50914', '#FFFFFF'],
        'benfica' => ['#E83030', '#FFFFFF'], 'porto' => ['#00428C', '#FFFFFF'], 'sporting cp' => ['#008057', '#FFFFFF'],
        'galatasaray' => ['#A90432', '#FDB912'], 'fenerbahçe' => ['#002D72', '#FEE11A'], 'fenerbahce' => ['#002D72', '#FEE11A'],
        'celtic' => ['#018749', '#FFFFFF'], 'rangers' => ['#0057B8', '#FFFFFF'],
        'west ham united' => ['#7A263A', '#1BB1E7'], 'leicester city' => ['#003090', '#FDBE11'], 'southampton' => ['#D71920', '#FFFFFF'],
    ],

    /*
    | African nations by FIFA code: name, demonym ("of Nigerian descent"), flag colours, and the
    | names national teams appear under. Flags are pre-rendered from the MIT flag-icons set
    | (resources/flags/{iso}.png; licence alongside).
    */
    'nations' => [
        'NGA' => ['Nigeria', 'Nigerian', ['#008751', '#FFFFFF']],
        'GHA' => ['Ghana', 'Ghanaian', ['#CE1126', '#FCD116']],
        'SEN' => ['Senegal', 'Senegalese', ['#00853F', '#FDEF42']],
        'CIV' => ["Côte d'Ivoire", 'Ivorian', ['#F77F00', '#009E60'], ['ivory coast', 'cote divoire']],
        'MAR' => ['Morocco', 'Moroccan', ['#C1272D', '#006233']],
        'EGY' => ['Egypt', 'Egyptian', ['#CE1126', '#000000']],
        'TUN' => ['Tunisia', 'Tunisian', ['#E70013', '#FFFFFF']],
        'ALG' => ['Algeria', 'Algerian', ['#006633', '#FFFFFF']],
        'CMR' => ['Cameroon', 'Cameroonian', ['#007A5E', '#CE1126']],
        'MLI' => ['Mali', 'Malian', ['#14B53A', '#FCD116']],
        'GUI' => ['Guinea', 'Guinean', ['#CE1126', '#009460']],
        'GAB' => ['Gabon', 'Gabonese', ['#009E60', '#3A75C4']],
        'RSA' => ['South Africa', 'South African', ['#007749', '#FFB81C']],
        'ZAM' => ['Zambia', 'Zambian', ['#198A00', '#EF7D00']],
        'ZIM' => ['Zimbabwe', 'Zimbabwean', ['#006400', '#FFD200']],
        'COD' => ['DR Congo', 'Congolese', ['#007FFF', '#CE1021'], ['congo dr', 'democratic republic of the congo', 'dr congo']],
        'CGO' => ['Congo', 'Congolese', ['#009543', '#DC241F'], ['republic of the congo', 'congo brazzaville']],
        'BFA' => ['Burkina Faso', 'Burkinabè', ['#EF2B2D', '#009E49']],
        'BEN' => ['Benin', 'Beninese', ['#008751', '#FCD116']],
        'TOG' => ['Togo', 'Togolese', ['#006A4E', '#FFCE00']],
        'KEN' => ['Kenya', 'Kenyan', ['#BB0000', '#000000']],
        'UGA' => ['Uganda', 'Ugandan', ['#000000', '#FCDC04']],
        'TZA' => ['Tanzania', 'Tanzanian', ['#1EB53A', '#00A3DD']],
        'TAN' => ['Tanzania', 'Tanzanian', ['#1EB53A', '#00A3DD']],
        'ANG' => ['Angola', 'Angolan', ['#CC092F', '#000000']],
        'MOZ' => ['Mozambique', 'Mozambican', ['#009739', '#D21034']],
        'CPV' => ['Cape Verde', 'Cape Verdean', ['#003893', '#FFFFFF'], ['cabo verde']],
        'GMB' => ['Gambia', 'Gambian', ['#CE1126', '#0C1C8C'], ['the gambia']],
        'GAM' => ['Gambia', 'Gambian', ['#CE1126', '#0C1C8C'], ['the gambia']],
        'SLE' => ['Sierra Leone', 'Sierra Leonean', ['#1EB53A', '#0072C6']],
        'EQG' => ['Equatorial Guinea', 'Equatorial Guinean', ['#3E9A00', '#E32118']],
        'GNB' => ['Guinea-Bissau', 'Bissau-Guinean', ['#CE1126', '#009E49'], ['guinea bissau']],
        'LBR' => ['Liberia', 'Liberian', ['#BF0A30', '#002868']],
        'MWI' => ['Malawi', 'Malawian', ['#CE1126', '#000000']],
        'NAM' => ['Namibia', 'Namibian', ['#003580', '#009543']],
        'NER' => ['Niger', 'Nigerien', ['#E05206', '#0DB02B']],
        'NIG' => ['Niger', 'Nigerien', ['#E05206', '#0DB02B']],
        'RWA' => ['Rwanda', 'Rwandan', ['#00A1DE', '#FAD201']],
        'SDN' => ['Sudan', 'Sudanese', ['#D21034', '#000000']],
        'SSD' => ['South Sudan', 'South Sudanese', ['#078930', '#000000']],
        'BDI' => ['Burundi', 'Burundian', ['#CE1126', '#1EB53A']],
        'CAF' => ['Central African Republic', 'Central African', ['#003082', '#289728']],
        'CHA' => ['Chad', 'Chadian', ['#002664', '#C60C30']],
        'COM' => ['Comoros', 'Comorian', ['#3D8E33', '#FFC61E']],
        'DJI' => ['Djibouti', 'Djiboutian', ['#6AB2E7', '#12AD2B']],
        'ERI' => ['Eritrea', 'Eritrean', ['#EA0437', '#12AD2B']],
        'ETH' => ['Ethiopia', 'Ethiopian', ['#078930', '#FCDD09']],
        'LES' => ['Lesotho', 'Basotho', ['#00209F', '#009543']],
        'LBY' => ['Libya', 'Libyan', ['#E70013', '#239E46']],
        'MAD' => ['Madagascar', 'Malagasy', ['#FC3D32', '#007E3A']],
        'MRI' => ['Mauritius', 'Mauritian', ['#EA2839', '#1A206D']],
        'MTN' => ['Mauritania', 'Mauritanian', ['#00A95C', '#FFD700']],
        'SEY' => ['Seychelles', 'Seychellois', ['#003F87', '#FCD856']],
        'SOM' => ['Somalia', 'Somali', ['#4189DD', '#FFFFFF']],
        'SWZ' => ['Eswatini', 'Swazi', ['#3E5EB9', '#FFD900'], ['swaziland']],
        'BOT' => ['Botswana', 'Batswana', ['#6DA9D2', '#000000']],
        'STP' => ['São Tomé and Príncipe', 'Santomean', ['#12AD2B', '#FFCE00'], ['sao tome and principe', 'sao tome']],
    ],
];
