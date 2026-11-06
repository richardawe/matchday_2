<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Matches one player's name across providers: football-data.org says "Mohamed Salah",
 * API-Football's squads say "M. Salah", ESPN may say "Mo Salah", accents come and go.
 */
class PlayerNames
{
    public static function normalize(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))));
    }

    public static function same(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $ta = explode(' ', $a);
        $tb = explode(' ', $b);
        // One name is the other with extra names: "Salah" / "Mohamed Salah", "Vinicius Junior" / "Vinicius Jose Junior".
        if (count($ta) === 1 || count($tb) === 1) {
            return in_array(count($ta) === 1 ? $a : $b, count($ta) === 1 ? $tb : $ta, true) && strlen(count($ta) === 1 ? $a : $b) > 3;
        }
        // Same surname and the same first initial: "M Salah" / "Mohamed Salah".
        if (end($ta) === end($tb) && $ta[0][0] === $tb[0][0]) {
            return true;
        }

        return str_contains(" {$a} ", " {$b} ") || str_contains(" {$b} ", " {$a} ");
    }
}
