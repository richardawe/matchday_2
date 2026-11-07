<?php

namespace App\Services\YouTube;

use RuntimeException;

/** YouTube refused an upload for quota: the reel waits for the next quota day. */
class QuotaExhausted extends RuntimeException {}
