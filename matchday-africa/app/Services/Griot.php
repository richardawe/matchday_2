<?php

namespace App\Services;

use App\Models\MatchMoment;
use App\Support\Armies;

/**
 * The Griot: captions each story chapter in the fan's chosen voice. Templates only, so every
 * name, minute and score comes from the match data. Pidgin, Swahili/Sheng and Mzansi lines
 * should be checked by native speakers before launch.
 */
class Griot
{
    public const VOICES = ['en' => 'English', 'pcm' => 'Naija Pidgin', 'sw' => 'Sheng / Swahili', 'za' => 'Mzansi'];

    private const LINES = [
        'en' => [
            'kickoff' => ['The armies meet', '{home_army} v {away_army}. The drums begin.'],
            'opener' => ['First blood', '{player} strikes for the {army}.'],
            'equaliser' => ['The {army} answer', '{player} levels it. All square.'],
            'lead' => ['The {army} take the lead', '{player} puts them in front.'],
            'extend' => ['The {army} press on', '{player} makes it {score_short}.'],
            'late_winner' => ['A late blow', '{player} at {minute}. The {army} strike late.'],
            'consolation' => ['Pride restored', '{player} pulls one back for the {army}.'],
            'red_card' => ['A warrior falls', '{player} is sent off. The {army} fight on with ten.'],
            'half_time' => ['The drums pause', 'Half-time: {score}.'],
            'win' => ['The {army} prevail', 'Full time: {score}.'],
            'draw' => ['Honours even', 'Full time: {score}. No army yields.'],
        ],
        'pcm' => [
            'kickoff' => ['Wahala don start', '{home_army} and {away_army} don enter field.'],
            'opener' => ['Goal don enter!', '{player} don open the account for {army}.'],
            'equaliser' => ['Dem don equalise!', '{player} don bring am back. E level now.'],
            'lead' => ['{army} don take lead!', '{player} don put dem for front.'],
            'extend' => ['E don pass be that!', '{player} add another one. {score_short}.'],
            'late_winner' => ['Last-minute wahala!', '{player} for {minute}. Who dey breathe?'],
            'consolation' => ['At least dem score one', '{player} don reduce am for {army}.'],
            'red_card' => ['Red card! E don red', '{player} don comot. {army} remain ten.'],
            'half_time' => ['Half time, make we breathe', '{score} for now.'],
            'win' => ['{army} don win am!', 'Final: {score}.'],
            'draw' => ['Nobody win today', 'Final: {score}. Dem share the points.'],
        ],
        'sw' => [
            'kickoff' => ['Vita imeanza', '{home_army} vs {away_army}. Ngoma zinaanza.'],
            'opener' => ['Bao la kwanza!', '{player} afungua kwa {army}.'],
            'equaliser' => ['Wamesawazisha!', '{player} arudisha usawa.'],
            'lead' => ['{army} wako mbele!', '{player} awaweka mbele.'],
            'extend' => ['Bao lingine!', '{player} aongeza. {score_short}.'],
            'late_winner' => ['Bao la dakika za mwisho!', '{player} dakika ya {minute}.'],
            'consolation' => ['Bao la kufutia machozi', '{player} afungia {army}.'],
            'red_card' => ['Kadi nyekundu!', '{player} atolewa nje. {army} wabaki kumi.'],
            'half_time' => ['Mapumziko', 'Kipindi cha kwanza: {score}.'],
            'win' => ['{army} washinda!', 'Mwisho wa mchezo: {score}.'],
            'draw' => ['Sare!', 'Mwisho wa mchezo: {score}.'],
        ],
        'za' => [
            'kickoff' => ['Ke nako!', '{home_army} vs {away_army}. Let’s go.'],
            'opener' => ['Eish, what a strike!', '{player} opens it up for the {army}.'],
            'equaliser' => ['Haibo, it’s level!', '{player} brings it back.'],
            'lead' => ['The {army} are in front!', '{player} with the shaya.'],
            'extend' => ['Sharp sharp, another one!', '{player} makes it {score_short}.'],
            'late_winner' => ['Late, late drama!', '{player} at {minute}. Yoh!'],
            'consolation' => ['One for pride', '{player} pulls one back for the {army}.'],
            'red_card' => ['Red card, haibo!', '{player} is off. The {army} are down to ten.'],
            'half_time' => ['Half-time, take a breather', '{score} at the break.'],
            'win' => ['Ayoba! The {army} win it', 'Full time: {score}.'],
            'draw' => ['Nobody’s laughing today', 'Full time: {score}.'],
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
        $home = Armies::for($match->homeTeam);
        $away = Armies::for($match->awayTeam);
        $side = $moment->side === 'away' ? $away : $home;
        $club = fn ($team) => trim(preg_replace('/\s+FC$/i', '', (string) $team?->name));

        $template = match ($moment->type) {
            'goal' => $moment->angle ?? 'opener',
            'full_time' => $moment->side ? 'win' : 'draw',
            default => $moment->type,
        };
        [$headline, $line] = self::LINES[$voice][$template] ?? self::LINES['en'][$template];
        $values = [
            '{army}' => $side['army'] ?? $club($moment->side === 'away' ? $match->awayTeam : $match->homeTeam),
            '{home_army}' => $home['army'] ?? $club($match->homeTeam),
            '{away_army}' => $away['army'] ?? $club($match->awayTeam),
            '{player}' => $moment->player_name ?: ($side['army'] ?? 'They'),
            '{minute}' => $moment->clock ?? '',
            '{score}' => $club($match->homeTeam).' '.$moment->home_score.'–'.$moment->away_score.' '.$club($match->awayTeam),
            '{score_short}' => $moment->home_score.'–'.$moment->away_score,
        ];

        return [strtr($headline, $values), strtr($line, $values)];
    }

    /** Short label for the card's tag, e.g. "GOAL · 41′". */
    public function tag(MatchMoment $moment): string
    {
        $label = ['kickoff' => 'KICK-OFF', 'goal' => 'GOAL', 'red_card' => 'RED CARD', 'half_time' => 'HALF-TIME', 'full_time' => 'FULL TIME'][$moment->type];

        return in_array($moment->type, ['goal', 'red_card'], true) && $moment->clock ? "{$label} · {$moment->clock}" : $label;
    }
}
