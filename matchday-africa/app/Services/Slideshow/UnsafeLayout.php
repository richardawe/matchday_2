<?php

namespace App\Services\Slideshow;

use RuntimeException;

/** Text drawn outside the TikTok/Reels/Shorts safe zone: the render fails rather than ship it. */
class UnsafeLayout extends RuntimeException {}
