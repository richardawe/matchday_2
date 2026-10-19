<?php

// The reels-first homepage (resources/views/home-v2.blade.php). Off until approved on the live
// site; anyone can preview it with ?home=v2. With it on, the 3D arena lives at /war/arena.
return [
    'v2' => (bool) env('HOME_V2', false),
];
