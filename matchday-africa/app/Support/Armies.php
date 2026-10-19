<?php

namespace App\Support;

use App\Models\Team;

/**
 * Looks up a club's crest-free warrior army (config/armies.php) by team name, whichever
 * provider the name came from ("Arsenal FC", "AFC Bournemouth", "Wolves").
 */
class Armies
{
    public static function for(Team|string|null $team): ?array
    {
        $name = $team instanceof Team ? $team->name : $team;
        $key = self::key((string) $name);
        $club = config("armies.clubs")[$key] ?? null;
        if (!$club) {
            return null;
        }
        $art = $club['art'];

        return $club + [
            'key' => $key,
            'glow' => self::glow($club['colours']),
            // Cut-out (transparent backdrop) built by scripts/build-armies.mjs, for server-drawn cards.
            'cutout_path' => $art ? resource_path("armies/{$art}.webp") : null,
            'cutout' => $art ? asset(config('armies.optimised')."/{$art}-cutout-960.webp") : null,
            'image' => $art ? asset(config('armies.optimised')."/{$art}-960.webp") : null,
            'srcset' => $art ? collect(config('armies.widths'))->map(fn ($w) => asset(config('armies.optimised')."/{$art}-{$w}.webp")." {$w}w")->join(', ') : null,
        ];
    }

    /** Light colour for glows: the kit's first colour, or its second when the first is near-black. */
    public static function glow(array $colours): string
    {
        [$r, $g, $b] = sscanf($colours[0], '#%02x%02x%02x');

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) < 40 && isset($colours[1]) ? $colours[1] : $colours[0];
    }

    public static function key(string $name): string
    {
        $key = trim(preg_replace('/\s+/', ' ', preg_replace('/\bfc\b/', '', mb_strtolower($name))));

        return config('armies.aliases')[$key] ?? $key;
    }
}
