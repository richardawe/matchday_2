<?php

namespace App\Support;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * The cartoon look's army mood thumbnails (war/army/moods/<art>-<mood>.webp, built by
 * scripts/build-armies.mjs): which army, in which mood, for a match, a Griot post or a club's last
 * result. The same rules as the v2 reel (reel/src/timeline.js): a goal is victory for the scorers and
 * dejection for the others; the woodwork, a big chance, a red card, a goal ruled out or an injury
 * is dejection for that side; level is warrior; full time is victory and defeat.
 * Everything returns null in the classic look or for a club without an army.
 */
class ArmyMoods
{
    public const MOODS = ['warrior', 'victory', 'dejected', 'defeated'];

    /** ['url' => …, 'alt' => "Arsenal's Roman Legion, in victory"] or null. */
    public static function image(Team|string|null $team, string $mood): ?array
    {
        if (!SiteStyle::cartoon() || !in_array($mood, self::MOODS, true)) {
            return null;
        }
        $army = Armies::for($team);
        if (!$army || !$army['art']) {
            return null;
        }
        $file = config('armies.optimised')."/moods/{$army['art']}-{$mood}.webp";
        if (!is_file(public_path($file))) {
            return null;
        }
        $club = trim(preg_replace('/\s+(FC|AFC)$/i', '', $team instanceof Team ? $team->name : (string) $team));
        $words = ['warrior' => 'ready for battle', 'victory' => 'in victory', 'dejected' => 'dejected', 'defeated' => 'defeated'];

        return ['url' => asset($file), 'alt' => "{$club}'s {$army['army']}, {$words[$mood]}"];
    }

    /**
     * Both sides of a match as it stands: before kick-off and when level, warriors; while one side
     * leads, victory and dejection; at full time, victory and defeat.
     *
     * @return array{home: ?array, away: ?array}
     */
    public static function forMatch(FootballMatch $match): array
    {
        $started = $match->home_score !== null && $match->away_score !== null
            && (in_array($match->status, FootballMatch::LIVE_STATUSES, true) || in_array($match->status, ['FINISHED', 'FT'], true));
        $diff = $started ? (int) $match->home_score - (int) $match->away_score : 0;
        $final = in_array($match->status, ['FINISHED', 'FT'], true);
        $mood = fn (int $lead) => $lead > 0 ? 'victory' : ($lead < 0 ? ($final ? 'defeated' : 'dejected') : 'warrior');

        return ['home' => self::image($match->homeTeam, $mood($diff)), 'away' => self::image($match->awayTeam, $mood(-$diff))];
    }

    /**
     * The images beside a Griot timeline post: the side it is about, or both sides for kick-off,
     * half-time and full time.
     *
     * @return array<int, array>
     */
    public static function forPost(GriotPost $post, FootballMatch $match): array
    {
        $lead = (int) $post->home_score - (int) $post->away_score;
        $team = fn (?string $side) => $side === 'away' ? $match->awayTeam : $match->homeTeam;
        $images = match ($post->kind) {
            'goal' => [self::image($team($post->side), 'victory')],
            'red_card', 'var', 'woodwork', 'big_chance', 'injury' => [self::image($team($post->side), 'dejected')],
            'pressure' => [self::image($team($post->side), 'warrior')],
            'kickoff' => [self::image($match->homeTeam, 'warrior'), self::image($match->awayTeam, 'warrior')],
            'half_time' => [self::image($match->homeTeam, $lead > 0 ? 'victory' : ($lead < 0 ? 'dejected' : 'warrior')), self::image($match->awayTeam, $lead < 0 ? 'victory' : ($lead > 0 ? 'dejected' : 'warrior'))],
            'full_time' => [self::image($match->homeTeam, $lead > 0 ? 'victory' : ($lead < 0 ? 'defeated' : 'warrior')), self::image($match->awayTeam, $lead < 0 ? 'victory' : ($lead > 0 ? 'defeated' : 'warrior'))],
            default => [],
        };

        return array_values(array_filter($images));
    }

    /**
     * Each club's mood after its last finished match: won, victory; lost, dejected; drew, warrior.
     * One query for the whole table.
     *
     * @param Collection<int, Team> $teams
     * @return array<int, ?array> team id => image
     */
    public static function afterLastResult(Collection $teams): array
    {
        if (!SiteStyle::cartoon() || $teams->isEmpty()) {
            return [];
        }
        $ids = $teams->pluck('id')->all();
        $last = FootballMatch::whereIn('status', ['FINISHED', 'FT'])
            ->where(fn ($q) => $q->whereIn('home_team_id', $ids)->orWhereIn('away_team_id', $ids))
            ->where('match_date', '>=', now('UTC')->subDays(30)->format('Y-m-d H:i:s'))
            ->orderByDesc('match_date')->get(['id', 'home_team_id', 'away_team_id', 'home_score', 'away_score']);
        $out = [];
        foreach ($teams as $team) {
            $match = $last->first(fn ($m) => $m->home_team_id === $team->id || $m->away_team_id === $team->id);
            $lead = $match ? ((int) $match->home_score - (int) $match->away_score) * ($match->home_team_id === $team->id ? 1 : -1) : 0;
            $out[$team->id] = self::image($team, $lead > 0 ? 'victory' : ($lead < 0 ? 'dejected' : 'warrior'));
        }

        return $out;
    }
}
