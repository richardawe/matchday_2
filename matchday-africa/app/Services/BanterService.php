<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Support\Armies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Speech bubbles for the reel: what the two armies say to each other, up to four per reel.
 *
 *   title  the home army sizes the visitors up (written)
 *   first  the army behind the first big moment (AI when allowed)
 *   reply  the other army answers on the last big moment (written)
 *   final  the winners, or either side in a draw (AI when allowed)
 *
 * Written lines come from config/banter.php. Up to config('reel.banter.max_ai') lines are asked of
 * the AI service the newsroom already uses; every AI line must pass check() (length, blocklist, no
 * names but the two armies, no numbers but the real ones) or a written line takes its place, so a
 * reel never waits on the AI. Each army keeps one language (English or Pidgin), fixed per match.
 */
class BanterService
{
    public const MAX_LENGTH = 60;

    public function __construct(private MatchStoryService $stories, private OpenRouterService $ai) {}

    /** @return array<int, array{slot: string, scene: string, beat: ?int, side: string, lang: string, text: string, source: string}> */
    public function compose(FootballMatch $match): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam']);
        $facts = $this->facts($match);
        $plan = $this->plan($match, $facts);
        if (!$plan) {
            return [];
        }

        $ai = config('reel.banter.ai') ? $this->askAi($facts, array_slice(array_values(array_filter($plan, fn ($b) => $b['ai'])), 0, (int) config('reel.banter.max_ai'))) : [];
        $seed = $match->id;

