<?php

namespace App\Services;

use App\Models\FootballMatch;

class MythGrammarService
{
    public function tell(FootballMatch $match): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'events.team']);
        $home = $match->homeTeam?->name ?? 'The home banner';
        $away = $match->awayTeam?->name ?? 'The visitors';
        $homeScore = $match->home_score ?? 0;
        $awayScore = $match->away_score ?? 0;
        $beats = [];

        foreach ($match->events->sortBy([['minute', 'asc'], ['sort_order', 'asc']]) as $event) {
            $team = $event->team?->name
                ?? ($event->team_id === $match->home_team_id ? $home : ($event->team_id === $match->away_team_id ? $away : 'a banner'));
            $player = $event->player_name ?: null;
            $type = $event->type;
            $subType = strtolower((string) $event->sub_type);
            $text = match (true) {
                in_array($type, ['goal', 'penalty_goal', 'own_goal'], true) => $this->goalText($team, $player, $type, (bool) $event->is_penalty, (bool) $event->is_own_goal),
                $type === 'red_card' || ($type === 'card' && str_contains($subType, 'red')) => $player ? "{$player} is banished from the field; {$team} must hold the line with one fewer." : "A warrior of {$team} is banished from the field.",
                $type === 'yellow_card' || ($type === 'card' && str_contains($subType, 'yellow')) => $player ? "{$player} of {$team} receives the referee's warning." : "{$team} receives the referee's warning.",
                $type === 'substitution' => $this->substitutionText($team, $player, $event->related_player_name),
                default => null,
            };
            if ($text) {
                $beats[] = ['key' => 'event:'.$event->id, 'minute' => $event->minute, 'event' => $type, 'text' => $text];
            }
        }

        if (!$match->events->contains(fn ($event) => in_array($event->type, ['goal', 'penalty_goal', 'own_goal'], true)) && ($homeScore + $awayScore) > 0) {
            $beats[] = ['key' => 'score:'.$homeScore.'-'.$awayScore, 'minute' => $match->minute, 'event' => 'score', 'text' => "The score stands at {$home} {$homeScore}–{$awayScore} {$away}; the provider has not supplied the scorers."];
        }

        [$headline, $ending] = $this->stateText($match, $home, $away, $homeScore, $awayScore);

        return [
            'headline' => $headline,
            'story' => trim(implode(' ', array_column($beats, 'text')).' '.$ending),
            'beats' => array_values($beats),
            'signature' => hash('sha256', json_encode([$match->status, $match->minute, $homeScore, $awayScore, array_column($beats, 'key')])),
        ];
    }

    private function goalText(string $team, ?string $player, string $type, bool $penalty, bool $ownGoal): string
    {
        if ($ownGoal || $type === 'own_goal') return $player ? "A cruel turn of fate — {$player}'s own goal changes the field for {$team}." : "A cruel own goal changes the field for {$team}.";
        $opening = ($penalty || $type === 'penalty_goal') ? 'A siege from the spot breaches the gate' : 'A raid lands';
        return $player ? "{$opening} — {$player} strikes for {$team}." : "{$opening} for {$team}; the scorer is not yet confirmed.";
    }

    private function substitutionText(string $team, ?string $out, ?string $in): string
    {
        if ($out && $in) return "{$team} changes its guard: {$in} enters as {$out} leaves the field.";
        return "{$team} changes its guard for the next passage of play.";
    }

    private function stateText(FootballMatch $match, string $home, string $away, int $homeScore, int $awayScore): array
    {
        if ($match->isFinished()) {
            if ($homeScore === $awayScore) return ['Both banners remain standing', 'At the final horn, neither banner fell. The field was shared.'];
            $winner = $homeScore > $awayScore ? $home : $away;
            return ["{$winner} claims the field", "The final horn sounds for {$winner}, whose line holds when the contest ends."];
        }
        if ($match->status === 'HT' || $match->status === 'PAUSED') return ['The horns fall quiet at the interval', "The banners withdraw briefly with the score at {$homeScore}–{$awayScore}."];
        if (in_array($match->status, FootballMatch::LIVE_STATUSES, true)) {
            if ($homeScore === $awayScore) return ['The field hangs in the balance', "At {$this->minuteLabel($match)}, neither banner commands the field."];
            $leader = $homeScore > $awayScore ? $home : $away;
            return ["{$leader} holds the advantage", "At {$this->minuteLabel($match)}, {$leader} stands ahead — but the contest is not yet written."];
        }
        return ["{$home} and {$away} gather at the gates", 'The field awaits its first confirmed breach.'];
    }

    private function minuteLabel(FootballMatch $match): string
    {
        return $match->minute ? $match->minute.'′' : 'the latest provider update';
    }
}
