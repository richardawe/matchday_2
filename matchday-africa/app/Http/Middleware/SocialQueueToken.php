<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The posting bot's bearer token for /api/social-queue (config social.queue_token, SOCIAL_QUEUE_TOKEN).
 * No token configured means the API is closed. The token is never logged or echoed.
 */
class SocialQueueToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('social.queue_token');
        $given = (string) $request->bearerToken();
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
