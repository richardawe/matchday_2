<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns a video's raw YouTube/social description into what a page may show: a clean summary (the
 * first sentence or two, at most SUMMARY_MAX characters, without URLs, hashtags, @handles, credit
 * lines or the AI disclosure), and the credit lines and AI disclosure kept apart for the small print
 * at the bottom of the video page.
 */
class VideoText
{
    public const SUMMARY_MAX = 160;

    /** Lines that credit a picture, music or footage: kept for the small print, never in the summary. */
    private const CREDIT = '/^\s*(photos?|images?|pictures?|picture credit|photo credit|credits?|music|footage|source|sources)\s*:/iu';

    /**
     * @return array{summary: string, credits: list<string>, disclosure: ?string}
     */
    public static function parts(?string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $credits = [];
        $disclosure = null;
        $kept = [];
        foreach (preg_split('/\n\s*\n/u', trim($text)) ?: [] as $paragraph) {
            if (self::isDisclosure($paragraph)) {
                $disclosure ??= self::oneLine($paragraph);

                continue;
            }
            foreach (explode("\n", $paragraph) as $line) {
                if (preg_match(self::CREDIT, $line)) {
                    $credits[] = self::oneLine(self::stripLinks($line, keepWords: true));

                    continue;
                }
                if (self::isDisclosure($line)) {
                    $disclosure ??= self::oneLine($line);

                    continue;
                }
                $clean = self::stripLinks($line);
                // "Listen and read the sources: <url>" leaves only a lead-in: drop it.
                if ($clean === '' || ($clean !== trim($line) && (str_ends_with($clean, ':') || str_word_count($clean) < 4))) {
                    continue;
                }
                $kept[] = $clean;
            }
        }

        return ['summary' => self::summary(implode(' ', $kept)), 'credits' => array_values(array_unique(array_filter($credits))), 'disclosure' => $disclosure];
    }

    /** The first sentence, plus the second when both fit in $max characters; a long first sentence is cut at a word. */
    public static function summary(string $text, int $max = self::SUMMARY_MAX): string
    {
        $text = self::oneLine(\App\Services\SocialQueue::plain($text));
        if ($text === '') {
            return '';
        }
        $sentences = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\d"“‘(])/u', $text) ?: [$text];
        $out = '';
        foreach (array_slice($sentences, 0, 2) as $i => $sentence) {
            $try = $out === '' ? $sentence : $out.' '.$sentence;
            if (mb_strlen($try) > $max) {
                if ($i === 0) {
                    return rtrim(Str::limit($sentence, $max - 1, ''), " ,;:-–—").'…';
                }
                break;
            }
            $out = $try;
        }

        return $out;
    }

    /** An AI/synthetic-voice disclosure, ours (config radio.disclosure) or one written the usual way. */
    public static function isDisclosure(string $text): bool
    {
        $text = self::oneLine($text);
        if ($text === '') {
            return false;
        }
        $ours = (string) config('radio.disclosure');
        if ($ours !== '' && str_contains(Str::lower($text), Str::lower(Str::limit($ours, 40, '')))) {
            return true;
        }

        return (bool) preg_match('/\b(AI|artificial intelligence|Kokoro|synthes[iz]ed voice|text-to-speech)\b/iu', $text)
            && (bool) preg_match('/\b(written|made|generated|created|produced|voiced|narrated|uses?|using|with the help of|assisted|disclosure)\b/iu', $text);
    }

    /** Without URLs, bare domains with a path, hashtags and @handles. */
    private static function stripLinks(string $line, bool $keepWords = false): string
    {
        $line = preg_replace('~\b(?:https?://|www\.)\S+~iu', '', $line) ?? $line;
        $line = preg_replace('~\b[a-z0-9-]+(?:\.[a-z0-9-]+)+/\S*~iu', '', $line) ?? $line;
        if (!$keepWords) {
            $line = preg_replace('/(?<![\p{L}\d])[#@][\p{L}\d_]+/u', '', $line) ?? $line;
        }
        $line = preg_replace('/\(\s*\)|\[\s*\]/u', '', $line) ?? $line;

        return trim(self::oneLine($line), " \t-–—|·•");
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
