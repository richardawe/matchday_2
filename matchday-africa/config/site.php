<?php

/*
|--------------------------------------------------------------------------
| Site look
|--------------------------------------------------------------------------
|
| style: classic (the dark Griot look) or cartoon (bright comic panels, the same ink and colours
| as the v2 reels). Anyone can preview the other look in their own browser with ?style=cartoon
| (or ?style=classic); the choice is kept in a cookie for 30 days. SITE_STYLE sets it for everyone.
|
*/

return [
    'style' => env('SITE_STYLE', 'classic'),
];
