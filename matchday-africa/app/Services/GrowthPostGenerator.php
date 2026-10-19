<?php
namespace App\Services;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\GrowthPost;
use App\Support\XText;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Plans the social timeline into growth_posts, one row per subject and channel (content_key), so re-running is
 * harmless: every new article, a preview of each top fixture before kick-off, the day's
 * fixtures in the morning and the results at night. Queued when config('buffer.x.auto') is on
 * (buffer:publish sends them), drafts for the admin page otherwise.
 *
 * Nobody reads these before they go out, so they only state what the site's own data says:
 * teams, competitions, kick-offs, scores and article headlines. Generated preview prose is not
 * quoted (its head-to-head lines can be invented), and article cover photos, which come from
 * other publishers, are not attached; X shows the article's link card instead.
 *
 * Kick-offs: matches.match_date holds UTC wall-clock values (AGENTS.md rule 5).
 */
class GrowthPostGenerator
{
    private const NOT_UPCOMING = ['FINISHED', 'AWARDED', 'POSTPONED', 'CANCELLED', 'SUSPENDED', 'IN_PLAY', 'PAUSED', 'LIVE', '1H', 'HT', '2H', 'ET', 'FT'];

    public function generate(): int
    {
        return $this->news() + $this->previews() + $this->fixtures() + $this->results();
    }

    private function news(): int
    {
        $created = 0;
        Blog::published()->where('published_at', '>=', now()->subHours(config('buffer.x.news_max_age_hours')))->oldest('published_at')->get()
            ->each(function (Blog $blog) use (&$created) {
                $excerpt = Str::squish(strip_tags((string) $blog->excerpt));
                $text = XText::compose(trim("{$blog->title}\n\n{$excerpt}"), route('blogs.show', $blog), '#FootballNews '.config('buffer.x.hashtags'));
                $created += $this->plan("news:{$blog->id}", 'news', $text, null, route('blogs.show', $blog), now(), $blog->published_at->copy()->addHours(12));
            });

        return $created;
    }

    private function previews(): int
    {
        $now = now('UTC');
        $lead = (int) config('buffer.x.preview_lead_minutes');
        $created = 0;
        foreach ($this->topFixtures($this->lagos()->startOfDay(), $this->lagos()->addDay()->endOfDay())->groupBy(fn ($m) => $this->kickoff($m)->setTimezone(config('buffer.x.timezone'))->toDateString()) as $day) {
            foreach ($day->take((int) config('buffer.x.previews_per_day')) as $match) {
                $kickoff = $this->kickoff($match);
                $send = $kickoff->copy()->subMinutes($lead)->max($now);
                $expires = $kickoff->copy()->subMinutes(10);
                if ($kickoff->gt($now->copy()->addDay()) || $send->gte($expires)) {
                    continue;
                }
                $local = $kickoff->copy()->setTimezone(config('buffer.x.timezone'));
                $body = $this->name($match->homeTeam).' v '.$this->name($match->awayTeam)."\n"
                    .($match->league?->name ?: 'Football').' · Kick-off '.$local->format('H:i').' WAT'
                    ."\n\nPreview, team news and stats. Who wins? Call it 👇";
                $link = route('matches.show', $match);
                $text = XText::compose($body, $link, $this->tags($match));
                $created += $this->plan("preview:{$match->id}", 'preview', $text, route('matches.card', $match), $link, $send, $expires);
            }
        }

        return $created;
    }

    private function fixtures(): int
    {
        $today = $this->lagos();
        $at = $today->copy()->setTimeFromTimeString(config('buffer.x.fixtures_at'));
        if ($today->lt($at) || $today->hour >= 12) {
            return 0;
        }
        $matches = $this->topFixtures($today->copy()->startOfDay(), $today->copy()->endOfDay())
            ->filter(fn ($m) => $this->kickoff($m)->gt(now('UTC')))->take(6);
        if ($matches->isEmpty()) {
            return 0;
        }
        $lines = $matches->sortBy(fn ($m) => $this->kickoff($m)->timestamp)
            ->map(fn ($m) => $this->kickoff($m)->setTimezone(config('buffer.x.timezone'))->format('H:i').'  '.$this->name($m->homeTeam).' v '.$this->name($m->awayTeam));
        $body = 'Today\'s big games ('.$today->format('D j M').', WAT) ⚽'."\n\n".$lines->implode("\n");

        return $this->plan('fixtures:'.$today->toDateString(), 'fixtures', XText::compose($body, route('matches.index'), config('buffer.x.hashtags')),
            null, route('matches.index'), $at, $today->copy()->setTime(12, 0));
    }

