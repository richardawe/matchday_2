<?php

namespace App\Services\Griot;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Services\Griot;
use App\Support\ClubName;

/**
 * Tells each timeline post in the fan's voice. Templates only: every name, minute and number
 * comes from the post's facts, so the Griot never invents. When the small writer model has
 * written a caption that passed the fact check (griot_posts.written), that is used instead.
 * Pidgin, Swahili/Sheng and Mzansi lines need a native speaker's review before launch.
 */
class GriotTeller
{
    /** Each kind: one or more [headline, line] variants, picked by the post's key so reruns agree. */
    private const LINES = [
        'en' => [
            'kickoff' => [['Kick-off: {home} v {away}', 'Hear me: {home} and {away} are under way. Let the drums speak.']],
            'own_goal' => [['OWN GOAL! {player} turns it in', '{player} puts it into his own net{at}. A gift for {club}. {score}.']],
            'penalty' => [['PENALTY! {player} scores for {club}', '{player} does not miss from the spot{at}. {score}.']],
            'goal_assist' => ['{assist} laid it on for him.'],
            'goal_pressure' => ['{club} knocked {shots_before} times before the door gave way.'],
            'red_card' => [['RED CARD! {player} is sent off', '{club} are down to ten{at}. Still {score_short}.']],
            'var' => [['VAR: no goal for {club}', '{player} thought he had scored{at}. The screen says no. Still {score_short}.']],
            'woodwork' => [['OFF THE POST! {player} so close', '{player} hits the woodwork{where} for {club}{at}. Still {score_short}.'],
                ['OFF THE WOODWORK! {club} denied', '{player} strikes{where} and the frame shakes{at}. Still {score_short}.']],
            'big_chance_saved' => [['SAVED! {player} is denied', '{player} shoots{where} for {club}{at}, but the keeper saves. Still {score_short}.'],
                ['WHAT A SAVE! {club} denied', 'The keeper stops {player}{where}{at}. Still {score_short}.']],
            'big_chance_missed' => [['MISSED! {player} so close', '{player} shoots{where} for {club}{at} and it goes wide. Still {score_short}.'],
                ['CHANCE GONE for {club}', '{player} fires{where}{at}, but the goal stays shut. Still {score_short}.']],
            'big_chance_penalty_missed' => [['PENALTY MISSED! {player}', '{player} misses from the spot for {club}{at}. Still {score_short}.']],
            'pressure' => [['{club} on the attack', '{shots} shots in {span_text}, {on_target_text}. {opp} hold on.'],
                ['{club} pile on the pressure', '{club} come again and again: {shots} shots in {span_text}.']],
            'injury' => [['INJURY: {player_off} goes off', '{player_off} cannot go on{at}. {player_on} comes on for {club}.']],
            'half_time' => [['Half-time: {home} {score_short} {away}', '{score} at the break. Shots {home_shots} to {away_shots}.']],
            'win' => [['Full time: {club} win', '{score}. Shots {home_shots} to {away_shots}.']],
            'draw' => [['Full time: honours even', '{score}. Shots {home_shots} to {away_shots}.']],
            'where' => ['six_yard' => ' from six yards', 'box' => ' inside the box', 'outside' => ' from distance', '' => ''],
        ],
        'pcm' => [
            'kickoff' => [['Match don start: {home} v {away}', '{home} and {away} don enter field. Make the drum talk!']],
            'own_goal' => [['Own goal! {player} score for the other side', '{player} put am for him own net{at}. Na gift for {club}. {score}.']],
            'penalty' => [['Penalty don enter! {player} for {club}', '{player} no dull am{at}. {score}.']],
            'goal_assist' => ['Na {assist} give am the ball.'],
            'goal_pressure' => ['{club} knock the door {shots_before} times before e open.'],
            'red_card' => [['Red card! {player} don comot', '{club} remain ten{at}. Still {score_short}.']],
            'var' => [['VAR don cancel {club} goal!', '{player} think say e don score{at}. VAR talk say no goal.']],
            'woodwork' => [['Na post save dem! {player}', '{player} shoot{where} for {club}, ball jam post{at}. Still {score_short}.']],
            'big_chance_saved' => [['Keeper don hold am! {player}', '{player} try{where} for {club}{at}, keeper catch am. Still {score_short}.']],
            'big_chance_missed' => [['Chai! {player} miss am', '{player} try{where} for {club}{at}, e no enter. Still {score_short}.']],
            'big_chance_penalty_missed' => [['Penalty don waste! {player}', '{player} miss the penalty for {club}{at}. Still {score_short}.']],
            'pressure' => [['{club} dey press!', '{shots} shots for {span_text}. {opp} still dey hold.']],
            'injury' => [['Injury: {player_off} don comot', '{player_off} no fit continue{at}. {player_on} don enter for {club}.']],
            'half_time' => [['Half time: {home} {score_short} {away}', '{score} for now. Shots {home_shots} to {away_shots}.']],
            'win' => [['Full time: {club} don win am!', 'Final: {score}. Shots {home_shots} to {away_shots}.']],
            'draw' => [['Full time: nobody win today', 'Final: {score}. Dem share the points.']],
            'where' => ['six_yard' => ' for six-yard box', 'box' => ' inside box', 'outside' => ' from far', '' => ''],
        ],
        'sw' => [
            'kickoff' => [['Mechi imeanza: {home} dhidi ya {away}', '{home} na {away} wameanza. Ngoma zinaanza kulia.']],
            'own_goal' => [['Goli la kujifunga! {player}', '{player} ajifunga{at}. Zawadi kwa {club}. {score}.']],
            'penalty' => [['Penalti! {player} afungia {club}', '{player} hakosei{at}. {score}.']],
            'goal_assist' => ['Pasi ya {assist}.'],
            'goal_pressure' => ['{club} walibisha hodi mara {shots_before} kabla mlango haujafunguka.'],
            'red_card' => [['Kadi nyekundu! {player} atolewa', '{club} wabaki kumi{at}. Bado {score_short}.']],
            'var' => [['VAR yakataa goli la {club}', '{player} alidhani amefunga{at}. VAR imesema hapana.']],
            'woodwork' => [['Mwamba! {player} karibu', '{player} wa {club} apiga{where}, mpira wagonga mwamba{at}. Bado {score_short}.']],
            'big_chance_saved' => [['Kipa aokoa! {player}', '{player} wa {club} ajaribu{where}{at}, kipa adaka. Bado {score_short}.']],
            'big_chance_missed' => [['Nafasi imepotea! {player}', '{player} wa {club} ajaribu{where}{at}, haiingii. Bado {score_short}.']],
            'big_chance_penalty_missed' => [['Penalti imekosa! {player}', '{player} wa {club} akosa penalti{at}. Bado {score_short}.']],
            'pressure' => [['{club} wanashambulia!', 'Mashuti {shots} kwa {span_text}. {opp} wanashikilia.']],
            'injury' => [['Jeraha: {player_off} atoka', '{player_off} hawezi kuendelea{at}. {player_on} aingia kwa {club}.']],
            'half_time' => [['Mapumziko: {home} {score_short} {away}', 'Kipindi cha kwanza: {score}. Mashuti {home_shots} kwa {away_shots}.']],
            'win' => [['Mwisho: {club} washinda!', 'Mwisho wa mchezo: {score}.']],
            'draw' => [['Mwisho: sare!', 'Mwisho wa mchezo: {score}.']],
            'where' => ['six_yard' => ' karibu na goli', 'box' => ' ndani ya boksi', 'outside' => ' kutoka mbali', '' => ''],
        ],
        'za' => [
            'kickoff' => [['Ke nako! {home} v {away}', '{home} and {away} are under way. Let the drums go.']],
            'own_goal' => [['OWN GOAL! Eish, {player}', '{player} gifts it to {club}{at}. {score}.']],
            'penalty' => [['PENALTY! {player} for {club}, sharp!', '{player} sends it home{at}. {score}.']],
            'goal_assist' => ['{assist} with the setup.'],
            'goal_pressure' => ['{club} knocked {shots_before} times first.'],
            'red_card' => [['RED CARD! {player} is off, haibo!', '{club} are down to ten{at}. Still {score_short}.']],
            'var' => [['VAR says no to {club}, yoh!', '{player} thought it counted{at}. No goal.']],
            'woodwork' => [['OFF THE WOODWORK! {player}', '{player} hits the post{where} for {club}{at}. Still {score_short}.']],
            'big_chance_saved' => [['WHAT A SAVE! {player} denied', '{player} goes{where} for {club}{at}, the keeper stops it. Still {score_short}.']],
            'big_chance_missed' => [['Haibo, {player} misses!', '{player} shoots{where} for {club}{at}, wide. Still {score_short}.']],
            'big_chance_penalty_missed' => [['PENALTY MISSED! Eish, {player}', '{player} misses from the spot for {club}{at}. Still {score_short}.']],
            'pressure' => [['{club} are coming!', '{shots} shots in {span_text}. {opp} hang on.']],
            'injury' => [['INJURY: {player_off} is done', '{player_off} goes off{at}. {player_on} comes on for {club}.']],
            'half_time' => [['Half-time: {home} {score_short} {away}', '{score} at the break.']],
            'win' => [['Ayoba! {club} win it', 'Full time: {score}.']],
            'draw' => [['Full time: nobody’s laughing', '{score}.']],
            'where' => ['six_yard' => ' from close range', 'box' => ' in the box', 'outside' => ' from far out', '' => ''],
        ],
    ];

