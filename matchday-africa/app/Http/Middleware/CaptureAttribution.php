<?php

namespace App\Http\Middleware;

use App\Support\Attribution;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Remembers where a visitor first came from (see App\Support\Attribution). */
class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && !$request->expectsJson() && !$request->is('admin', 'admin/*', 'api/*')) {
            if ($touch = Attribution::capture($request)) {
                Cookie::queue(Attribution::COOKIE, json_encode($touch), Attribution::MINUTES, null, null, null, true, false, 'lax');
            }
        }

        return $next($request);
    }
}
