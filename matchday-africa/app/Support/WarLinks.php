<?php

namespace App\Support;

/**
 * Links to the Matchday War landing page. public/war is also a real folder (the statue art), so
 * Apache answers /war with a 301 to /war/ before Laravel sees it; route('war.index') always
 * prints /war, so every internal and shared link goes through here to land on /war/ directly.
 */
class WarLinks
{
    public static function home(array $query = []): string
    {
        $url = rtrim(route('war.index'), '/').'/';

        return $query ? $url.'?'.http_build_query($query) : $url;
    }
}