    private function results(): int
    {
        $today = $this->lagos();
        $at = $today->copy()->setTimeFromTimeString(config('buffer.x.results_at'));
        if ($today->lt($at)) {
            return 0;
        }
        $matches = $this->topFixtures($today->copy()->startOfDay(), $today->copy()->endOfDay(), finished: true)->take(8);
        if ($matches->isEmpty()) {
            return 0;
        }
        $lines = $matches->map(fn ($m) => $this->name($m->homeTeam)." {$m->home_score}–{$m->away_score} ".$this->name($m->awayTeam));
        $body = 'Full-time across the day ('.$today->format('D j M').') 🏁'."\n\n".$lines->implode("\n");

        return $this->plan('results:'.$today->toDateString(), 'results', XText::compose($body, route('matches.index'), config('buffer.x.hashtags')),
            null, route('matches.index'), $at, $today->copy()->addDay()->setTime(3, 0));
    }

    /** Fixtures in listed competitions kicking off between two Lagos times, most important first. */
    private function topFixtures(Carbon $from, Carbon $to, bool $finished = false): Collection
    {
        $listed = count(config('competitions.order', []));
        $utc = fn (Carbon $t) => $t->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

        return FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereBetween('match_date', [$utc($from), $utc($to)])
            ->when($finished, fn ($q) => $q->where('status', 'FINISHED')->whereNotNull('home_score')->whereNotNull('away_score'),
                fn ($q) => $q->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', self::NOT_UPCOMING)))
            ->get()
            ->filter(fn ($m) => $m->homeTeam && $m->awayTeam && $m->league && $m->league->competition_rank < $listed)
            ->sortBy(fn ($m) => [$m->league->competition_rank, $this->kickoff($m)->timestamp])
            ->values();
    }

    /** One row per channel that can carry it: X takes everything, Instagram needs an image. */
    private function plan(string $subject, string $type, string $text, ?string $image, ?string $url, Carbon $at, Carbon $expires): int
    {
        if ($expires->isPast()) {
            return 0;
        }
        $appTz = config('app.timezone');
        $created = 0;
        foreach (['x' => true, 'instagram' => $image && config('buffer.channels.instagram')] as $channel => $carries) {
            $key = $channel === 'x' ? $subject : "{$subject}@{$channel}";
            if (!$carries || GrowthPost::where('content_key', $key)->exists()) {
                continue;
            }
            GrowthPost::create([
                'content_key' => $key, 'channel' => $channel, 'type' => $type, 'message' => $text, 'image_url' => $image, 'destination_url' => $url,
                'status' => config('buffer.x.auto') ? 'queued' : 'draft',
                'scheduled_at' => $at->copy()->setTimezone($appTz), 'expires_at' => $expires->copy()->setTimezone($appTz),
            ]);
            $created++;
        }

        return $created;
    }

    private function tags(FootballMatch $match): string
    {
        $tags = config('buffer.x.competition_tags');
        $tag = $tags[$match->league?->espn_slug] ?? $tags[$match->league?->short_code] ?? null;

        return trim(($tag ? "{$tag} " : '').config('buffer.x.hashtags'));
    }

    private function kickoff(FootballMatch $match): Carbon
    {
        return Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
    }

    private function lagos(): Carbon
    {
        return now(config('buffer.x.timezone'));
    }

    private function name($team): string
    {
        return trim(preg_replace('/\s+FC$/i', '', (string) $team?->name));
    }
}
