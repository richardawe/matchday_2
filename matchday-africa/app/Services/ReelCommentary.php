<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * The full-time reel's commentary, written to sound like one commentator calling the match from
 * kick-off to the final whistle in 20 seconds: the opening line, one call per moment (player,
 * club, minute and the running score) and the final whistle. Every name and number comes from
 * the match data.
 *
 * Each call is a list of wordings, longest first: radio/speak.mjs speaks the longest that fits
 * its scene. Scores are written as words ("two one to Arsenal") because that's how they are said.
 */
class ReelCommentary
{
    private const NUMBERS = ['nil', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];

    /** Name particles that stay with the surname: "Van Dijk", "De Bruyne". */
    private const PARTICLES = ['van', 'von', 'de', 'da', 'di', 'del', 'der', 'den', 'dos', 'du', 'el', 'al', 'le', 'la', 'ten', 'ter', 'mac'];

    /** @param array{home: string, away: string} $clubs spoken club names */
    public function __construct(private array $clubs) {}

    /** The opening line, over the final-score frame. */
    public function title(int $home, int $away): array
    {
        [$h, $a] = [$this->clubs['home'], $this->clubs['away']];

        return ["{$h} against {$a}. Here's how it went!", "{$h} against {$a}. Here we go!", "{$h}, {$a}. Here we go!", "{$h}, {$a}."];
    }

    /** The final whistle, over the numbers and the full-time card. */
    public function final(int $home, int $away): array
    {
        [$h, $a] = [$this->clubs['home'], $this->clubs['away']];
        if ($home === $away) {
            $score = $home === 0 ? 'goalless' : $this->word($home).' all';

            return ["And there's the final whistle! {$h} {$this->word($home)}, {$a} {$this->word($away)}. Honours even!",
                "Full time! It finishes {$score}. Honours even!", "Full time, {$score}!", 'Full time!'];
        }
        $winner = $home > $away ? $h : $a;
        $call = $this->word(max($home, $away)).' '.$this->word(min($home, $away));

        return ["And there's the final whistle! {$h} {$this->word($home)}, {$a} {$this->word($away)}. {$winner} take the points!",
            "Full time! {$winner} win it, {$call}!", "Full time! {$winner} win, {$call}!", 'Full time!'];
    }

    /**
     * One moment's call.
     *
     * @param array{type: string, side: ?string, player?: ?string, minute?: ?int, extra?: ?int, home_score: int, away_score: int,
     *     angle?: ?string, own_goal?: bool, penalty?: bool, outcome?: ?string, player_off?: ?string} $beat
     */
    public function beat(array $beat): array
    {
        $club = $this->clubs[$beat['side'] ?? ''] ?? null;
        $name = $this->surname($beat['player'] ?? null);
        $when = $this->when($beat['minute'] ?? null, $beat['extra'] ?? null);
        $home = (int) $beat['home_score'];
        $away = (int) $beat['away_score'];
        $score = $this->score($home, $away);

        $calls = match ($beat['type']) {
            'goal' => $this->goal($beat, $name, $club, $when, $score),
            'red_card' => [$name && $club && $when ? "Red card! {$name} is sent off for {$club}, {$when}. Down to ten!" : null,
                $name && $club ? "Red card! {$name} is off for {$club}!" : null, $name ? "Red card, {$name}!" : null, 'Red card!'],
            'var' => [$club ? "But VAR says no! {$club}'s goal is ruled out." : null, 'VAR says no goal!', 'No goal!'],
            'woodwork' => [$name && $club ? "Off the post! {$name} so close for {$club}!" : null, $name ? "Off the post, {$name}!" : null, 'Off the post!'],
            'big_chance' => match ($beat['outcome'] ?? 'missed') {
                'saved' => [$name && $club ? "Great save! {$name} denied for {$club}." : null, $name ? "Saved! {$name} denied." : null, 'Great save!'],
                'penalty_missed' => [$name && $club ? "Penalty missed! {$name} can't convert for {$club}." : null, $name ? "{$name} misses the penalty!" : null, 'Penalty missed!'],
                default => [$name && $club ? "Chance for {$name}! Wide, and {$club} can't believe it." : null, $name ? "{$name}, so close!" : null, 'So close!'],
            },
            'pressure' => [$club ? "{$club} pile on the pressure!" : null, $club ? "{$club} attacking!" : null],
            'injury' => [!empty($beat['player_off']) && $club ? "Blow for {$club}: {$this->surname($beat['player_off'])} has to go off." : null,
                !empty($beat['player_off']) ? "{$this->surname($beat['player_off'])} goes off injured." : null],
            'half_time' => ["Half-time, and {$score}.", "Half-time: {$score}.", 'Half-time.'],
            'kickoff' => ["We're under way! {$this->clubs['home']} against {$this->clubs['away']}.", "We're under way!"],
            default => [],
        };

        return $this->tidy($calls);
    }

