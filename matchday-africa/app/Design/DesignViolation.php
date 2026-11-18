<?php

namespace App\Design;

use App\Services\Slideshow\UnsafeLayout;

/**
 * A render broke the design language (banned copy, an unlicensed image, a repeated card, a colour
 * pair the contrast rules forbid). Renders fail rather than publish it.
 */
class DesignViolation extends UnsafeLayout {}