        return array_map(function (array $bubble) use ($facts, $ai, &$seed) {
            $text = $ai[$bubble['slot']] ?? null;
            $source = $text ? 'ai' : 'written';
            $text ??= $this->written($bubble['situation'], $bubble['lang'], $facts, $bubble['side'], $seed++);
            unset($bubble['situation'], $bubble['ai']);

            return $bubble + ['text' => $text, 'source' => $source];
        }, $plan);
    }

    /**
     * Whether a line may go in a bubble. Army names are the only names allowed and are removed
     * first, so the blocklist and the capitalised-word check judge the rest of the sentence.
     */
    public function check(string $text, array $facts): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH || preg_match('/[\r\n@#<>{}\[\]]|https?:|www\./i', $text)) {
            return false;
        }
        $rest = $text;
        foreach ($facts['names'] as $name) {
            $rest = preg_replace('/\b'.preg_quote($name, '/').'\b/iu', ' ', $rest);
        }
        // Anyone named in this match's events (scorers, assists, cards), wherever they appear.
        foreach ($facts['people'] ?? [] as $person) {
            if (preg_match('/\b'.preg_quote($person, '/').'\b/iu', $rest)) {
                return false;
            }
        }
        foreach (config('banter.blocklist') as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $rest)) {
                return false;
            }
        }
        // Numbers must be the real score or minutes.
        preg_match_all('/\d+/', $rest, $numbers);
        if (array_diff($numbers[0], $facts['numbers'])) {
            return false;
        }
        // A capitalised word mid-sentence is a name; the only names allowed are the armies'.
        $words = preg_split('/(?<=[.!?])\s+/', $rest);
        foreach ($words as $sentence) {
            if (preg_match_all('/(?<!^)(?<=\s)\p{Lu}\p{Ll}+/u', trim($sentence), $caps) && array_diff($caps[0], ['I', 'Ehen', 'Oya', 'Abeg', 'Na', 'Una'])) {
                return false;
            }
        }

        return true;
    }

    /** The facts a line about this match may use, for checking edited lines. */
    public function factsFor(FootballMatch $match): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam']);

        return $this->facts($match);
    }

    /** What the bubbles may say: army names, the score and the minutes of the big moments. */
    private function facts(FootballMatch $match): array
    {
        $chapters = $this->stories->chapters($match);
        $home = Armies::for($match->homeTeam)['army'] ?? 'Home';
        $away = Armies::for($match->awayTeam)['army'] ?? 'Away';
        $big = $chapters->whereIn('type', ['goal', 'red_card'])->values();
        $numbers = collect([$match->home_score, $match->away_score])
            ->merge($big->pluck('minute'))->merge($big->pluck('home_score'))->merge($big->pluck('away_score'))
            ->filter(fn ($n) => $n !== null)->map(fn ($n) => (string) (int) $n)->unique()->values()->all();
        $names = collect([$home, $away])->flatMap(fn ($n) => [$n, ...preg_split('/[\s\-’\']+/u', $n)])
            ->filter(fn ($w) => mb_strlen($w) > 2)->sortByDesc(fn ($w) => mb_strlen($w))->values()->all();

        $people = MatchEvent::where('match_id', $match->id)->get(['player_name', 'assist_player_name'])
            ->flatMap(fn ($e) => [$e->player_name, $e->assist_player_name])->filter()
            ->flatMap(fn ($n) => preg_split('/[\s\-’\']+/u', $n))->filter(fn ($w) => mb_strlen($w) > 2)
            ->reject(fn ($w) => in_array(mb_strtolower($w), array_map('mb_strtolower', $names), true))->unique()->values()->all();

        return [
            'home' => $home, 'away' => $away, 'people' => $people,
            'home_score' => (int) $match->home_score, 'away_score' => (int) $match->away_score,
            'big' => $big, 'numbers' => $numbers, 'names' => $names,
        ];
    }

    /** Which bubbles this reel gets, who says them and in what situation. Mirrors the reel's beat picker. */
    private function plan(FootballMatch $match, array $facts): array
    {
        $langs = array_values(config('reel.banter.langs'));
        $lang = ['home' => $langs[$match->id % count($langs)], 'away' => $langs[($match->id + 1) % count($langs)]];
        $other = fn (string $side) => $side === 'home' ? 'away' : 'home';
        $shown = $facts['big']->count() > 5 ? $facts['big']->slice(-5)->values() : $facts['big'];
        $plan = [['slot' => 'title', 'scene' => 'title', 'beat' => null, 'side' => 'home', 'situation' => 'title', 'ai' => false]];

        if ($first = $shown->first()) {
            $situation = $first->type === 'red_card' ? 'red_card_taunt' : ((int) $first->minute >= 85 ? 'late_goal' : 'goal');
            $speaker = $first->type === 'red_card' ? $other($first->side ?? 'home') : ($first->side ?? 'home');
            $plan[] = ['slot' => 'first', 'scene' => 'beat', 'beat' => $first->id, 'side' => $speaker, 'situation' => $situation, 'ai' => true];

            $last = $shown->last();
            $answer = $last->type === 'red_card' ? 'red_card_lament' : 'conceded';
            $plan[] = ['slot' => 'reply', 'scene' => 'beat', 'beat' => $last->id, 'side' => $last->type === 'red_card' ? ($last->side ?? 'home') : $other($last->side ?? 'home'), 'situation' => $answer, 'ai' => false];
        }

        $margin = abs($facts['home_score'] - $facts['away_score']);
        $winner = $facts['home_score'] === $facts['away_score'] ? null : ($facts['home_score'] > $facts['away_score'] ? 'home' : 'away');
        $plan[] = ['slot' => 'final', 'scene' => 'final', 'beat' => null, 'side' => $winner ?? 'away',
            'situation' => !$winner ? 'draw' : ($margin >= 3 ? 'big_win' : 'win'), 'ai' => true];

        return array_map(fn ($b) => $b + ['lang' => $lang[$b['side']]], $plan);
    }

    private function written(string $situation, string $lang, array $facts, string $side, int $seed): string
    {
        $pool = config("banter.{$situation}.{$lang}") ?: config("banter.{$situation}.en");

        return $this->fill($pool[$seed % count($pool)], $facts, $side);
    }

    private function fill(string $line, array $facts, string $side): string
    {
        $foe = $side === 'home' ? 'away' : 'home';

        return strtr($line, [
            '{army}' => $facts[$side], '{foe}' => $facts[$foe],
            '{score}' => $facts["{$side}_score"].'–'.$facts["{$foe}_score"],
        ]);
    }

    /**
     * One request for the AI's lines, as JSON keyed by slot. Lines that fail check() are
     * dropped, and the written bank fills those bubbles.
     *
     * @return array<string, string>
     */
    private function askAi(array $facts, array $slots): array
    {
        if (!$slots) {
            return [];
        }
        $describe = [
            'goal' => 'just scored', 'late_goal' => 'just scored a late goal', 'red_card_taunt' => 'just saw the other army get a red card',
            'win' => 'won the match', 'big_win' => 'won the match by a big margin', 'draw' => 'drew the match',
        ];
        $ask = collect($slots)->map(fn ($b) => sprintf('- "%s": the %s (%s) %s. Language: %s.', $b['slot'], $facts[$b['side']],
            $b['side'] === 'home' ? 'home side' : 'away side', $describe[$b['situation']] ?? 'speaks',
            $b['lang'] === 'pcm' ? 'Nigerian Pidgin' : 'English'))->implode("\n");
        $score = "{$facts['home']} {$facts['home_score']}–{$facts['away_score']} {$facts['away']}";

        $prompt = <<<PROMPT
        You write speech-bubble banter for a 20-second football reel. Two statue armies stand in for the clubs and trade playful lines. Final score: {$score}.

        Write one line for each bubble:
        {$ask}

        Rules, all required:
        - At most 55 characters per line. One sentence or two short ones.
        - The armies speak to each other. Never mention real people: no players, managers, referees or fans, and no club names.
        - The only names allowed are "{$facts['home']}" and "{$facts['away']}". The only numbers allowed are the real score.
        - Playful and confident, never cruel. No swearing, insults, religion, tribe, politics, nationality, body, gender or violence.
        - Pidgin lines should sound like natural Nigerian Pidgin on social media, not translated English.

        Reply with JSON only, like {"first": "...", "final": "..."}.
        PROMPT;

        try {
            $reply = $this->ai->editFootballArticle($prompt);
            $json = $reply && preg_match('/\{.*\}/s', $reply, $m) ? json_decode($m[0], true) : null;
        } catch (Throwable $e) {
            Log::warning('Reel banter AI failed', ['error' => $e->getMessage()]);
            $json = null;
        }

        $lines = [];
        foreach ($slots as $b) {
            $line = is_array($json) && is_string($json[$b['slot']] ?? null) ? trim($json[$b['slot']], " \"'") : null;
            if ($line && $this->check($line, $facts)) {
                $lines[$b['slot']] = $line;
            } elseif ($line) {
                Log::info('Reel banter AI line rejected', ['slot' => $b['slot'], 'line' => $line]);
            }
        }

        return $lines;
    }
}
