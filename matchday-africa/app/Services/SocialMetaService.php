<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\PredictionSet;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Title, description, canonical URL, Open Graph, Twitter card and structured data for a page.
 * Pages hand their subject to the layout as $content (a match, team, article or prediction
 * round, or an array of overrides); anything else gets the site defaults with its own URL.
 */
class SocialMetaService
{
    private const SITE = 'Matchday Africa';
    private const DESCRIPTION = 'Live scores, 20-second match reels, free prediction leagues, Matchday Radio and football news for African fans.';

    public function getSocialPreviewData($content = null, array $overrides = []): array
    {
        $meta = match (true) {
            $content instanceof FootballMatch => $this->match($content),
            $content instanceof Team => $this->team($content),
            $content instanceof Blog => $this->blog($content),
            $content instanceof PredictionSet => $this->predictionSet($content),
            default => $this->defaults(),
        };
        if (is_array($content)) {
            $overrides = $content + $overrides;
        }
        $meta = array_merge($meta, array_filter($overrides, fn ($v) => $v !== null && $v !== ''));
        $meta['description'] = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $meta['description']))), 160, '…');

        $meta['open_graph'] = array_filter([
            'og:type' => $meta['type'],
            'og:site_name' => self::SITE,
            'og:title' => $meta['title'],
            'og:description' => $meta['description'],
            'og:url' => $meta['url'],
            'og:image' => $meta['image'],
            'og:image:secure_url' => $meta['image'],
            'og:image:width' => $meta['image_width'] ?? null,
            'og:image:height' => $meta['image_height'] ?? null,
            'og:image:alt' => $meta['image_alt'] ?? $meta['title'],
            'og:video' => $meta['video'] ?? null,
            'og:video:type' => isset($meta['video']) ? 'video/mp4' : null,
            'og:video:width' => isset($meta['video']) ? '1080' : null,
            'og:video:height' => isset($meta['video']) ? '1920' : null,
        ] + ($meta['extra_og'] ?? []));
        $meta['twitter_card'] = [
            'twitter:card' => 'summary_large_image',
            'twitter:site' => config('social.accounts.x.handle', '@matchdayafrica'),
            'twitter:title' => $meta['title'],
            'twitter:description' => $meta['description'],
            'twitter:image' => $meta['image'],
            'twitter:image:alt' => $meta['image_alt'] ?? $meta['title'],
        ];

        return $meta;
    }

    private function defaults(): array
    {
        return [
            'title' => self::SITE.': live scores, match reels and free predictions',
            'description' => self::DESCRIPTION,
            'url' => url()->current(),
            'type' => 'website',
            'image' => route('images.social-default', ['v' => '20260823']),
            'image_width' => '1200', 'image_height' => '630',
            'robots' => 'index, follow',
            'json_ld' => request()->routeIs('home') ? $this->siteJsonLd() : null,
        ];
    }

    private function match(FootballMatch $match): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        $home = $this->club($match->homeTeam);
        $away = $this->club($match->awayTeam);
        $league = $match->league?->name ?? 'Football';
        // matches.match_date holds UTC wall-clock values.
        $kickoff = $match->match_date ? Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC') : null;
        $local = $kickoff?->copy()->setTimezone('Africa/Lagos');
        $date = $local ? $local->format('j M Y') : 'date TBC';
        $finished = $match->isFinished();
        $live = in_array($match->status, FootballMatch::LIVE_STATUSES, true);

        if ($finished) {
            $title = "{$home} {$match->home_score}–{$match->away_score} {$away}: {$league} result, {$date}";
            $verdict = $match->home_score === $match->away_score ? "{$home} and {$away} drew {$match->home_score}–{$match->away_score}"
                : ($match->home_score > $match->away_score ? "{$home} beat {$away} {$match->home_score}–{$match->away_score}" : "{$away} won {$match->away_score}–{$match->home_score} at {$home}");
            $description = "{$verdict} in the {$league} on {$date}. Goals, stats, the 20-second reel and how fans predicted it.";
        } elseif ($live) {
            $title = "{$home} v {$away} live: {$match->home_score}–{$match->away_score}, {$league}";
            $description = "Follow {$home} v {$away} live in the {$league}: score, goals, cards and the fan room, updated every minute.";
        } else {
            $title = "{$home} v {$away} prediction and live score, {$league}, ".($local ? $local->format('D j M Y') : $date);
            $description = "{$home} v {$away} in the {$league}".($local ? ', '.$local->format('D j M Y, H:i').' WAT' : '')
                .($match->venue_name ? " at {$match->venue_name}" : '').'. Predict the score free, then follow it live.';
        }

        $moment = request()->query('moment');
        $image = $this->matchImage($match);

        return [
            'title' => $title.' | '.self::SITE,
            'description' => $description,
            'url' => route('matches.show', $match),
            'type' => 'website',
            'image' => $image,
            'image_width' => '1200', 'image_height' => $moment ? '630' : '675',
            'image_alt' => "{$home} v {$away}, {$league}",
            'robots' => 'index, follow',
            'json_ld' => $this->sportsEvent($match, $home, $away, $league, $kickoff, $description, $image),
        ];
    }

    /** schema.org SportsEvent for match pages (Google event rich results). */
    private function sportsEvent(FootballMatch $match, string $home, string $away, string $league, ?Carbon $kickoff, string $description, string $image): array
    {
        $status = match (strtoupper((string) $match->status)) {
            'POSTPONED' => 'https://schema.org/EventPostponed',
            'CANCELLED', 'CANCELED' => 'https://schema.org/EventCancelled',
            default => 'https://schema.org/EventScheduled',
        };
        $team = fn ($model, string $name) => array_filter(['@type' => 'SportsTeam', 'name' => $name,
            'url' => $model ? route('teams.show', $model) : null, 'logo' => $model?->logo ?: null]);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'SportsEvent',
            'name' => "{$home} v {$away}",
            'description' => $description,
            'sport' => 'Soccer',
            'url' => route('matches.show', $match),
            'image' => [$image],
            'startDate' => $kickoff?->toIso8601String(),
            'endDate' => $kickoff?->copy()->addMinutes(110)->toIso8601String(),
            'eventStatus' => $status,
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => array_filter(['@type' => 'Place', 'name' => $match->venue_name ?: "{$home} home ground",
                'address' => $match->venue_city ?: ($match->homeTeam?->venue_city ?: null)]),
            'homeTeam' => $team($match->homeTeam, $home),
            'awayTeam' => $team($match->awayTeam, $away),
            'competitor' => [$team($match->homeTeam, $home), $team($match->awayTeam, $away)],
            'superEvent' => $match->league ? ['@type' => 'SportsEvent', 'name' => $league] : null,
            'organizer' => $match->league ? ['@type' => 'SportsOrganization', 'name' => $league, 'url' => route('leagues.show', $match->league)] : null,
        ]);
    }

    private function matchImage(FootballMatch $match): string
    {
        // A shared chapter link (?moment=) previews that chapter's card.
        $stories = app(MatchStoryService::class);
        if (request()->query('moment') && $stories->ready()) {
            $chapter = $stories->chapters($match)->firstWhere('id', (int) request()->query('moment'));
            if ($chapter) {
                return $chapter->cardUrl(Griot::voiceFor(request()), 'og');
            }
        }

        // Otherwise the match card (fixture before, result after); the version changes with the
        // score so link previews refresh at full time.
        return route('matches.card', ['match' => $match, 'v' => substr(md5($match->status.'|'.$match->home_score.'|'.$match->away_score), 0, 8)]);
    }

    private function team(Team $team): array
    {
        $team->loadMissing('league');
        $name = $this->club($team);
        $league = $team->league?->name;

        return [
            'title' => "{$name}: fixtures, results and live scores | ".self::SITE,
            'description' => "{$name}".($league ? " ({$league})" : '').': upcoming fixtures, results, live scores, squad and form. Predict their next match free on Matchday Africa.',
            'url' => route('teams.show', $team),
            'type' => 'website',
            'image' => route('images.social-default', ['v' => '20260823']),
            'image_width' => '1200', 'image_height' => '630',
            'robots' => 'index, follow',
            'json_ld' => array_filter(['@context' => 'https://schema.org', '@type' => 'SportsTeam', 'name' => $team->name, 'sport' => 'Soccer',
                'url' => route('teams.show', $team), 'logo' => $team->logo ?: null,
                'memberOf' => $league ? ['@type' => 'SportsOrganization', 'name' => $league] : null]),
        ];
    }

    private function blog(Blog $blog): array
    {
        $description = $blog->excerpt ?: Str::limit(strip_tags((string) $blog->content), 160);
        $image = $blog->featured_image ? $blog->featured_image_url : route('images.social-default', ['v' => '20260823']);

        return [
            'title' => $blog->title.' | '.self::SITE,
            'description' => $description,
            'url' => route('blogs.show', $blog),
            'type' => 'article',
            'image' => $image,
            'image_width' => $blog->featured_image ? null : '1200',
            'image_height' => $blog->featured_image ? null : '630',
            'robots' => 'index, follow',
            'extra_og' => array_filter([
                'article:published_time' => $blog->published_at?->toIso8601String(),
                'article:modified_time' => $blog->updated_at?->toIso8601String(),
                'article:author' => $blog->author_name,
            ]),
            'json_ld' => array_filter(['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => Str::limit($blog->title, 110, ''),
                'description' => Str::limit(strip_tags((string) $description), 300), 'image' => [$image],
                'datePublished' => $blog->published_at?->toIso8601String(), 'dateModified' => $blog->updated_at?->toIso8601String(),
                'author' => ['@type' => $blog->author_name ? 'Person' : 'Organization', 'name' => $blog->author_name ?: self::SITE],
                'publisher' => ['@type' => 'Organization', 'name' => self::SITE, 'logo' => ['@type' => 'ImageObject', 'url' => asset('favicon.png')]],
                'mainEntityOfPage' => route('blogs.show', $blog)]),
        ];
    }

    private function predictionSet(PredictionSet $set): array
    {
        return [
            'title' => "{$set->name}: football predictions | ".self::SITE,
            'description' => "Make your calls for {$set->name}. Free football predictions: score points, climb the weekly table and beat your friends' league.",
            'url' => route('predictions.show', $set),
            'type' => 'website',
            'image' => route('images.social-default', ['v' => '20260823']),
            'image_width' => '1200', 'image_height' => '630',
            'robots' => 'noindex, follow',
        ];
    }

    private function siteJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', '@id' => url('/').'#org', 'name' => self::SITE, 'url' => url('/'), 'logo' => asset('favicon.png'),
                    'sameAs' => array_values(array_map(fn ($a) => $a['url'], config('social.accounts', [])))],
                ['@type' => 'WebSite', 'name' => self::SITE, 'url' => url('/'), 'publisher' => ['@id' => url('/').'#org']],
            ],
        ];
    }

    private function club(?Team $team): string
    {
        return trim(preg_replace('/\s+FC$/i', '', (string) $team?->name)) ?: 'TBC';
    }
}
