<?php

namespace App\Services\Griot;

use App\Models\GriotChallengeMatch;
use App\Services\Griot;
use App\Support\Armies;

/**
 * The Griot's one line per call in its four voices, filled only with the forecaster's facts
 * (wins in the last five, the call itself). Native speakers should review the pcm/sw/za lines,
 * as with the other Griot captions.
 */
class ChallengeLines
{
    private const LINES = [
        'en' => [
            'home' => '{home} have won {home_wins} of their last {home_n}. I see {score} at home.',
            'away' => '{away} arrive with {away_wins} wins from {away_n}. I see them take it, {score}.',
            'draw' => 'Neither army gives way. I see {score}.',
            'home_plain' => 'The home drums are loud. I see {score}.',
            'away_plain' => 'The visitors carry the day. I see {score}.',
        ],
        'pcm' => [
            'home' => '{home} don win {home_wins} for their last {home_n}. Na {score} I see for house.',
            'away' => '{away} dey come with {away_wins} win for {away_n}. Dem go carry am, {score}.',
            'draw' => 'Nobody go gree for anybody. Na {score} I see.',
            'home_plain' => 'Home crowd go push dem. Na {score} I see.',
            'away_plain' => 'Visitors go scatter the party. Na {score} I see.',
        ],
        'sw' => [
            'home' => '{home} wameshinda {home_wins} kati ya {home_n} za mwisho. Naona {score} nyumbani.',
            'away' => '{away} wanakuja na ushindi {away_wins} kati ya {away_n}. Naona wakibeba, {score}.',
            'draw' => 'Hakuna atakayekubali. Naona {score}.',
            'home_plain' => 'Nyumbani ni nyumbani. Naona {score}.',
            'away_plain' => 'Wageni wataharibu sherehe. Naona {score}.',
        ],
        'za' => [
            'home' => '{home} won {home_wins} of their last {home_n}, eish. I\'m seeing {score} at home.',
            'away' => '{away} rock up with {away_wins} wins from {away_n}. Sharp sharp, {score} to them.',
            'draw' => 'Nobody\'s backing down, neh. I see {score}.',
            'home_plain' => 'Home ground, home vibes. I see {score}.',
            'away_plain' => 'The visitors are spoiling the party. I see {score}.',
        ],
    ];

    public function line(GriotChallengeMatch $pick, string $voice = 'en'): string
    {
        $voice = array_key_exists($voice, Griot::VOICES) ? $voice : 'en';
        $match = $pick->match;
        $facts = $pick->facts ?? [];
        $club = fn ($team) => \App\Support\ClubName::short($team);
        $homeN = strlen((string) ($facts['home_form'] ?? ''));
        $awayN = strlen((string) ($facts['away_form'] ?? ''));
        $kind = $pick->home_pick > $pick->away_pick ? 'home' : ($pick->home_pick < $pick->away_pick ? 'away' : 'draw');
        // Quote the form only when there are five results and they back the call.
        if ($kind === 'home' && ($homeN < 5 || ($facts['home_wins'] ?? 0) < 2)) {
            $kind = 'home_plain';
        } elseif ($kind === 'away' && ($awayN < 5 || ($facts['away_wins'] ?? 0) < 2)) {
            $kind = 'away_plain';
        }

        return strtr(self::LINES[$voice][$kind], [
            '{home}' => $club($match?->homeTeam),
            '{away}' => $club($match?->awayTeam),
            '{home_wins}' => (string) ($facts['home_wins'] ?? 0),
            '{away_wins}' => (string) ($facts['away_wins'] ?? 0),
            '{home_n}' => (string) $homeN,
            '{away_n}' => (string) $awayN,
            '{score}' => $pick->call(),
        ]);
    }

    /** The army name for a side, or the club name for clubs without one. */
    public static function army($team): string
    {
        return Armies::for($team)['army'] ?? trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $team?->name));
    }
}
