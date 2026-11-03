<?php

namespace App\Support;

/** Which look this request gets: classic or cartoon (config/site.php, preview cookie md_style). */
class SiteStyle
{
    public const STYLES = ['classic', 'cartoon'];

    public const COOKIE = 'md_style';

    public static function current(): string
    {
        $request = request();
        $chosen = $request->attributes->get('site_style');
        if (in_array($chosen, self::STYLES, true)) {
            return $chosen;
        }
        $style = config('site.style');

        return in_array($style, self::STYLES, true) ? $style : 'classic';
    }

    public static function cartoon(): bool
    {
        return self::current() === 'cartoon';
    }

    /** Body classes for the layouts. */
    public static function bodyClass(): string
    {
        return self::cartoon() ? 'md-cartoon' : '';
    }
}
