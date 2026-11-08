<?php

namespace App\Services;

use App\Models\MatchMoment;
use App\Support\ClubName;

/**
 * The Griot: captions each story chapter in the fan's chosen voice. Templates only, so every
 * name, minute and score comes from the match data. Pidgin, Swahili/Sheng and Mzansi lines
 * should be checked by native speakers before launch.
 */
class Griot
{
    public const VOICES = ['en' => 'English', 'pcm' => 'Naija Pidgin', 'sw' => 'Sheng / Swahili', 'za' => 'Mzansi'];

    /**
     * Each line names the player and the club and says what happened, so a fan who sees only one
     * caption knows the event, the team and the score. {at} is when it happened ("in the 41st
     * minute"), empty when the minute is unknown.
     */
    private const LINES = [
        'en' => [
            'kickoff' => ['Kick-off: {home} v {away}', 'Hear me: {home} and {away} are under way. Let the drums speak.'],
            'opener' => ['GOAL! {player} scores for {club}', 'First goal of the match{at}. {score}.'],
            'equaliser' => ['GOAL! {player} equalises for {club}', 'All square{at}. {score}.'],
            'lead' => ['GOAL! {player} puts {club} in front', '{club} ahead{at}. {score}.'],
            'extend' => ['GOAL! {player} makes it {score_short}', '{club} pull further ahead{at}. {score}.'],
            'late_winner' => ['LATE GOAL! {player} for {club}', 'Drama{at}! {score}.'],
            'consolation' => ['GOAL! {player} pulls one back', 'One back for {club}{at}. {score}.'],
            'red_card' => ['RED CARD! {player} is sent off', '{club} are down to ten{at}. Still {score_short}.'],
            'half_time' => ['Half-time: {home} {score_short} {away}', '{score} at the break.'],
            'win' => ['Full time: {club} win', '{score}.'],
            'draw' => ['Full time: honours even', '{score}. The points are shared.'],
        ],
        'pcm' => [
            'kickoff' => ['Match don start: {home} v {away}', '{home} and {away} don enter field. Make the drum talk!'],
            'opener' => ['Goal! {player} don score for {club}', '{player} don open the account{at}. {score}.'],
            'equaliser' => ['Goal! {club} don equalise!', '{player} don bring am back{at}. {score}.'],
            'lead' => ['Goal! {club} don take lead!', '{player} don put {club} for front{at}. {score}.'],
            'extend' => ['Goal! {player} add another one', '{club} dey run am{at}. {score}.'],
            'late_winner' => ['Last-minute goal! {player} for {club}', '{player} score am{at}. Who dey breathe? {score}.'],
            'consolation' => ['Goal! {club} don reduce am', '{player} score one{at}. {score}.'],
            'red_card' => ['Red card! {player} don comot', '{club} remain ten{at}. Still {score_short}.'],
            'half_time' => ['Half time: {home} {score_short} {away}', '{score} for now.'],
            'win' => ['Full time: {club} don win am!', 'Final: {score}.'],
            'draw' => ['Full time: nobody win today', 'Final: {score}. Dem share the points.'],
        ],
        'sw' => [
            'kickoff' => ['Mechi imeanza: {home} dhidi ya {away}', '{home} na {away} wameanza. Ngoma zinaanza.'],
            'opener' => ['Goli! {player} afungia {club}', '{player} afungua ukurasa{at}. {score}.'],
            'equaliser' => ['Goli! {club} wasawazisha', '{player} arudisha usawa{at}. {score}.'],
            'lead' => ['Goli! {club} wako mbele', '{player} awaweka {club} mbele{at}. {score}.'],
            'extend' => ['Goli! {player} aongeza', '{club} waongeza{at}. {score}.'],
            'late_winner' => ['Goli la dakika za mwisho! {player}', '{player} afungia {club}{at}. {score}.'],
            'consolation' => ['Goli! {club} wapata moja', '{player} afunga{at}. {score}.'],
            'red_card' => ['Kadi nyekundu! {player} atolewa', '{club} wabaki kumi{at}. Bado {score_short}.'],
            'half_time' => ['Mapumziko: {home} {score_short} {away}', 'Kipindi cha kwanza: {score}.'],
            'win' => ['Mwisho: {club} washinda!', 'Mwisho wa mchezo: {score}.'],
            'draw' => ['Mwisho: sare!', 'Mwisho wa mchezo: {score}.'],
        ],
        'za' => [
            'kickoff' => ['Ke nako! {home} v {away}', '{home} and {away} are under way. Let’s go.'],
            'opener' => ['GOAL! {player} scores for {club}', 'Eish, what a strike{at}! {score}.'],
            'equaliser' => ['GOAL! {club} level it, haibo!', '{player} brings it back{at}. {score}.'],
            'lead' => ['GOAL! {club} are in front!', '{player} with the shaya{at}. {score}.'],
            'extend' => ['GOAL! {player} makes it {score_short}', 'Sharp sharp, {club} again{at}. {score}.'],
            'late_winner' => ['LATE GOAL! {player} for {club}', 'Late, late drama{at}. Yoh! {score}.'],
            'consolation' => ['GOAL! One for pride', '{player} pulls one back for {club}{at}. {score}.'],
            'red_card' => ['RED CARD! {player} is off, haibo!', '{club} are down to ten{at}. Still {score_short}.'],
            'half_time' => ['Half-time: {home} {score_short} {away}', '{score} at the break.'],
            'win' => ['Ayoba! {club} win it', 'Full time: {score}.'],
            'draw' => ['Full time: nobody’s laughing', '{score}.'],
        ],
    ];

