<?php

namespace App\Support;

use App\Models\Team;

/** Club names short enough for cards and one-line calls: "Man City", "Spurs", "Wolves". */
class ClubName
{
    private const SHORT = [
        'manchester city' => 'Man City',
        'manchester united' => 'Man United',
        'tottenham hotspur' => 'Spurs',
        'wolverhampton wanderers' => 'Wolves',
        'brighton & hove albion' => 'Brighton',
        'nottingham forest' => 'Nott\'m Forest',
        'newcastle united' => 'Newcastle',
        'west ham united' => 'West Ham',
        'leeds united' => 'Leeds',
        'afc bournemouth' => 'Bournemouth',
        'crystal palace' => 'Palace',
        'aston villa' => 'Aston Villa',
        'ipswich town' => 'Ipswich',
        'coventry city' => 'Coventry',
        'hull city' => 'Hull',
    ];

    public static function full(Team|string|null $team): string
    {
        $name = $team instanceof Team ? $team->name : (string) $team;

        return trim(preg_replace('/\s+(FC|AFC)$/i', '', $name)) ?: 'TBC';
    }

    public static function short(Team|string|null $team): string
    {
        $full = self::full($team);
        $key = Armies::key($full);

        return self::SHORT[$key] ?? self::SHORT[config('armies.aliases')[$key] ?? ''] ?? $full;
    }

    /** The name a commentator says aloud: the short name without written abbreviations ("Forest", not "Nott'm Forest"). */
    public static function spoken(Team|string|null $team): string
    {
        return str_replace("Nott'm Forest", 'Forest', self::short($team));
    }
}
