<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * First-touch attribution. The first visit records its UTM tags, referring site and landing page
 * in a cookie (CaptureAttribution); a later visit only overwrites it when the first one had no
 * UTM tags. The account, email signup or guest pick made later reads it back.
 */
class Attribution
{
    public const COOKIE = 'md_attr';
    public const SESSION = 'md_attr';
    public const MINUTES = 60 * 24 * 90;
    private const FIELDS = ['utm_source' => 100, 'utm_medium' => 100, 'utm_campaign' => 150];

    /**
     * www.matchday.africa and matchday.africa do not share a host-only cookie, and the
     * homepage links to the apex host. Send the first hit (UTM tags included) to the
     * canonical host before anything is remembered.
     */
    public static function redirectToCanonicalHost(Request $request): ?RedirectResponse
    {
        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (!$canonical || strcasecmp($request->getHost(), 'www.'.$canonical) !== 0) {
            return null;
        }
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: $request->getScheme();

        return redirect()->away($scheme.'://'.$canonical.$request->getRequestUri(), 301);
    }

    /** What this request should store, or null when the touch already on file should stand. */
    public static function capture(Request $request): ?array
    {
        $current = self::read($request);
        $tags = self::tags($request);
        if ($current && (!empty($current['utm_source']) || !$tags)) {
            return null;
        }

        $touch = $tags;
        if ($referrer = self::referrer($request)) {
            $touch['signup_referrer'] = $referrer;
        }
        $touch['signup_landing'] = Str::limit('/'.ltrim($request->path(), '/'), 250, '');

        return $touch;
    }

    /**
     * The stored touch, cleaned. The cookie is the 90-day copy; the session is the copy
     * that survives the Google redirect on the same host when the cookie was set on the other.
     * A touch that names a source wins over one that does not.
     */
    public static function read(?Request $request = null): array
    {
        $request ??= request();
        $cookie = self::clean(json_decode((string) $request?->cookie(self::COOKIE), true));
        $session = self::clean($request?->hasSession() ? $request->session()->get(self::SESSION) : null);
        if (!empty($cookie['utm_source'])) {
            return $cookie;
        }
        if (!empty($session['utm_source'])) {
            return $session;
        }

        return $cookie ?: $session;
    }

    /** Attribution columns for a new user, if the migration has run. */
    public static function forUser(?Request $request = null): array
    {
        static $ready = null;
        $ready ??= Schema::hasColumn('users', 'utm_source');

        return $ready ? self::read($request) : [];
    }

    /** A readable source label: the UTM source, else the referring host, else "direct". */
    public static function label(?string $source, ?string $referrer = null): string
    {
        if ($source) {
            return Str::lower($source);
        }
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        return $host ? Str::lower(preg_replace('/^www\./', '', $host)) : 'direct';
    }

    private static function clean(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $clean = [];
        foreach (self::FIELDS + ['signup_referrer' => 255, 'signup_landing' => 255] as $key => $max) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $clean[$key] = Str::limit($data[$key], $max, '');
            }
        }

        return $clean;
    }

    private static function tags(Request $request): array
    {
        $tags = [];
        foreach (self::FIELDS as $key => $max) {
            $value = trim((string) $request->query($key));
            if ($value !== '') {
                $tags[$key] = Str::limit(strip_tags($value), $max, '');
            }
        }

        return isset($tags['utm_source']) ? $tags : [];
    }

    private static function referrer(Request $request): ?string
    {
        $referrer = (string) $request->headers->get('referer');
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!$host || strcasecmp($host, $request->getHost()) === 0) {
            return null;
        }

        return Str::limit($referrer, 250, '');
    }
}