    /** Drops missing wordings and starts each sentence with a capital ("! it's" → "! It's"). */
    private function tidy(array $calls): array
    {
        $calls = array_map(fn (string $t) => preg_replace_callback('/(^|[.!?]\s+)(\p{Ll})/u', fn ($m) => $m[1].Str::upper($m[2]), $t), array_filter($calls));

        return array_values(array_unique($calls));
    }

    private function goal(array $beat, ?string $name, ?string $club, ?string $when, string $score): array
    {
        if (!empty($beat['own_goal'])) {
            return [$name && $club ? "Own goal! {$name} turns it in, and it's a gift for {$club}. {$score}!" : null,
                $name ? "Own goal, {$name}! {$score}." : null, "Own goal! {$score}."];
        }
        $who = $name ?? $club;
        if (!empty($beat['penalty'])) {
            return [$who && $club && $when ? "Penalty, and {$who} scores for {$club}, {$when}! {$score}." : null,
                $who ? "{$who} scores the penalty! {$score}." : null, "Penalty scored! {$score}."];
        }
        $full = match ($beat['angle'] ?? 'opener') {
            'equaliser' => $who && $club ? "{$who} levels it for {$club}" : null,
            'lead' => $who && $club ? "{$who} puts {$club} in front" : null,
            'extend' => $who && $club ? "{$who} again for {$club}" : null,
            'late_winner' => $who && $club ? "Late drama! {$who} for {$club}" : null,
            'consolation' => $who && $club ? "{$who} pulls one back for {$club}" : null,
            default => $who && $club ? "{$who} scores for {$club}" : null,
        };

        return [$full && $when ? "{$full}, {$when}! {$score}." : null, $full ? "{$full}! {$score}." : null,
            $who ? "Goal, {$who}! {$score}." : null, "Goal! {$score}."];
    }

    /** "two one to Arsenal", "one all", "nil nil". */
    public function score(int $home, int $away): string
    {
        if ($home === $away) {
            return $home === 0 ? 'nil nil' : $this->word($home).' all';
        }
        $leader = $home > $away ? $this->clubs['home'] : $this->clubs['away'];

        return "it's {$this->word(max($home, $away))} {$this->word(min($home, $away))} to {$leader}";
    }

    /** "23 minutes", "deep in stoppage time", or null. */
    public function when(?int $minute, ?int $extra = null): ?string
    {
        if (!$minute || $minute <= 0) {
            return null;
        }
        if ($extra) {
            return $minute >= 90 ? 'deep in stoppage time' : 'in first-half stoppage time';
        }

        return "{$minute} minutes";
    }

    /** What a commentator calls a player: the surname ("Salah", "Van Dijk"), or the one name a player goes by. */
    public function surname(?string $name): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return null;
        }
        $last = array_pop($parts);
        while ($parts && in_array(Str::lower(end($parts)), self::PARTICLES, true)) {
            $last = Str::ucfirst(array_pop($parts)).' '.$last;
        }

        return $last;
    }

    private function word(int $n): string
    {
        return self::NUMBERS[$n] ?? (string) $n;
    }
}
