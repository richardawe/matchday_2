<?php

namespace App\Services;

use App\Design\Canvas;
use App\Design\Tokens;
use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\Player;
use App\Support\AfricanScope;
use App\Support\ClubName;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures for news articles. We never save a source site's picture (og:image from BBC Sport and the
 * like is usually an agency photo: Getty, Reuters, AP, AFP, PA, often watermarked). In order:
 *   1. our own fixture/result card for a match the story names (both clubs, within 4 days),
 *   2. the licence-checked Wikimedia Commons photo of a player the story names (credit kept in metadata),
 *   3. a branded Matchday text card drawn from the headline (blog-images/card-*.png).
 *
 * featured_image then holds a site path ("/matches/12/card.png", "/african-players/4/photo.jpg") or a
 * blog-images file, never another site's address.
 */
class NewsImages
{
    public const CARD = [1200, 675];

    /** Hosts whose pictures we may show besides our own: Wikimedia's (licence-checked Commons files). */
    private const ALLOWED_HOSTS = ['wikimedia.org', 'wikipedia.org'];

    /**
     * What featured_image should be for this article, and the metadata to merge (credit and source of a
     * Commons photo, which kind of picture it is).
     *
     * $draw = false (a dry run) names the text card without drawing it.
     *
     * @return array{image: string, metadata: array<string, string>}
     */
    public function choose(Blog $blog, bool $draw = true): array
    {
        $text = trim($blog->title.' '.strip_tags((string) $blog->getRawOriginal('excerpt')));
        try {
            if ($match = $this->matchFor($text, $blog->published_at ?? now())) {
                return ['image' => $this->sitePath(FixtureCardRenderer::url($match)), 'metadata' => ['image_kind' => 'match_card', 'image_match_id' => (string) $match->id]];
            }
            if ($photo = $this->playerPhotoFor($text)) {
                return $photo;
            }
        } catch (\Throwable $e) {
            Log::notice('News image lookup failed; using the text card', ['blog_id' => $blog->id, 'error' => $e->getMessage()]);
        }

        return ['image' => $this->cardPath($blog, $draw), 'metadata' => ['image_kind' => 'text_card']];
    }

    /** Sets the article's picture (no save). */
    public function apply(Blog $blog): Blog
    {
        $choice = $this->choose($blog);
        $metadata = (array) $blog->metadata;
        unset($metadata['image_credit'], $metadata['image_source_url'], $metadata['image_match_id'], $metadata['source_image_url']);
        $blog->featured_image = $choice['image'];
        $blog->metadata = $choice['metadata'] + $metadata;

        return $blog;
    }

    /**
     * Whether the article shows a picture taken from another site: a file we downloaded from the source
     * (named news-*, or with metadata source_image_url), or a link to any host other than ours or Wikimedia.
     */
    public static function isThirdParty(Blog $blog): bool
    {
        $image = trim((string) $blog->featured_image);
        if ($image === '') {
            return false;
        }
        if (Str::startsWith($image, ['http://', 'https://', '//'])) {
            return !self::allowedHost((string) parse_url(Str::startsWith($image, '//') ? 'https:'.$image : $image, PHP_URL_HOST));
        }
        if (Str::startsWith($image, '/')) {
            return false;
        }

        return filled(data_get($blog->metadata, 'source_image_url')) || Str::startsWith(basename($image), 'news-');
    }

    /** Where the third-party picture came from, for the dry-run listing. */
    public static function sourceHost(Blog $blog): string
    {
        $image = (string) $blog->featured_image;
        $url = Str::startsWith($image, ['http://', 'https://']) ? $image : (string) data_get($blog->metadata, 'source_image_url');

        return (string) (parse_url($url, PHP_URL_HOST) ?: parse_url((string) data_get($blog->metadata, 'source_url'), PHP_URL_HOST) ?: 'unknown');
    }

