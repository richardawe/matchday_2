<?php

namespace App\Http\Middleware;

use App\Support\SiteStyle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets anyone preview the other site look in their own browser: ?style=cartoon or ?style=classic
 * is remembered in a cookie for 30 days. Without a preview, config site.style decides.
 */
class SiteStylePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        $asked = $request->query('style');
        $chosen = is_string($asked) && in_array($asked, SiteStyle::STYLES, true) ? $asked : null;
        if ($chosen) {
            $request->attributes->set('site_style', $chosen);
        } elseif (in_array($cookie = $request->cookie(SiteStyle::COOKIE), SiteStyle::STYLES, true)) {
            $request->attributes->set('site_style', $cookie);
        }

        $response = $next($request);
        if ($chosen) {
            // On this response only (not the shared cookie queue): remembered for 30 days.
            $response->headers->setCookie(Cookie::make(SiteStyle::COOKIE, $chosen, 60 * 24 * 30));
        }

        return $response;
    }
}
