<?php

namespace App\Services;

use App\Support\AfricanScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Today's in-scope players (AfricanScope) at clubs playing today, who already have today's
 * API-Football line-up or stats. Shared by the home "in focus" block and /start.
 * Each player gets focus_match, focus_stats (today's numbers only) and focus_stat_rows.
 */
class AfricanFocus
{
    /** @param Collection<int, \App\Models\FootballMatch> $todaysMatches */
    public static function today(Collection $todaysMatches, string $today, int $limit = 18): Collection
    {
        $teamIds = $todaysMatches->flatMap(fn ($match) => [$match->home_team_id, $match->away_team_id])->filter()->unique();
        $players = AfricanScope::playersAt($teamIds->all())->sortBy('name')->values()
            ->filter(fn ($player) => data_get($player->metadata, 'api_football_lineup.date') === $today
                || data_get($player->metadata, 'api_football_stats.date') === $today)
            ->take($limit)->values();
        $matchByTeam = $todaysMatches->mapWithKeys(fn ($match) => [$match->home_team_id => $match, $match->away_team_id => $match]);
        $players->each(function ($player) use ($matchByTeam, $today) {
            $player->setAttribute('focus_match', $matchByTeam->get($player->team_id));
            $fresh = data_get($player->metadata, 'api_football_stats.date') === $today;
            $stats = $fresh ? data_get($player->metadata, 'api_football_stats.statistics', []) : [];
            $player->setAttribute('focus_stats', $stats);
            $rows = collect($stats)->flatMap(fn ($values, $group) => is_array($values) ? collect($values)
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->map(fn ($value, $label) => ['label' => Str::headline($group).' · '.Str::headline($label), 'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : $value]) : [])->values();
            $player->setAttribute('focus_stat_rows', $rows);
        });

        return $players;
    }

    /**
     * One real number from today's stats for a short card, or null when the data has none.
     * Goals or assists when scored, else rating, else minutes (a stored zero minutes is shown).
     */
    public static function headline(array $stats): ?string
    {
        $goals = data_get($stats, 'goals.total');
        $assists = data_get($stats, 'goals.assists');
        $rating = data_get($stats, 'games.rating');
        $minutes = data_get($stats, 'games.minutes');

        return match (true) {
            is_numeric($goals) && $goals > 0 => (int) $goals.' '.Str::plural('goal', (int) $goals),
            is_numeric($assists) && $assists > 0 => (int) $assists.' '.Str::plural('assist', (int) $assists),
            is_numeric($rating) => 'Rating '.rtrim(rtrim(number_format((float) $rating, 1), '0'), '.'),
            is_numeric($minutes) => (int) $minutes.' '.Str::plural('minute', (int) $minutes),
            default => null,
        };
    }
}
