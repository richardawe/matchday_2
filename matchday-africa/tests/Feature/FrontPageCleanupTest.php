<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Player;
use App\Models\SocialVideo;
use App\Models\Team;
use App\Services\NewsImages;
use App\Support\VideoText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * After the live front page: clean video text (no raw YouTube descriptions), no pictures from source
 * sites on news, and no picture twice on the homepage.
 */
class FrontPageCleanupTest extends TestCase
{
    use RefreshDatabase;

    private array $dirs = [];

    private const DESCRIPTION = "Mohamed Salah's road from Nagrig to Anfield, told in four minutes. We follow every goal of his first season. This third sentence is too much for a summary.\n\n"
        ."Watch the full story: https://www.youtube.com/watch?v=abc123&list=PLverylonglistidentifier\n"
        ."#Salah #Liverpool #AfricanFootball @matchdayafrica\n\n"
        ."Photo: Jane Doe / Wikimedia Commons / CC BY-SA 4.0\n\n"
        .'This video is written with AI and voiced using Kokoro, with synthesised music.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 12:00:00');
        config(['home.v2' => true, 'app.url' => 'http://localhost']);
        Storage::fake('public');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function video(string $title, int $minutesAgo, string $description = self::DESCRIPTION, string $poster = 'poster-bytes'): SocialVideo
    {
        $v = SocialVideo::create(['title' => $title, 'type' => 'documentary', 'status' => 'approved', 'duration_seconds' => 240,
            'video_path' => 'video.mp4', 'poster_path' => 'poster.jpg', 'description' => $description]);
        $v->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        File::ensureDirectoryExists($this->dirs[] = $v->directory());
        File::put($v->publicVideoPath(), 'x');
        File::put($v->publicPosterPath(), $poster);

        return $v;
    }

    private function article(string $title, int $minutesAgo, ?string $image, array $metadata = []): Blog
    {
        return Blog::create(['title' => $title, 'slug' => Str::slug($title).'-'.Str::random(4), 'excerpt' => 'Short story.', 'content' => 'Body.',
            'status' => 'published', 'published_at' => now()->subMinutes($minutesAgo), 'featured_image' => $image, 'metadata' => $metadata ?: null]);
    }

    // ------------------------------------------------------------------ 1. video text

    public function test_the_summary_is_the_first_sentences_without_links_hashtags_credits_or_the_ai_note(): void
    {
        $parts = VideoText::parts(self::DESCRIPTION);

        $this->assertSame("Mohamed Salah's road from Nagrig to Anfield, told in four minutes. We follow every goal of his first season.", $parts['summary']);
        $this->assertLessThanOrEqual(VideoText::SUMMARY_MAX, mb_strlen($parts['summary']));
        $this->assertSame(['Photo: Jane Doe / Wikimedia Commons / CC BY-SA 4.0'], $parts['credits']);
        $this->assertSame('This video is written with AI and voiced using Kokoro, with synthesised music.', $parts['disclosure']);

        // Our own radio disclosure is recognised too, and one very long sentence is cut at a word.
        $long = VideoText::parts(str_repeat('The Super Eagles keep pressing high and winning the ball back ', 6)."\n\n".config('radio.disclosure'));
        $this->assertLessThanOrEqual(160, mb_strlen($long['summary']));
        $this->assertStringEndsWith('…', $long['summary']);
        $this->assertSame(config('radio.disclosure'), $long['disclosure']);
        $this->assertStringNotContainsString('AI', $long['summary']);
    }

    public function test_the_video_page_shows_the_summary_and_keeps_credits_and_disclosure_in_the_small_print(): void
    {
        $v = $this->video('Salah: the long road', 30);
        $html = $this->get(route('reels.video', $v))->assertOk()->getContent();
        $body = Str::before(Str::after($html, '<h1 id="video-title">'), '</section>');

        preg_match('/<p class="md-reels-now md-video-summary">(.*?)<\/p>/s', $body, $summary);
        $this->assertSame("Mohamed Salah&#039;s road from Nagrig to Anfield, told in four minutes. We follow every goal of his first season.", $summary[1] ?? null);
        foreach (['youtube.com/watch', '#Salah', '@matchdayafrica', 'too much for a summary'] as $raw) {
            $this->assertStringNotContainsString($raw, $body);
        }
        preg_match('/<footer class="md-video-notes">(.*?)<\/footer>/s', $body, $notes);
        $this->assertStringContainsString('Photo: Jane Doe / Wikimedia Commons / CC BY-SA 4.0', $notes[1] ?? '');
        $this->assertStringContainsString('written with AI and voiced using Kokoro', $notes[1] ?? '');
        // The credit and the AI note appear only in the small print, once each.
        $this->assertSame(1, substr_count($body, 'Jane Doe'));
        $this->assertSame(1, substr_count($body, 'voiced using Kokoro'));
        // The meta description is the clean summary too.
        $this->assertStringNotContainsString('youtube.com/watch', Str::before($html, '</head>'));
    }

    public function test_the_homepage_hero_shows_the_clean_summary_only(): void
    {
        $this->video('Salah: the long road', 30);
        $html = $this->get('/')->assertOk()->getContent();
        preg_match('/<section class="md-front-hero md-front-hero--video".*?<\/section>/s', $html, $hero);

        $this->assertNotEmpty($hero);
        $this->assertStringContainsString('told in four minutes. We follow every goal of his first season.', $hero[0]);
        foreach (['https://', '#Salah', 'Photo:', 'Kokoro', 'third sentence'] as $raw) {
            $this->assertStringNotContainsString($raw, $hero[0]);
        }
    }

    public function test_long_words_and_links_wrap_on_a_phone(): void
    {
        $css = File::get(resource_path('css/app.css'));
        $rule = Str::after($css, '/* Long words and links never run off the right edge on a phone');
        foreach (['.md-front .md-front-text', '.md-video-summary', '.md-video-notes', '.md-reels-copy h1', '.md-news-list h3'] as $selector) {
            $this->assertStringContainsString($selector, Str::before($rule, '{'));
        }
        $this->assertStringContainsString('overflow-wrap:anywhere', Str::before(Str::after($rule, '{'), '}'));
    }

    // ------------------------------------------------------------------ 2. news pictures

    public function test_a_source_sites_picture_is_never_used_for_a_new_article_and_the_text_card_stands_in(): void
    {
        $blog = $this->article('Ghana name a new squad for the qualifiers', 5, null, ['automated' => true]);
        app(NewsImages::class)->apply($blog)->save();

        $this->assertStringStartsWith('blog-images/card-'.$blog->id.'-', $blog->featured_image);
        Storage::disk('public')->assertExists($blog->featured_image);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($blog->featured_image));
        $this->assertSame([1200, 675], [$w, $h]);
        $this->assertSame('text_card', $blog->metadata['image_kind']);
        $this->assertFalse(NewsImages::isThirdParty($blog));
    }

    public function test_our_match_card_comes_first_then_a_credited_commons_photo(): void
    {
        $league = League::create(['name' => 'Premier League', 'short_code' => 'PL', 'football_data_id' => 1]);
        $arsenal = Team::create(['name' => 'Arsenal FC']);
        $chelsea = Team::create(['name' => 'Chelsea FC']);
        $match = FootballMatch::withoutEvents(fn () => FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $arsenal->id,
            'away_team_id' => $chelsea->id, 'match_date' => now()->subDay(), 'status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1]));
        $blog = $this->article('Arsenal edge Chelsea in a London derby', 5, null);
        app(NewsImages::class)->apply($blog)->save();
        $this->assertStringStartsWith("/matches/{$match->id}/card.png", $blog->featured_image);
        $this->assertSame(url($blog->featured_image), $blog->imageUrl());

        $this->dirs[] = storage_path('app/player-test');
        File::ensureDirectoryExists(storage_path('app/player-test'));
        File::put(storage_path('app/player-test/iwobi.jpg'), 'jpeg');
        Player::create(['team_id' => $arsenal->id, 'name' => 'Alex Iwobi', 'nationality' => 'Nigeria', 'nationality_code' => 'NGA', 'is_active' => true,
            'metadata' => ['commons_photo' => ['status' => 'ok', 'path' => 'player-test/iwobi.jpg', 'credit' => 'Jane Doe / Wikimedia Commons / CC BY-SA 4.0',
                'source_url' => 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi.jpg']]]);
        $story = $this->article('Alex Iwobi signs a new deal', 5, null);
        app(NewsImages::class)->apply($story)->save();
        $this->assertStringStartsWith('/african-players/', $story->featured_image);
        $this->assertSame('Jane Doe / Wikimedia Commons / CC BY-SA 4.0', $story->metadata['image_credit']);
        $this->get(route('blogs.show', $story))->assertOk()->assertSee('Photo:', false)->assertSee('Jane Doe / Wikimedia Commons / CC BY-SA 4.0');
    }

    public function test_the_backfill_lists_source_site_pictures_on_a_dry_run_then_swaps_them_for_ours(): void
    {
        Storage::disk('public')->put('blog-images/news-7-abc123.jpg', 'bbc-bytes');
        Storage::disk('public')->put('blog-images/admin-upload.jpg', 'ours');
        $bbc = $this->article('BBC story', 50, 'blog-images/news-7-abc123.jpg', ['automated' => true, 'source_image_url' => 'https://ichef.bbci.co.uk/news/1024/getty.jpg']);
        $getty = $this->article('Getty linked story', 40, 'https://media.gettyimages.com/id/123/photo.jpg');
        $reuters = $this->article('Reuters linked story', 30, 'https://cloudfront-us-east-2.images.arcpublishing.com/reuters/abc.jpg');
        $commons = $this->article('Commons story', 20, 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Photo.jpg');
        $upload = $this->article('Admin upload', 10, 'blog-images/admin-upload.jpg');
        $card = $this->article('Our card', 5, '/matches/1/card.png?v=abc');

        $this->artisan('news:replace-source-images', ['--dry-run' => true])
            ->expectsOutputToContain('ichef.bbci.co.uk')
            ->expectsOutputToContain('media.gettyimages.com')
            ->expectsOutputToContain('Dry run: 3 article(s) would change')
            ->assertSuccessful();
        $this->assertSame('blog-images/news-7-abc123.jpg', $bbc->fresh()->featured_image);
        $this->assertSame('https://media.gettyimages.com/id/123/photo.jpg', $getty->fresh()->featured_image);
        $this->assertEmpty(preg_grep('/card-/', Storage::disk('public')->files('blog-images')), 'A dry run draws nothing.');

        $this->artisan('news:replace-source-images')->expectsOutputToContain('Replaced 3 picture(s)')->assertSuccessful();
        foreach ([$bbc, $getty, $reuters] as $blog) {
            $blog = $blog->fresh();
            $this->assertStringStartsWith('blog-images/card-'.$blog->id.'-', $blog->featured_image);
            $this->assertFalse(NewsImages::isThirdParty($blog));
            $this->assertArrayNotHasKey('source_image_url', $blog->metadata);
            $this->assertNotEmpty($blog->metadata['replaced_image']);
        }
        Storage::disk('public')->assertMissing('blog-images/news-7-abc123.jpg');
        Storage::disk('public')->assertExists('blog-images/admin-upload.jpg');
        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/a/ab/Photo.jpg', $commons->fresh()->featured_image);
        $this->assertSame('blog-images/admin-upload.jpg', $upload->fresh()->featured_image);
        $this->assertSame('/matches/1/card.png?v=abc', $card->fresh()->featured_image);
        $this->artisan('news:replace-source-images', ['--dry-run' => true])->expectsOutputToContain('No articles use a picture from another site')->assertSuccessful();
    }

    // ------------------------------------------------------------------ 3. no picture twice

    /** Every picture in the hero, New on Matchday and Latest News. */
    private function homeImages(string $html): array
    {
        $start = strpos($html, 'id="home-hero"');
        $end = strpos($html, '</section>', (int) strpos($html, 'id="home-news"'));
        preg_match_all('/<img[^>]*\ssrc="([^"]+)"|<video[^>]*\sposter="([^"]+)"/', substr($html, $start, $end - $start), $m);

        return array_values(array_filter(array_map(fn ($a, $b) => $a ?: $b, $m[1], $m[2])));
    }

    public function test_the_homepage_never_shows_one_picture_twice(): void
    {
        // The hero video's poster is also its rail item's poster; two articles share one picture address;
        // two more articles have different files with the same bytes.
        $this->video('Salah: the long road', 30);
        $this->article('Shared picture one', 60, 'https://example.com/same.jpg');
        $this->article('Shared picture two', 70, 'https://example.com/same.jpg');
        Storage::disk('public')->put('blog-images/first.jpg', 'same-bytes');
        Storage::disk('public')->put('blog-images/second.jpg', 'same-bytes');
        $this->article('Same bytes one', 80, 'blog-images/first.jpg');
        $this->article('Same bytes two', 90, 'blog-images/second.jpg');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-hero-kind="video"', $html);
        $images = $this->homeImages($html);
        $this->assertNotEmpty($images);
        $keys = array_map(fn ($u) => \App\Support\ImageIdentity::key($u), $images);
        $this->assertSame(count($keys), count(array_unique($keys)), 'Repeated pictures: '.implode(', ', $images));

        // The rail's copy of the hero video gets the fallback card; a repeated article gets its text card.
        $this->assertMatchesRegularExpression('/data-fresh-item="video:\d+">(?:(?!<\/li>).)*data-fallback-card/s', $html);
        $this->assertStringContainsString('/media/blog/card-', $html);
        // The first article keeps its own picture.
        $this->assertStringContainsString('https://example.com/same.jpg', $html);
        // Every news article with a picture shows one, or the CSS card when its picture and its text card are already higher up.
        preg_match_all('/<article data-news-id="\d+"[^>]*>(.*?)<\/article>/s', $html, $news);
        $this->assertCount(4, $news[1]);
        foreach ($news[1] as $item) {
            $this->assertMatchesRegularExpression('/<img |data-fallback-card/', $item);
        }
    }
}
