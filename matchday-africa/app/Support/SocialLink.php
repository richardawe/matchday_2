<?php

namespace App\Support;

use App\Services\SocialQueue;

/**
 * Links in outbound social posts and YouTube descriptions: an https page on the public site
 * tagged utm_source={channel}&utm_medium=social&utm_campaign={campaign}[&utm_content={content}],
 * so a post is never a bare page link.
 */
class SocialLink
{
    public const CHANNELS = ['x', 'tiktok', 'instagram', 'youtube'];

    public static function to(string $url, string $channel, string $campaign, ?string $content = null): string
    {
        $url = (string) SocialQueue::https($url);
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            [$url, $fragment] = [substr($url, 0, $hash), substr($url, $hash)];
        }
        $query = http_build_query(array_filter(['utm_source' => $channel, 'utm_medium' => 'social', 'utm_campaign' => $campaign,
            'utm_content' => $content], fn ($v) => $v !== null && $v !== ''));

        return $url.(str_contains($url, '?') ? '&' : '?').$query.$fragment;
    }
}
