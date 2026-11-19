<?php

namespace App\Services\Story;

use RuntimeException;

/** A match without enough distinct verified beats for 61 s: the render fails rather than repeat a card. */
class StoryTooShort extends RuntimeException {}
