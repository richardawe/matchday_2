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
        if ($request->isMethod('GET') && !$request->expectsJson()) {
            if ($redirect = Attribution::redirectToCanonicalHost($request)) {
                return $redirect;
            }
            if (!$request->is('admin', 'admin/*', 'api/*')) {
                $this->remember($request);
            }
        }

        return $next($request);
    }

    /** Writes the first touch into the 90-day cookie and the session, and keeps the session warm. */
    private function remember(Request $request): void
    {
        $touch = Attribution::capture($request);
        if ($touch) {
            Cookie::queue(Attribution::COOKIE, json_encode($touch), Attribution::MINUTES, null, null, null, true, false, 'lax');
        } elseif ($request->hasSession()) {
            $touch = Attribution::read($request);
        }
        if ($touch && $request->hasSession() && $request->session()->get(Attribution::SESSION) !== $touch) {
            $request->session()->put(Attribution::SESSION, $touch);
        }
    }
}