    public static function allowedHost(string $host): bool
    {
        $host = Str::lower($host);
        if ($host === '') {
            return false;
        }
        $ours = array_filter([Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST)), 'matchday.africa']);
        foreach (array_merge($ours, self::ALLOWED_HOSTS) as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** The branded text card's public URL (drawn on first use): the fallback whenever a picture can't be used. */
    public function cardUrl(Blog $blog): string
    {
        return route('blogs.image', ['filename' => basename($this->cardPath($blog))]);
    }

    /** blog-images/card-{id}-{hash}.png on the public disk, drawn if missing (unless $draw is false). */
    public function cardPath(Blog $blog, bool $draw = true): string
    {
        $path = 'blog-images/card-'.($blog->id ?: 'new').'-'.substr(sha1($blog->title.'|v1'), 0, 10).'.png';
        $disk = Storage::disk('public');
        if ($draw && !$disk->exists($path)) {
            $img = $this->drawCard((string) $blog->title, $blog->published_at);
            ob_start();
            imagepng($img, null, 6);
            $disk->put($path, (string) ob_get_clean());
            imagedestroy($img);
        }

        return $path;
    }

    /** A 1200x675 card: brand black, the headline in Anton white, a yellow band, the MD mark. */
    public function drawCard(string $title, ?Carbon $at = null): \GdImage
    {
        [$w, $h] = self::CARD;
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, Canvas::col($img, Tokens::colour('black')));
        $pad = 72;
        Canvas::logo($img, 88, $pad, 56);
        $label = Tokens::type('label');
        Canvas::text($img, 'Matchday Africa news'.($at ? ' · '.$at->copy()->timezone('Africa/Lagos')->format('j M Y') : ''), $label['file'], 28, $pad + 112, 112, Tokens::colour('yellow'));

        $lines = Canvas::fitBox($title !== '' ? $title : 'Matchday Africa', 'headline', $w - 2 * $pad, 340, 4);
        $type = Tokens::type('headline');
        $y = 220;
        foreach ($lines as [$line, $px]) {
            $y += (int) round($px * $type['leading']);
            Canvas::text($img, $line, $type['file'], $px, $pad, $y, Tokens::colour('white'));
        }
        Canvas::rect($img, 0, $h - 64, $w, $h, Tokens::colour('yellow'));
        Canvas::text($img, 'matchday.africa', $label['file'], 26, $pad, $h - 22, Tokens::colour('black'));

        return $img;
    }

    /** A match whose two clubs the story names, closest to the story's date (within 4 days). */
    private function matchFor(string $text, Carbon $at): ?FootballMatch
    {
        $haystack = ' '.Str::lower(Str::ascii($text)).' ';
        $mentions = function ($team) use ($haystack): bool {
            foreach (array_unique(array_filter([ClubName::short($team), trim(preg_replace('/\s+(FC|AFC|CF)$/i', '', (string) $team?->name))])) as $name) {
                $name = Str::lower(Str::ascii($name));
                if (mb_strlen($name) >= 4 && preg_match('/(?<![a-z0-9])'.preg_quote($name, '/').'(?![a-z0-9])/', $haystack)) {
                    return true;
                }
            }

            return false;
        };
        $from = $at->copy()->utc()->subDays(4)->format('Y-m-d H:i:s');
        $to = $at->copy()->utc()->addDays(4)->format('Y-m-d H:i:s');

        return FootballMatch::with(['homeTeam', 'awayTeam'])->whereBetween('match_date', [$from, $to])->limit(500)->get()
            ->filter(fn (FootballMatch $m) => $m->homeTeam && $m->awayTeam && $mentions($m->homeTeam) && $mentions($m->awayTeam))
            ->sortBy(fn (FootballMatch $m) => abs($m->match_date->getTimestamp() - $at->getTimestamp()))->first();
    }

    /** The Commons photo (licence-checked, credited) of an in-scope player the story names in full. */
    private function playerPhotoFor(string $text): ?array
    {
        preg_match_all('/\p{Lu}[\p{L}\'’-]+(?:\s+(?:(?:de|da|van|von|el|ben|dos|di|le)\s+)?\p{Lu}[\p{L}\'’-]+){1,2}/u', $text, $m);
        $names = collect($m[0])->map(fn ($n) => trim($n))->unique()->values();
        if ($names->isEmpty()) {
            return null;
        }
        $player = Player::whereIn('name', $names->all())->get()
            ->first(fn (Player $p) => data_get($p->metadata, 'commons_photo.status') === 'ok' && AfricanScope::inScope($p)
                && is_file(storage_path('app/'.data_get($p->metadata, 'commons_photo.path'))));
        if (!$player) {
            return null;
        }
        $photo = (array) data_get($player->metadata, 'commons_photo');

        return ['image' => $this->sitePath(route('discovery.photo', $player)), 'metadata' => array_filter([
            'image_kind' => 'commons_photo', 'image_credit' => (string) ($photo['credit'] ?? ''), 'image_source_url' => (string) ($photo['source_url'] ?? ''),
        ])];
    }

    /** "/path?query" of a URL on this site, so the stored value never pins a host. */
    private function sitePath(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** Articles whose picture came from another site (see isThirdParty), newest first. */
    public function thirdParty(?int $limit = null): Collection
    {
        return Blog::whereNotNull('featured_image')->where('featured_image', '!=', '')->latest('id')->get()
            ->filter(fn (Blog $b) => self::isThirdParty($b))->when($limit, fn ($c) => $c->take($limit))->values();
    }

    /**
     * Swaps a third-party picture for ours. The old file is deleted when it was downloaded from the source
     * and no other article uses it; the old value is kept in metadata (replaced_image).
     */
    public function replace(Blog $blog): Blog
    {
        $old = (string) $blog->featured_image;
        $oldSource = data_get($blog->metadata, 'source_image_url');
        $this->apply($blog);
        $blog->metadata = array_filter(['replaced_image' => $old, 'replaced_source_image_url' => $oldSource, 'image_replaced_at' => now()->toIso8601String()]) + (array) $blog->metadata;
        Blog::withoutEvents(fn () => $blog->save());
        if ($old !== '' && !Str::startsWith($old, ['http://', 'https://', '/']) && !Blog::where('featured_image', $old)->exists()) {
            Storage::disk('public')->delete('blog-images/'.basename($old));
        }

        return $blog;
    }
}
