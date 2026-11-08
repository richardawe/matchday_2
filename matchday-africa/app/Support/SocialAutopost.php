<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The one switch for the site posting to social media itself (config social.autopost_enabled,
 * SOCIAL_AUTOPOST_ENABLED). Off: nothing is sent to Buffer, X, Instagram or TikTok; a separate
 * bot posts from the social queue (/api/social-queue). YouTube uploads and broadcasts don't ask.
 */
final class SocialAutopost
{
    public const OFF_MESSAGE = 'Social autopost is off; nothing sent.';

    public static function enabled(): bool
    {
        return (bool) config('social.autopost_enabled');
    }

    /** True when sending may go ahead; otherwise logs one info line saying what was held back. */
    public static function allows(string $what): bool
    {
        if (self::enabled()) {
            return true;
        }
        Log::info("Social autopost is off (SOCIAL_AUTOPOST_ENABLED=false): {$what} not sent.");

        return false;
    }
}