    /** Tag above the post, e.g. "GOAL · 41′". */
    private const TAGS = ['kickoff' => 'Kick-off', 'goal' => 'Goal', 'red_card' => 'Red card', 'var' => 'VAR', 'woodwork' => 'Off the post',
        'big_chance' => 'Big chance', 'pressure' => 'Pressure', 'injury' => 'Injury', 'half_time' => 'Half-time', 'full_time' => 'Full time'];

    public function __construct(private Griot $griot) {}

    /** [headline, line] for a post. */
    public function tell(GriotPost $post, string $voice = 'en', ?FootballMatch $match = null): array
    {
        $voice = array_key_exists($voice, Griot::VOICES) ? $voice : 'en';
        if ($voice === 'en' && is_array($post->written) && isset($post->written['headline'], $post->written['line'])) {
            return [$post->written['headline'], $post->written['line']];
        }
        $match ??= $post->match;
        $facts = $post->facts ?? [];
        $values = $this->values($post, $match, $voice);
        $lines = self::LINES[$voice];

        if ($post->kind === 'goal') {
            $template = match (true) {
                !empty($facts['own_goal']) => 'own_goal',
                !empty($facts['penalty']) => 'penalty',
                default => null,
            };
            [$headline, $line] = $template ? $this->variant($lines[$template], $post) : Griot::lines($voice, $facts['angle'] ?? 'opener');
            if (!empty($facts['assist']) && empty($facts['own_goal'])) {
                $line .= ' '.$lines['goal_assist'][0];
            } elseif (($facts['shots_before'] ?? 0) >= 2) {
                $line .= ' '.$lines['goal_pressure'][0];
            }
        } else {
            $template = match ($post->kind) {
                'big_chance' => 'big_chance_'.($facts['outcome'] ?? 'missed'),
                'full_time' => $post->side ? 'win' : 'draw',
                default => $post->kind,
            };
            [$headline, $line] = $this->variant($lines[$template] ?? self::LINES['en'][$template], $post);
        }

        return [$this->fill($headline, $values), $this->fill($line, $values)];
    }

