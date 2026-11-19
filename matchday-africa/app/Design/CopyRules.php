<?php

namespace App\Design;

/**
 * The copy rules from the design language: banned phrases ("quietly", "it's not X, it's Y",
 * "most people"), emoji arrows, sirens and all-caps sentences. On-screen text that breaks them
 * fails the render; captions are cleaned of arrows and sirens and checked in tests.
 */
final class CopyRules
{
    /** Rule ids the text breaks: ['quietly', 'all-caps', ...]. */
    public static function violations(string $text): array
    {
        $found = [];
        // Licence codes are acronyms, not shouting.
        $text = str_ireplace((array) Tokens::get('copy.allowed_acronyms', []), 'licence', $text);
        foreach ((array) Tokens::get('copy.banned') as $rule) {
            if (@preg_match('/'.$rule['pattern'].'/iu', $text) === 1 && !self::caseSensitive($rule['id'], $rule['pattern'], $text)) {
                $found[] = (string) $rule['id'];
            }
        }

        return array_values(array_unique($found));
    }

    /** All-caps is about letter case, so it is matched without /i. */
    private static function caseSensitive(string $id, string $pattern, string $text): bool
    {
        return $id === 'all-caps' && preg_match('/'.$pattern.'/u', $text) !== 1;
    }

    public static function passes(string $text): bool
    {
        return self::violations($text) === [];
    }

    /** Throws DesignViolation naming the broken rules. */
    public static function assert(string $text, string $where = 'text'): void
    {
        if ($bad = self::violations($text)) {
            throw new DesignViolation(sprintf('Banned copy (%s) in the %s: "%s"', implode(', ', $bad), $where, mb_substr($text, 0, 80)));
        }
    }

    /** Removes emoji arrows and sirens (the rules that can be fixed without rewriting). */
    public static function clean(string $text): string
    {
        foreach ((array) Tokens::get('copy.banned') as $rule) {
            if (in_array($rule['id'], ['arrows', 'siren'], true)) {
                $text = (string) preg_replace('/\s*'.$rule['pattern'].'\s*/u', ' ', $text);
            }
        }

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }
}
