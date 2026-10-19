<?php

namespace App\Support;

/**
 * X's 280 limit is weighted: every link counts 23, Latin text 1 per character, emoji and
 * CJK 2. This fits a post's body around a link and hashtags so X never refuses it for length.
 */
final class XText
{
    public const LIMIT = 280;

    private const URL = '~https?://\S+~u';

    public static function length(string $text): int
    {
        $links = preg_match_all(self::URL, $text);
        $rest = preg_replace(self::URL, '', $text);
        $length = $links * 23;
        foreach (mb_str_split($rest) as $char) {
            $cp = mb_ord($char);
            $length += ($cp <= 0x10FF || ($cp >= 0x2000 && $cp <= 0x200D) || ($cp >= 0x2010 && $cp <= 0x201F) || ($cp >= 0x2032 && $cp <= 0x2037)) ? 1 : 2;
        }

        return $length;
    }

    /** "{body}\n\n{link}\n{tags}", shortening the body with an ellipsis until it fits. */
    public static function compose(string $body, ?string $link = null, string $tags = ''): string
    {
        $tail = ($link ? "\n\n{$link}" : '').($tags !== '' ? ($link ? "\n" : "\n\n").$tags : '');
        $body = trim($body);
        while ($body !== '' && self::length($body.$tail) > self::LIMIT) {
            $over = self::length($body.$tail) - self::LIMIT;
            $keep = mb_strlen(rtrim($body, '…')) - max(1, $over);
            $body = $keep > 0 ? rtrim(mb_substr($body, 0, $keep)).'…' : '';
        }

        return trim($body.$tail);
    }
}