    public function tag(GriotPost $post): string
    {
        $label = self::TAGS[$post->kind] ?? ucfirst($post->kind);
        if ($post->kind === 'goal' || $post->kind === 'red_card') {
            $label .= ' · '.ClubName::short($post->side === 'away' ? $post->match?->awayTeam : $post->match?->homeTeam);
        }

        return $label;
    }

    /** What the Griot says aloud: the caption with scores and minutes written for speech. */
    public function speech(GriotPost $post, string $voice = 'en'): string
    {
        [$headline, $line] = $this->tell($post, $voice);
        $text = rtrim($headline, '.!?').'! '.$line;

        return preg_replace(['/\((\d+)\+(\d+)\)/u', '/(\d+)\+(\d+)′/u', '/(\d+)′/u', '/(\d)\s*[–-]\s*(\d)/u'], ['$1 plus $2', '$1 plus $2', 'minute $1', '$1 $2'], $text);
    }

    private function values(GriotPost $post, FootballMatch $match, string $voice): array
    {
        $facts = $post->facts ?? [];
        $home = ClubName::short($match->homeTeam);
        $away = ClubName::short($match->awayTeam);
        $mine = $post->side === 'away' ? $away : $home;
        $theirs = $post->side === 'away' ? $home : $away;
        $where = self::LINES[$voice]['where'];

        return [
            '{club}' => $mine,
            '{opp}' => $theirs,
            '{home}' => $home,
            '{away}' => $away,
            '{player}' => $facts['player'] ?? $mine,
            '{assist}' => $facts['assist'] ?? '',
            '{player_on}' => $facts['player_on'] ?? 'A substitute',
            '{player_off}' => $facts['player_off'] ?? 'A player',
            '{minute}' => $post->clock ?? '',
            '{at}' => Griot::at($voice, $post->minute, $post->extra_minute),
            '{where}' => $where[$facts['zone'] ?? ''] ?? $where[''],
            '{shots}' => (string) ($facts['shots'] ?? ''),
            '{shots_before}' => (string) ($facts['shots_before'] ?? ''),
            '{on_target}' => (string) ($facts['on_target'] ?? 0),
            '{span_text}' => $this->span((int) ($facts['span'] ?? 1), $voice),
            '{on_target_text}' => ($facts['on_target'] ?? 0) ? "{$facts['on_target']} on target" : 'none on target',
            '{home_shots}' => (string) ($facts['home_shots'] ?? 0),
            '{away_shots}' => (string) ($facts['away_shots'] ?? 0),
            '{score}' => ClubName::full($match->homeTeam).' '.$post->home_score.'–'.$post->away_score.' '.ClubName::full($match->awayTeam),
            '{score_short}' => $post->home_score.'–'.$post->away_score,
        ];
    }

    /** "1 minute", "5 minutes", Swahili "dakika 5". */
    private function span(int $minutes, string $voice): string
    {
        return $voice === 'sw' ? "dakika {$minutes}" : $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
    }

    private function variant(array $variants, GriotPost $post): array
    {
        return $variants[crc32((string) $post->key) % count($variants)];
    }

    private function fill(string $text, array $values): string
    {
        return Griot::fill($text, $values);
    }
}
