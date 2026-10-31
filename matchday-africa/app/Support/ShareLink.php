<?php

namespace App\Support;

/**
 * Links people share: the page's canonical URL tagged with utm_source (whatsapp, x, copy),
 * utm_medium=share and utm_campaign (match, reel, prediction, article, league_invite), so the
 * signups page can credit the share that brought someone in.
 */
class ShareLink
{
    public const NETWORKS = ['whatsapp', 'x', 'copy'];

    public static function tagged(string $url, string $network, string $campaign): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            [$url, $fragment] = [substr($url, 0, $hash), substr($url, $hash)];
        }
        $query = http_build_query(['utm_source' => $network, 'utm_medium' => 'share', 'utm_campaign' => $campaign]);

        return $url.(str_contains($url, '?') ? '&' : '?').$query.$fragment;
    }

    /** @return array<string, string> network => href */
    public static function links(string $url, string $text, string $campaign): array
    {
        $whatsapp = self::tagged($url, 'whatsapp', $campaign);
        $x = self::tagged($url, 'x', $campaign);

        return [
            'whatsapp' => 'https://wa.me/?text='.rawurlencode(trim($text).' '.$whatsapp),
            'x' => 'https://x.com/intent/post?'.http_build_query(['text' => trim($text), 'url' => $x, 'via' => ltrim(config('social.accounts.x.handle', '@matchdayafrica'), '@')]),
            'copy' => self::tagged($url, 'copy', $campaign),
        ];
    }
}
