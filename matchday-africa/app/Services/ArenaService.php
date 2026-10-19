<?php

namespace App\Services;

use App\Models\FootballMatch;
use Carbon\Carbon;

/** The arena (home page today, the War section's arena page with the new homepage). */
class ArenaService
{
    /**
     * The arena's headline Premier League match: live first, then the next kick-off within three
     * days, then the latest result. Only matches where both clubs have a crest-free army.
     */
    public function headline(Carbon $now): ?array
    {
        $stories = app(\App\Services\MatchStoryService::class);
        $relations = ['homeTeam', 'awayTeam', 'league'];
        $epl = fn ($q) => $q->where('short_code', 'PL')->orWhere('espn_slug', 'eng.1');
        $pick = fn ($query) => $query->with($relations)->whereHas('league', $epl)->get()->first(fn ($m) => $stories->tellsStory($m));
        $utc = now('UTC');

        $match = $pick(FootballMatch::crediblyLive($now)->orderBy('match_date'));
        $state = 'live';
        if (!$match) {
            $match = $pick(FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])->whereBetween('match_date', [$utc, $utc->copy()->addDays(3)])->orderBy('match_date'));
            $state = 'upcoming';
        }
        if (!$match) {
            $match = $pick(FootballMatch::whereIn('status', ['FINISHED', 'FT'])->where('match_date', '>=', $utc->copy()->subDay())->orderByDesc('match_date'));
            $state = 'finished';
        }
        if (!$match) {
            return null;
        }
        $home = \App\Support\Armies::for($match->homeTeam);
        $away = \App\Support\Armies::for($match->awayTeam);
        $reel = $state === 'finished' && app(\App\Services\ReelService::class)->path($match) ? route('matches.reel', $match) : null;

        return [
            'match' => $match, 'state' => $state, 'home' => $home, 'away' => $away, 'reel' => $reel,
            'label' => match ($state) { 'live' => $match->live_label, 'upcoming' => 'KICK-OFF '.$match->match_date->format('D H:i'), default => 'FULL TIME' },
            // match_date holds UTC wall-clock time; the countdown needs a true UTC instant.
            'kickoff' => Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC')->toIso8601String(),
        ];
    }
}
