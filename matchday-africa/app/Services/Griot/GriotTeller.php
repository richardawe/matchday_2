<?php

namespace App\Services\Griot;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Services\Griot;
use App\Support\Armies;

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
            'kickoff' => [['Hear me, the armies meet', 'The {home_army} and the {away_army} take the field. Let the drums speak.']],
            'own_goal' => [['The gods favour the {army}', '{player} turns it into his own net. {score_short}.']],
            'penalty' => [['From the spot, it is done', '{player} does not miss. {score_short}.']],
            'goal_assist' => ['{assist} laid it on for him.'],
            'goal_pressure' => ['The {army} knocked {shots_before} times before the door gave way.'],
            'red_card' => [['A warrior is banished', '{player} is sent from the field. The {army} fight on with ten.']],
            'var' => [['The elders say no', '{player} thought he had scored. The screen has spoken: no goal.']],
            'woodwork' => [['The post stands guard', '{player} strikes {where} and the frame shakes. Still {score_short}.'],
                ['Wood, only wood', '{player} {where}. The post refuses him.']],
            'big_chance_saved' => [['The keeper holds the gate', '{player} {where}, but the shot is saved. Still {score_short}.'],
                ['Denied', '{player} goes for it {where}. The keeper stands firm.']],
            'big_chance_missed' => [['So close, so far', '{player} {where} and it goes wide. Still {score_short}.'],
                ['The chance escapes', '{player} {where}, but the goal stays shut.']],
            'big_chance_penalty_missed' => [['The spot betrays him', '{player} misses the penalty. Still {score_short}.']],
            'pressure' => [['The {army} press forward', '{shots} shots in {span_text}, {on_target_text}. The {opp_army} hold on.'],
                ['The drums beat faster', 'The {army} come again and again: {shots} shots in {span_text}.']],
            'injury' => [['A warrior is hurt', '{player_off} cannot go on. {player_on} takes his place for the {army}.']],
            'half_time' => [['The drums pause', 'Half-time: {score}. Shots {home_shots} to {away_shots}.']],
            'win' => [['Let it be told: the {army} prevail', 'Full time: {score}. Shots {home_shots} to {away_shots}.']],
            'draw' => [['Honours even, no army yields', 'Full time: {score}. Shots {home_shots} to {away_shots}.']],
            'where' => ['six_yard' => 'from six yards', 'box' => 'inside the box', 'outside' => 'from distance', '' => 'goes for goal'],
        ],
        'pcm' => [
            'kickoff' => [['Wahala don start', '{home_army} and {away_army} don enter field. Make the drum talk!']],
            'own_goal' => [['E don enter by heself!', '{player} put am for him own net. {score_short}.']],
            'penalty' => [['Penalty don enter!', '{player} no dull am. {score_short}.']],
            'goal_assist' => ['Na {assist} give am the ball.'],
            'goal_pressure' => ['{army} knock the door {shots_before} times before e open.'],
            'red_card' => [['Red card! E don red', '{player} don comot. {army} remain ten.']],
            'var' => [['VAR don cancel am!', '{player} think say e don score. VAR talk say no goal.']],
            'woodwork' => [['Na post save dem!', '{player} shoot {where}, ball jam post. Still {score_short}.']],
            'big_chance_saved' => [['Keeper don hold am!', '{player} try {where}, keeper catch am. Still {score_short}.']],
            'big_chance_missed' => [['Chai! E for don enter', '{player} try {where}, e no enter. Still {score_short}.']],
            'big_chance_penalty_missed' => [['Penalty don waste!', '{player} miss the penalty. Still {score_short}.']],
            'pressure' => [['{army} dey press dem!', '{shots} shots for {span_text}. {opp_army} still dey hold.']],
            'injury' => [['Injury wahala', '{player_off} no fit continue. {player_on} don enter for {army}.']],
            'half_time' => [['Half time, make we breathe', '{score} for now. Shots {home_shots} to {away_shots}.']],
            'win' => [['{army} don win am!', 'Final: {score}. Shots {home_shots} to {away_shots}.']],
            'draw' => [['Nobody win today', 'Final: {score}. Dem share the points.']],
            'where' => ['six_yard' => 'for six-yard box', 'box' => 'inside box', 'outside' => 'from far', '' => 'for goal'],
        ],
        'sw' => [
            'kickoff' => [['Vita imeanza', '{home_army} dhidi ya {away_army}. Ngoma zinaanza kulia.']],
            'own_goal' => [['Bao la kujifunga!', '{player} ajifunga. {score_short}.']],
            'penalty' => [['Penalti imeingia!', '{player} hakosei. {score_short}.']],
            'goal_assist' => ['Pasi ya {assist}.'],
            'goal_pressure' => ['{army} walibisha hodi mara {shots_before} kabla mlango haujafunguka.'],
            'red_card' => [['Kadi nyekundu!', '{player} atolewa nje. {army} wabaki kumi.']],
            'var' => [['VAR yakataa!', '{player} alidhani amefunga. VAR imesema hapana.']],
            'woodwork' => [['Mwamba umeokoa!', '{player} apiga {where}, mpira wagonga mwamba. Bado {score_short}.']],
            'big_chance_saved' => [['Kipa ameokoa!', '{player} ajaribu {where}, kipa adaka. Bado {score_short}.']],
            'big_chance_missed' => [['Nafasi imepotea!', '{player} ajaribu {where}, haiingii. Bado {score_short}.']],
            'big_chance_penalty_missed' => [['Penalti imekosa!', '{player} akosa penalti. Bado {score_short}.']],
            'pressure' => [['{army} wanashambulia!', 'Mashuti {shots} kwa {span_text}. {opp_army} wanashikilia.']],
            'injury' => [['Jeraha', '{player_off} hawezi kuendelea. {player_on} aingia kwa {army}.']],
            'half_time' => [['Mapumziko', 'Kipindi cha kwanza: {score}. Mashuti {home_shots} kwa {away_shots}.']],
            'win' => [['{army} washinda!', 'Mwisho wa mchezo: {score}.']],
            'draw' => [['Sare!', 'Mwisho wa mchezo: {score}.']],
            'where' => ['six_yard' => 'karibu na goli', 'box' => 'ndani ya boksi', 'outside' => 'kutoka mbali', '' => 'golini'],
        ],
        'za' => [
            'kickoff' => [['Ke nako!', '{home_army} vs {away_army}. Let the drums go.']],
            'own_goal' => [['Eish, into his own net!', '{player} gifts it. {score_short}.']],
            'penalty' => [['Penalty, sharp!', '{player} sends it home. {score_short}.']],
            'goal_assist' => ['{assist} with the setup.'],
            'goal_pressure' => ['The {army} knocked {shots_before} times first.'],
            'red_card' => [['Red card, haibo!', '{player} is off. The {army} are down to ten.']],
            'var' => [['VAR says no, yoh!', '{player} thought it counted. No goal.']],
            'woodwork' => [['Off the woodwork!', '{player} {where} and the post says no. Still {score_short}.']],
            'big_chance_saved' => [['What a save!', '{player} {where}, the keeper stops it. Still {score_short}.']],
            'big_chance_missed' => [['Haibo, how did that miss?', '{player} {where}, wide. Still {score_short}.']],
            'big_chance_penalty_missed' => [['Penalty missed, eish!', '{player} misses from the spot. Still {score_short}.']],
            'pressure' => [['The {army} are coming!', '{shots} shots in {span_text}. The {opp_army} hang on.']],
            'injury' => [['Injury blow', '{player_off} is done. {player_on} comes on for the {army}.']],
            'half_time' => [['Half-time, take a breather', '{score} at the break.']],
            'win' => [['Ayoba! The {army} win it', 'Full time: {score}.']],
            'draw' => [['Nobody’s laughing today', 'Full time: {score}.']],
            'where' => ['six_yard' => 'from close range', 'box' => 'in the box', 'outside' => 'from far out', '' => 'goes for it'],
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
            $label .= ' · '.$this->club($post->side === 'away' ? $post->match?->awayTeam : $post->match?->homeTeam);
        }

        return $label;
    }

    /** What the Griot says aloud: the caption with scores and minutes written for speech. */
    public function speech(GriotPost $post, string $voice = 'en'): string
    {
        [$headline, $line] = $this->tell($post, $voice);
        $text = rtrim($headline, '.!?').'! '.$line;

        return preg_replace(['/(\d+)\+(\d+)′/u', '/(\d+)′/u', '/(\d)\s*[–-]\s*(\d)/u'], ['$1 plus $2', 'minute $1', '$1 $2'], $text);
    }

    private function values(GriotPost $post, FootballMatch $match, string $voice): array
    {
        $facts = $post->facts ?? [];
        $home = Armies::for($match->homeTeam);
        $away = Armies::for($match->awayTeam);
        $mine = $post->side === 'away' ? $away : $home;
        $theirs = $post->side === 'away' ? $home : $away;
        $where = self::LINES[$voice]['where'];

        return [
            '{army}' => $mine['army'] ?? $this->club($post->side === 'away' ? $match->awayTeam : $match->homeTeam),
            '{opp_army}' => $theirs['army'] ?? $this->club($post->side === 'away' ? $match->homeTeam : $match->awayTeam),
            '{home_army}' => $home['army'] ?? $this->club($match->homeTeam),
            '{away_army}' => $away['army'] ?? $this->club($match->awayTeam),
            '{player}' => $facts['player'] ?? ($mine['army'] ?? 'They'),
            '{assist}' => $facts['assist'] ?? '',
            '{player_on}' => $facts['player_on'] ?? 'A substitute',
            '{player_off}' => $facts['player_off'] ?? 'A player',
            '{minute}' => $post->clock ?? '',
            '{where}' => $where[$facts['zone'] ?? ''] ?? $where[''],
            '{shots}' => (string) ($facts['shots'] ?? ''),
            '{shots_before}' => (string) ($facts['shots_before'] ?? ''),
            '{on_target}' => (string) ($facts['on_target'] ?? 0),
            '{span_text}' => $this->span((int) ($facts['span'] ?? 1), $voice),
            '{on_target_text}' => ($facts['on_target'] ?? 0) ? "{$facts['on_target']} on target" : 'none on target',
            '{home_shots}' => (string) ($facts['home_shots'] ?? 0),
            '{away_shots}' => (string) ($facts['away_shots'] ?? 0),
            '{score}' => $this->club($match->homeTeam).' '.$post->home_score.'–'.$post->away_score.' '.$this->club($match->awayTeam),
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
        return trim(preg_replace('/\s+/', ' ', strtr($text, $values)));
    }

    private function club($team): string
    {
        return trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $team?->name));
    }
}
