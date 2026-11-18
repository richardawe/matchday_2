<?php

namespace App\Support;

use App\Models\FootballMatch;
use App\Models\MatchEvent;
use Illuminate\Support\Collection;

/**
 * A match's substitutions with the direction checked against the match itself. Providers disagree on
 * which name is the player coming on (football-data.org and our ESPN mapper store the player going off
 * as player_name; ESPN rows saved before 18 Nov 2026 and API-Football may have it the other way round),
 * so the stored order is never trusted on its own. Evidence, in chronological order:
 *  - the line-up (api_football_lineups or espn_lineups in the match metadata): a starter is on the pitch
 *    until a verified substitution takes them off; a named substitute is on the bench until one brings them on;
 *  - an earlier goal, assist or card (strictly before the minute): that player was on the pitch;
 *  - a later goal, assist or card: that player was not the one who went off.
 * A substitution is kept only when the evidence points one way; anything unknown or contradictory is
 * returned with verified = false and must not be shown.
 */
final class Substitutions
{
    /**
     * @return Collection<int, array{minute: int, extra: ?int, label: string, team_id: ?int, on: ?string, off: ?string, verified: bool}>
     */
    public static function oriented(FootballMatch $match): Collection
    {
        $events = MatchEvent::where('match_id', $match->id)->orderBy('minute')->orderBy('extra_minute')->orderBy('sort_order')->orderBy('id')->get();
        $lineups = data_get($match->metadata, 'api_football_lineups') ?: data_get($match->metadata, 'espn_lineups') ?: [];
        $starters = collect($lineups)->flatMap(fn ($side) => collect($side['starters'] ?? [])->pluck('name'))->filter()->values();
        $bench = collect($lineups)->flatMap(fn ($side) => collect($side['substitutes'] ?? [])->pluck('name'))->filter()->values();
        $in = fn (Collection $names, string $name) => $names->contains(fn ($n) => PlayerNames::same((string) $n, $name));
        $time = fn (MatchEvent $e) => (int) $e->minute * 100 + (int) $e->extra_minute;
        $actions = $events->filter(fn ($e) => in_array($e->type, ['goal', 'yellow_card', 'red_card', 'second_yellow'], true))
            ->flatMap(fn ($e) => collect([[$e->player_name, $time($e)], [$e->type === 'goal' ? $e->assist_player_name : null, $time($e)]])
                ->filter(fn ($a) => trim((string) $a[0]) !== '' && !($e->type === 'goal' && $e->is_own_goal && $a[0] === $e->player_name)));
        $cameOn = collect();
        $wentOff = collect();

        return $events->where('type', 'substitution')->values()->map(function (MatchEvent $e) use ($in, $starters, $bench, $actions, $time, &$cameOn, &$wentOff) {
            $at = $time($e);
            $names = [trim((string) $e->player_name), trim((string) $e->related_player_name)];
            $row = ['minute' => (int) $e->minute, 'extra' => $e->extra_minute ? (int) $e->extra_minute : null,
                'label' => $e->minute.($e->extra_minute ? '+'.$e->extra_minute : '')."'", 'team_id' => $e->team_id, 'on' => null, 'off' => null, 'verified' => false];
            if ($names[0] === '' || $names[1] === '' || PlayerNames::same($names[0], $names[1])) {
                return $row;
            }
            // +1: on the pitch before this minute (so going off); -1: not on the pitch (so coming on).
            $vote = function (string $name) use ($in, $starters, $bench, $actions, $at, $cameOn, $wentOff): array {
                $votes = [];
                if ($in($wentOff, $name)) {
                    $votes[] = -1;
                } elseif ($in($cameOn, $name)) {
                    $votes[] = 1;
                } elseif ($in($starters, $name)) {
                    $votes[] = 1;
                } elseif ($in($bench, $name)) {
                    $votes[] = -1;
                }
                $mine = $actions->filter(fn ($a) => PlayerNames::same((string) $a[0], $name));
                if ($mine->contains(fn ($a) => $a[1] < $at)) {
                    $votes[] = 1;
                }
                if ($mine->contains(fn ($a) => $a[1] > $at)) {
                    $votes[] = -1; // still playing afterwards: did not go off here
                }

                return array_values(array_unique($votes));
            };
            [$a, $b] = [$vote($names[0]), $vote($names[1])];
            // Each name's evidence must agree with itself, and the two must point opposite ways.
            if (count($a) > 1 || count($b) > 1 || (!$a && !$b) || ($a && $b && $a[0] === $b[0])) {
                return $row;
            }
            $firstOff = $a ? $a[0] === 1 : $b[0] === -1;
            [$off, $on] = $firstOff ? $names : array_reverse($names);
            $cameOn->push($on);
            $wentOff->push($off);

            return ['on' => $on, 'off' => $off, 'verified' => true] + $row;
        });
    }
}