    /** The fan's voice: ?voice= first, then the remembered cookie, then English. */
    public static function voiceFor(\Illuminate\Http\Request $request): string
    {
        foreach ([$request->query('voice'), $request->cookie('griot_voice')] as $voice) {
            if (is_string($voice) && array_key_exists($voice, self::VOICES)) {
                return $voice;
            }
        }

        return 'en';
    }

    /** The raw [headline, line] template for a voice and template key (the timeline reuses the goal lines). */
    public static function lines(string $voice, string $template): array
    {
        return self::LINES[$voice][$template] ?? self::LINES['en'][$template];
    }

    /** [headline, line] for a chapter. */
    public function tell(MatchMoment $moment, string $voice = 'en'): array
    {
        $voice = array_key_exists($voice, self::VOICES) ? $voice : 'en';
        $match = $moment->match;
        $clubs = ['home' => ClubName::short($match->homeTeam), 'away' => ClubName::short($match->awayTeam)];
        $club = $clubs[$moment->side === 'away' ? 'away' : 'home'];

        $template = match ($moment->type) {
            'goal' => $moment->angle ?? 'opener',
            'full_time' => $moment->side ? 'win' : 'draw',
            default => $moment->type,
        };
        [$headline, $line] = self::LINES[$voice][$template] ?? self::LINES['en'][$template];
        $values = [
            '{club}' => $club,
            '{home}' => $clubs['home'],
            '{away}' => $clubs['away'],
            '{player}' => $moment->player_name ?: $club,
            '{at}' => self::at($voice, $moment->minute, $moment->extra_minute),
            '{minute}' => $moment->clock ?? '',
            '{score}' => ClubName::full($match->homeTeam).' '.$moment->home_score.'–'.$moment->away_score.' '.ClubName::full($match->awayTeam),
            '{score_short}' => $moment->home_score.'–'.$moment->away_score,
        ];

        return [self::fill($headline, $values), self::fill($line, $values)];
    }

    /** When it happened, for the {at} slot: " in the 41st minute", " in stoppage time (45+2)", or "" without a minute. */
    public static function at(string $voice, ?int $minute, ?int $extra = null): string
    {
        if ($minute === null || $minute <= 0) {
            return '';
        }
        if ($extra) {
            return match ($voice) {
                'pcm' => " for added time ({$minute}+{$extra})",
                'sw' => " katika dakika za nyongeza ({$minute}+{$extra})",
                default => " in stoppage time ({$minute}+{$extra})",
            };
        }

        return match ($voice) {
            'pcm' => " for minute {$minute}",
            'sw' => " dakika ya {$minute}",
            default => ' in the '.self::ordinal($minute).' minute',
        };
    }

    public static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    /** Headline and line as one sentence pair for a post: "GOAL! Saka puts Arsenal in front. Arsenal ahead…". */
    public static function joined(array $told): string
    {
        [$headline, $line] = array_pad(array_values($told), 2, '');
        $headline = trim($headline);

        return trim(($headline !== '' && !preg_match('/[.!?]$/u', $headline) ? "{$headline}." : $headline).' '.trim($line));
    }

    /** Fills a template and tidies spaces left by empty slots ("ten ." → "ten."). */
    public static function fill(string $text, array $values): string
    {
        return trim(preg_replace(['/\s+/u', '/\s+([.,!?])/u'], [' ', '$1'], strtr($text, $values)));
    }

    /** Short label for the card's tag, e.g. "GOAL · 41′". */
    public function tag(MatchMoment $moment): string
    {
        $label = ['kickoff' => 'KICK-OFF', 'goal' => 'GOAL', 'red_card' => 'RED CARD', 'half_time' => 'HALF-TIME', 'full_time' => 'FULL TIME'][$moment->type];

        return in_array($moment->type, ['goal', 'red_card'], true) && $moment->clock ? "{$label} · {$moment->clock}" : $label;
    }
}
