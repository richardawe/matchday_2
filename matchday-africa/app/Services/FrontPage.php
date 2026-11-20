<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\BreakingNews;
use App\Models\FootballMatch;
use App\Models\Player;
use App\Models\RadioBulletin;
use App\Models\Slideshow;
use App\Models\SocialVideo;
use App\Support\AfricanScope;
use App\Support\AfricanStories;
use App\Support\StreamLink;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The live front page (home-v2): one hero chosen in priority order, the "New on Matchday" rail of
 * the newest things of every kind, and the African-first news list. Every fragment is cached for
 * at most CACHE_SECONDS, so a new video, article, radio edition or breaking item shows within a
 * minute without a deploy; the pulse (/home/pulse) sends the hero and the rail again when they change.
 */
class FrontPage
{
    public const CACHE_SECONDS = 45;

    /** The article fragments, so a newly published or unpublished article shows (or goes) at once. */
    public static function forgetArticles(): void
    {
        Cache::forget('front-page:hero-article');
        foreach ([6, 10] as $limit) {
            Cache::forget('front-page:fresh:'.$limit);
            Cache::forget('front-page:news:'.$limit);
        }
    }

    /** Radio and streams count as "on air" for this long after they are published. */
    public const ON_AIR_HOURS = 3;

    /** Rail items this fresh carry a "New" badge. */
    public const NEW_HOURS = 12;

    /**
     * Live matches in hero order: African competitions and national teams first, then matches with
     * African (or confirmed African-descent) players, then the rest; each group by competition rank
     * (config/competitions.php), then kick-off.
     */
    public function orderLive(Collection $live): Collection
    {
        if ($live->isEmpty()) {
            return $live;
        }
        $teamIds = $live->flatMap(fn ($m) => [$m->home_team_id, $m->away_team_id])->filter()->unique()->values()->all();
        try {
            $withPlayers = AfricanScope::scope(Player::query()->active()->whereIn('team_id', $teamIds))->distinct()->pluck('team_id')->flip();
        } catch (\Throwable) {
            $withPlayers = collect();
        }
        $tier = function (FootballMatch $m) use ($withPlayers): int {
            if ($m->league?->region === 'africa' || AfricanScope::nationForTeam($m->homeTeam?->name) || AfricanScope::nationForTeam($m->awayTeam?->name)) {
                return 0;
            }

            return $withPlayers->has($m->home_team_id) || $withPlayers->has($m->away_team_id) ? 1 : 2;
        };

        return $live->sortBy([
            fn ($a, $b) => $tier($a) <=> $tier($b),
            fn ($a, $b) => ($a->league?->competition_rank ?? PHP_INT_MAX) <=> ($b->league?->competition_rank ?? PHP_INT_MAX),
            fn ($a, $b) => $a->match_date <=> $b->match_date,
        ])->values();
    }

    /**
     * The one hero, never empty: live match > stream or radio on air (last 3 hours) > breaking news >
     * newest long video > newest article > a plain way into the matches.
     *
     * @return array{kind: string, at: ?Carbon}+array<string, mixed>
     */
    public function hero(Collection $liveMatches): array
    {
        if ($liveMatches->isNotEmpty()) {
            return ['kind' => 'live', 'match' => $liveMatches->first(), 'at' => $this->matchUpdated($liveMatches->first())];
        }
        foreach (['stream', 'radio', 'breaking', 'video', 'article'] as $kind) {
            try {
                $hero = $this->{'hero'.ucfirst($kind)}();
            } catch (\Throwable $e) {
                Log::warning('Front page hero failed', ['kind' => $kind, 'error' => $e->getMessage()]);
                $hero = null;
            }
            if ($hero) {
                return $hero + ['kind' => $kind];
            }
        }

        return ['kind' => 'matches', 'at' => null];
    }

