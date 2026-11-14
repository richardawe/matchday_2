<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\RadioBulletin;
use App\Models\Slideshow;
use App\Services\Griot\GriotChallengeService;
use App\Support\AfricanScope;
use App\Support\ClubName;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What /start (the social bio landing page) shows: one primary action, today's African players,
 * and a few quieter extras. Every link on the page carries the visitor's UTM tags onward.
 */
class StartPage
{
    public const TAGS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

    public function __construct(private HomeShowcase $showcase) {}

    /** The UTM tags this visit arrived with (only when a source is named, as Attribution requires). */
    public static function tags(Request $request): array
    {
        $tags = [];
        foreach (self::TAGS as $key) {
            $value = trim(strip_tags((string) $request->query($key)));
            if ($value !== '') {
                $tags[$key] = mb_substr($value, 0, 150);
            }
        }

        return isset($tags['utm_source']) ? $tags : [];
    }

    /**
     * $url with the visitor's tags appended where the URL does not already set them. When the visit
     * named a source but no campaign, $campaign fills the gap. Untagged visits get untagged links.
     */
    public static function carry(string $url, array $tags, ?string $campaign = null): string
    {
        if (!$tags) {
            return $url;
        }
        if ($campaign && empty($tags['utm_campaign'])) {
            $tags['utm_campaign'] = $campaign;
        }
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            [$url, $fragment] = [substr($url, 0, $hash), substr($url, $hash)];
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $existing);
        $missing = array_diff_key($tags, $existing);
        if (!$missing) {
            return $url.$fragment;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($missing).$fragment;
    }

    /**
     * The one primary action: an open Beat the Griot round, else the next match with in-scope players
     * (Premier League first), else match alerts by email. kind is griot, match or alerts.
     */
    public function primary(array $tags, ?Collection $upcoming = null): array
    {
        if (GriotChallengeService::ready() && app(GriotChallengeService::class)->open()) {
            return ['kind' => 'griot', 'label' => 'Enter Beat the Griot',
                'line' => 'Call three matches. Beat the Griot\'s points and you are in the draw.',
                'href' => self::carry(route('griot.challenge', [], false), $tags, 'griot')];
        }
        $next = ($upcoming ?? $this->upcoming())->first();
        if ($next) {
            return ['kind' => 'match', 'label' => 'Call the score', 'match' => $next,
                'line' => ClubName::full($next->homeTeam).' v '.ClubName::full($next->awayTeam).', '.$next->kickoffLabel().'. No account needed to make your call.',
                'href' => self::carry(route('matches.show', $next, false).'#prediction-arena', $tags, 'predictions')];
        }

        return ['kind' => 'alerts', 'label' => 'Get match alerts', 'line' => 'Weekend fixtures on Friday, results on Monday. Just your email.', 'href' => null];
    }

    /** Today's in-scope players with today's line-up or stats (the home focus query), short. */
    public function players(int $limit = 6): Collection
    {
        $now = now();
        $today = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereBetween('match_date', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->orderBy('match_date')->get();

        return AfricanFocus::today($today, $now->toDateString(), $limit);
    }

    /**
     * Upcoming matches in the next $days with at least one in-scope player in either squad,
     * Premier League first, then by kick-off. Each gets african_players (names, up to three).
     */
    public function upcoming(int $days = 14, int $limit = 4): Collection
    {
        $now = now('UTC');
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereIn('status', PlayerPages::UPCOMING)
            ->where('match_date', '>', $now->format('Y-m-d H:i:s'))
            ->where('match_date', '<=', $now->copy()->addDays($days)->format('Y-m-d H:i:s'))
            ->orderBy('match_date')->limit(300)->get()
            ->filter(fn ($m) => $m->homeTeam && $m->awayTeam);
        if ($matches->isEmpty()) {
            return collect();
        }
        $byTeam = AfricanScope::playersAt($matches->flatMap(fn ($m) => [$m->home_team_id, $m->away_team_id])->unique()->all())
            ->groupBy('team_id');

        return $matches
            ->filter(fn ($m) => $byTeam->has($m->home_team_id) || $byTeam->has($m->away_team_id))
            ->sortBy(fn ($m) => [($m->league?->competition_rank ?? PHP_INT_MAX) === 0 ? 0 : 1, $m->match_date->timestamp])
            ->take($limit)->values()
            ->each(fn ($m) => $m->setAttribute('african_players', collect([$m->home_team_id, $m->away_team_id])
                ->flatMap(fn ($id) => $byTeam->get($id, collect()))->sortBy('name')->pluck('name')->take(3)->values()));
    }

    /** The newest playable radio edition, or null. */
    public function radio(): ?RadioBulletin
    {
        return RadioBulletin::available() ? RadioBulletin::latestPlayable() : null;
    }

    /** A couple of approved slideshows. */
    public function slideshows(int $limit = 2): Collection
    {
        return Slideshow::ready() ? Slideshow::published()->latest('approved_at')->limit($limit)->get() : collect();
    }

    /** A couple of recent reels (cards from HomeShowcase). */
    public function reels(int $limit = 2): Collection
    {
        $list = $this->showcase->reels($limit);

        return collect([$list['featured']])->filter()->concat($list['rail'])->take($limit)->values();
    }
}
