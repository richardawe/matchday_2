<?php

namespace App\Services;

use App\Models\FootballMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What the reels-first homepage shows: this week's reels (the strongest one featured) and the
 * coming week's fixtures with their crest-free fixture cards.
 */
class HomeShowcase
{
    private const UPCOMING = ['SCHEDULED', 'TIMED'];

    public function __construct(private ReelService $reels, private MatchStoryService $stories) {}

    /**
     * Premier League reels from the last fortnight, newest round first. The featured reel is the
     * strongest from the last seven days (ReelService::weight), so a thrashing or a late winner leads.
     *
     * @return array{featured: ?array, rail: Collection}
     */
    public function reels(int $limit = 12, int $days = 14): array
    {
        $cutoff = now('UTC')->subDays($days)->format('Y-m-d H:i:s');
        $all = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereHas('league', fn ($q) => $q->where('short_code', 'PL')->orWhere('espn_slug', 'eng.1'))
            ->whereIn('status', ['FINISHED', 'FT'])
            ->where('match_date', '>=', $cutoff)
            ->latest('match_date')->get()
            ->filter(fn ($m) => $this->reels->path($m))
            ->map(fn ($m) => $this->reelCard($m))->values();
        if ($all->isEmpty()) {
            return ['featured' => null, 'rail' => collect()];
        }
        $recent = $all->filter(fn ($r) => $r['played']->gt(now()->subDays(7)));
        $featured = ($recent->isNotEmpty() ? $recent : $all)->sortByDesc('weight')->first();

        return ['featured' => $featured, 'rail' => $all->reject(fn ($r) => $r['id'] === $featured['id'])->take($limit)->values()];
    }

    /**
     * The next week's fixtures with fixture cards: Premier League first, then other listed
     * competitions to fill the board.
     */
    public function board(int $limit = 6): Collection
    {
        $utc = now('UTC');
        $listed = count(config('competitions.order', []));

        return FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereIn('status', self::UPCOMING)
            ->whereBetween('match_date', [$utc->format('Y-m-d H:i:s'), $utc->copy()->addDays(7)->format('Y-m-d H:i:s')])
            ->orderBy('match_date')->take(80)->get()
            ->filter(fn ($m) => $m->homeTeam && $m->awayTeam && $m->league && $m->league->competition_rank < $listed)
            ->sortBy(fn ($m) => [$m->league->competition_rank === 0 ? 0 : 1, $m->match_date->timestamp])
            ->take($limit)->values()
            ->map(function (FootballMatch $m) {
                $kickoff = Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone('Africa/Lagos');

                return ['match' => $m, 'card' => route('matches.card', $m), 'kickoff' => $kickoff,
                    'title' => $this->club($m->homeTeam).' v '.$this->club($m->awayTeam)];
            });
    }

    /** One reel for the homepage rail and the reel pages. */
    public function reelCard(FootballMatch $m): array
    {
        $readyAt = data_get($m->metadata, 'reel.ready_at');

        return [
            'id' => $m->id,
            'match' => $m,
            'title' => $this->club($m->homeTeam)." {$m->home_score}–{$m->away_score} ".$this->club($m->awayTeam),
            'video' => app(ReelService::class)->url($m),
            'poster' => $this->reels->posterPath($m) ? route('matches.reel-poster', ['match' => $m, 'v' => $readyAt]) : null,
            'link' => route('matches.show', $m).'#match-story',
            'page' => route('reels.show', $m),
            'played' => Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC'),
            'weight' => $this->reels->weight($m),
        ];
    }

    private function club($team): string
    {
        return trim(preg_replace('/\s+FC$/i', '', (string) $team?->name));
    }
}