    /**
     * A short key for what the hero shows: the page swaps the hero only when this changes (and nothing
     * in it is playing). For a live match it follows every score, clock and status change.
     */
    public static function heroKey(array $hero, Collection $liveMatches): string
    {
        $parts = [$hero['kind']];
        if ($hero['kind'] === 'live') {
            $parts = array_merge($parts, $liveMatches->map(fn ($m) => implode(':', [$m->id, $m->home_score, $m->away_score, $m->status, $m->live_label,
                $m->relationLoaded('events') ? $m->events->count() : '']))->all());
        } else {
            $subject = $hero['bulletin'] ?? $hero['item'] ?? $hero['video'] ?? $hero['blog'] ?? null;
            $parts[] = $subject ? $subject->getKey().':'.$subject->updated_at?->getTimestamp() : ($hero['url'] ?? '');
        }

        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    private function heroStream(): ?array
    {
        return StreamLink::enabled() && StreamLink::onYouTube() ? ['url' => StreamLink::url(), 'at' => now()] : null;
    }

    private function heroRadio(): ?array
    {
        $bulletin = RadioBulletin::latestPlayable();

        return $bulletin && $bulletin->published_at?->gte(now()->subHours(self::ON_AIR_HOURS))
            ? ['bulletin' => $bulletin, 'at' => $bulletin->published_at] : null;
    }

    private function heroBreaking(): ?array
    {
        $item = BreakingNews::current();

        return $item ? ['item' => $item, 'at' => $item->created_at] : null;
    }

    private function heroVideo(): ?array
    {
        if (!SocialVideo::ready()) {
            return null;
        }
        $id = Cache::remember('front-page:hero-video', self::CACHE_SECONDS, fn () => SocialVideo::visible()->latest('created_at')->latest('id')
            ->limit(10)->get()->first(fn (SocialVideo $v) => $v->hasVideo())?->id ?? 0);
        $video = $id ? SocialVideo::find($id) : null;

        return $video && $video->isPublic() && $video->hasVideo() ? ['video' => $video, 'at' => $video->created_at] : null;
    }

    private function heroArticle(): ?array
    {
        $id = Cache::remember('front-page:hero-article', self::CACHE_SECONDS, function () {
            $recent = Blog::published()->whereNotNull('published_at')->latest('published_at')->latest('id')->limit(10)->get();

            return ($recent->first(fn (Blog $b) => $b->imageUrl()) ?? $recent->first())?->id ?? 0;
        });
        $blog = $id ? Blog::find($id) : null;

        return $blog?->isPublished() ? ['blog' => $blog, 'at' => $blog->published_at] : null;
    }

    /**
     * "New on Matchday": the newest items of every kind, newest first: long videos, approved
     * slideshows, radio editions, published articles and live breaking items.
     *
     * @return Collection<int, array{key: string, kind: string, label: string, title: string, url: ?string, image: ?string, at: Carbon, new: bool}>
     */
    public function fresh(int $limit = 10): Collection
    {
        $items = Cache::remember('front-page:fresh:'.$limit, self::CACHE_SECONDS, function () use ($limit) {
            $all = collect();
            foreach (['videos', 'slideshows', 'radio', 'articles', 'breaking'] as $source) {
                try {
                    $all = $all->concat($this->{'fresh'.ucfirst($source)}($limit));
                } catch (\Throwable $e) {
                    Log::warning('Front page rail source failed', ['source' => $source, 'error' => $e->getMessage()]);
                }
            }

            return $all->filter(fn ($i) => $i['at'])->sortByDesc(fn ($i) => $i['at']->getTimestamp())->take($limit)
                ->map(fn ($i) => ['at' => $i['at']->getTimestamp()] + $i)->values()->all();
        });
        $cutoff = now()->subHours(self::NEW_HOURS)->getTimestamp();

        return collect($items)->map(fn ($i) => ['at' => Carbon::createFromTimestamp($i['at']), 'new' => $i['at'] >= $cutoff] + $i);
    }

    /** A short key for the rail's contents: changes when an item arrives, leaves or turns 12 hours old. */
    public static function freshKey(Collection $items): string
    {
        return substr(sha1($items->map(fn ($i) => $i['key'].':'.$i['at']->getTimestamp().':'.(int) $i['new'])->implode('|')), 0, 12);
    }

    private function freshVideos(int $limit): Collection
    {
        if (!SocialVideo::ready()) {
            return collect();
        }

        return SocialVideo::visible()->latest('created_at')->limit($limit)->get()->filter(fn (SocialVideo $v) => $v->hasVideo())
            ->map(fn (SocialVideo $v) => ['key' => "video:{$v->id}", 'kind' => 'video', 'label' => $v->typeLabel(), 'title' => (string) $v->title,
                'url' => $v->pageUrl(), 'image' => $v->posterUrl(), 'portrait' => true, 'at' => $v->created_at]);
    }

    private function freshSlideshows(int $limit): Collection
    {
        if (!Slideshow::ready()) {
            return collect();
        }

        return Slideshow::published()->whereNotNull('approved_at')->latest('approved_at')->limit($limit)->get()->filter(fn (Slideshow $s) => $s->videoUrl())
            ->map(fn (Slideshow $s) => ['key' => "slideshow:{$s->id}", 'kind' => 'slideshow', 'label' => 'Slideshow', 'title' => (string) data_get($s->facts, 'title', $s->title()),
                'url' => $s->pageUrl(), 'image' => $s->posterUrl(), 'portrait' => true, 'at' => $s->approved_at]);
    }

    private function freshRadio(int $limit): Collection
    {
        if (!RadioBulletin::available()) {
            return collect();
        }

        return RadioBulletin::published()->latest('published_at')->limit($limit)->get()
            ->map(fn (RadioBulletin $b) => ['key' => "radio:{$b->id}", 'kind' => 'radio', 'label' => 'Radio', 'title' => (string) $b->title,
                'url' => route('radio.show', $b), 'image' => $b->posterUrl(), 'portrait' => true, 'at' => $b->published_at]);
    }

    private function freshArticles(int $limit): Collection
    {
        return Blog::published()->whereNotNull('published_at')->latest('published_at')->limit($limit)->get()
            ->map(fn (Blog $b) => ['key' => "article:{$b->id}", 'kind' => 'article', 'label' => 'Article', 'title' => (string) $b->title,
                'url' => route('blogs.show', $b), 'image' => $b->imageUrl(), 'portrait' => false, 'at' => $b->published_at]);
    }

    private function freshBreaking(int $limit): Collection
    {
        if (!BreakingNews::ready()) {
            return collect();
        }

        return BreakingNews::live()->latest('created_at')->limit(min($limit, 3))->get()
            ->map(fn (BreakingNews $n) => ['key' => "breaking:{$n->id}", 'kind' => 'breaking', 'label' => 'Breaking', 'title' => (string) $n->headline,
                'url' => $n->link_url, 'image' => $n->image_url, 'portrait' => false, 'at' => $n->created_at]);
    }

    /**
     * Latest news: within the last 7 days when anything was published then (older stories only on a
     * quiet week), African stories first, each group newest first.
     */
    public function news(int $limit = 6): Collection
    {
        $ids = Cache::remember('front-page:news:'.$limit, self::CACHE_SECONDS, function () use ($limit) {
            $query = fn () => Blog::published()->whereNotNull('published_at')->latest('published_at')->latest('id');
            $pool = $query()->where('published_at', '>=', now()->subDays(7))->limit(60)->get();
            if ($pool->isEmpty()) {
                $pool = $query()->limit($limit)->get();
            }
            [$african, $rest] = $pool->partition(fn (Blog $b) => AfricanStories::isAfrican($b));

            return $african->concat($rest)->take($limit)->map(fn (Blog $b) => [$b->id, $african->contains('id', $b->id)])->values()->all();
        });
        $blogs = Blog::whereIn('id', array_column($ids, 0))->get()->keyBy('id');

        return collect($ids)->map(function ($row) use ($blogs) {
            $blog = $blogs->get($row[0]);
            $blog?->setAttribute('is_african', $row[1]);

            return $blog;
        })->filter(fn (?Blog $b) => $b?->isPublished())->values();
    }

    /** "As seen on TikTok": SocialVideos::row(), cached like the rest of the page. */
    public function featuredVideos(): Collection
    {
        try {
            return collect(Cache::remember('front-page:featured-videos', self::CACHE_SECONDS, fn () => app(SocialVideos::class)->row()->all()));
        } catch (\Throwable $e) {
            Log::warning('Front page featured videos failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** When the page last changed: the newest of the hero, the rail and any live match. */
    public function updatedAt(array $hero, Collection $fresh, Collection $liveMatches): ?Carbon
    {
        return collect([$hero['at'] ?? null, $fresh->first()['at'] ?? null])
            ->concat($liveMatches->map(fn ($m) => $this->matchUpdated($m)))
            ->filter()->map(fn ($t) => Carbon::instance($t))->filter(fn (Carbon $t) => $t->lte(now()))
            ->sortByDesc(fn (Carbon $t) => $t->getTimestamp())->first();
    }

    private function matchUpdated(FootballMatch $m): Carbon
    {
        return Carbon::instance($m->last_api_update ?? $m->updated_at ?? now());
    }
}
