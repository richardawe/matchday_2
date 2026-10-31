<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a real file under public/ when the request reached PHP anyway (a bare
 * Mozilla/5.0 or Mozilla/4.0 token is rewritten onto the front controller by the
 * host), and turns a 406 from that same token back into 200. Link-preview
 * fetchers use it; it is not an abuse signal.
 */
class ServePublicFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ($file = $this->publicFile($request))) {
            return response()->file($file, ['Cache-Control' => 'public, max-age=86400']);
        }

        $response = $next($request);
        if ($response->getStatusCode() === 406 && self::bareMozilla($request)) {
            $response->setStatusCode(200);
        }

        return $response;
    }

    /** Exactly "Mozilla/5.0" or "Mozilla/4.0", optional trailing space. */
    public static function bareMozilla(Request $request): bool
    {
        return (bool) preg_match('/^Mozilla\/[45]\.0\s*$/', (string) $request->userAgent());
    }

    private function publicFile(Request $request): ?string
    {
        $path = $request->path();
        if ($path === '/' || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }
        $root = realpath(public_path());
        $file = realpath(public_path($path));
        if (!$root || !$file || !is_file($file) || !str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }
        $base = basename($file);
        if ($base === '' || str_starts_with($base, '.') || preg_match('/\.php$/i', $base)) {
            return null;
        }

        return $file;
    }
}
