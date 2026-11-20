<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What picture a URL shows, so a page can tell when two items would show the same one: the file's
 * sha1 when it is one of our files on disk (two names, one picture), else the address without its
 * query string (?v= versions of the same card are one picture).
 */
class ImageIdentity
{
    private static array $hashes = [];

    public static function key(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url(Str::startsWith($url, '//') ? 'https:'.$url : $url) ?: [];
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (($host === '' || self::ours($host)) && ($file = self::file($path))) {
            $stamp = $file.'|'.@filemtime($file).'|'.@filesize($file);

            return self::$hashes[$stamp] ??= 'sha1:'.sha1_file($file);
        }

        return 'url:'.($host === '' || self::ours($host) ? '' : $host).$path;
    }

    private static function ours(string $host): bool
    {
        $hosts = array_filter([Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST)), 'matchday.africa', 'www.matchday.africa',
            app()->runningInConsole() ? null : Str::lower(request()->getHost())]);

        return in_array($host, $hosts, true);
    }

    private static function file(string $path): ?string
    {
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        if (Str::startsWith($path, '/media/blog/')) {
            $file = Storage::disk('public')->path('blog-images/'.basename($path));
        } elseif (Str::startsWith($path, '/storage/')) {
            $file = Storage::disk('public')->path(Str::after($path, '/storage/'));
        } else {
            $file = public_path(ltrim($path, '/'));
        }

        return is_file($file) ? $file : null;
    }
}
