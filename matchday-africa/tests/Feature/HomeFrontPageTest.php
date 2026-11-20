<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BreakingNews;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Player;
use App\Models\RadioBulletin;
use App\Models\Slideshow;
use App\Models\SocialVideo;
use App\Models\Team;
use App\Models\YoutubeBroadcast;
use App\Services\FrontPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The live front page (home-v2, App\Services\FrontPage): hero order, New on Matchday, As seen on TikTok, African-first news. */
class HomeFrontPageTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 12:00:00');
        config(['home.v2' => true, 'radio.enabled' => true]);
        Storage::fake('public'); // fallback text cards for repeated pictures (FrontPage::distinctImages)
    }

    protected function tearDown(): void
    {
        File::delete($this->files);
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function heroKind(): string
    {
        Cache::flush();
        preg_match('/id="home-hero" data-hero-kind="([a-z]+)"/', $this->get('/')->assertOk()->getContent(), $m);

        return $m[1] ?? 'none';
    }

    private function liveMatch(string $home = 'Arsenal FC', string $away = 'Chelsea FC', string $code = 'PL'): FootballMatch
    {
        $league = League::firstOrCreate(['short_code' => $code], ['name' => $code === 'PL' ? 'Premier League' : 'Africa Cup of Nations', 'football_data_id' => random_int(1, 99999)]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::create(['name' => $home])->id, 'away_team_id' => Team::create(['name' => $away])->id,
            'match_date' => now()->subMinutes(40), 'status' => 'IN_PLAY', 'home_score' => 2, 'away_score' => 1, 'last_api_update' => now()->subMinute(),
        ]));
    }

    private function radio(int $minutesAgo): RadioBulletin
    {
        $b = RadioBulletin::create(['slot' => now('Africa/Lagos')->format('Y-m-d').'-12', 'title' => 'The midday update', 'status' => 'published', 'stage' => 'audio',
            'recorded_at' => now()->subMinutes($minutesAgo), 'published_at' => now()->subMinutes($minutesAgo), 'facts' => [], 'segments' => [], 'script' => 'Hello.']);
        $relative = 'radio/'.$b->id.'/'.Str::uuid().'.mp3';
        File::ensureDirectoryExists(dirname($this->files[] = storage_path('app/'.$relative)));
        File::put(storage_path('app/'.$relative), str_repeat('x', 2048));
        $this->dirs[] = public_path('generated/radio/'.$b->id);
        $b->update(['audio_path' => $relative, 'audio_bytes' => 2048, 'duration_seconds' => 240, 'voice' => 'bm_george']);

        return $b;
    }

    private function video(string $title, int $minutesAgo, bool $featured = false): SocialVideo
    {
        $v = SocialVideo::create(['title' => $title, 'type' => 'documentary', 'status' => 'approved', 'featured' => $featured, 'duration_seconds' => 240,
            'video_path' => 'video.mp4', 'poster_path' => 'poster.jpg']);
        $v->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        File::ensureDirectoryExists($this->dirs[] = $v->directory());
        File::put($v->publicVideoPath(), 'x');
        File::put($v->publicPosterPath(), 'x');

        return $v;
    }

    private function slideshow(int $minutesAgo): Slideshow
    {
        $s = Slideshow::create(['key' => 'totw:'.$minutesAgo, 'type' => 'totw']);
        $s->forceFill(['status' => 'ready', 'approved' => true, 'approved_at' => now()->subMinutes($minutesAgo), 'video_path' => 'slideshow.mp4',
            'facts' => ['title' => 'Team of the week']])->save();
        File::ensureDirectoryExists($this->dirs[] = dirname($s->publicVideoPath()));
        File::put($s->publicVideoPath(), 'x');

        return $s;
    }

    private function article(string $title, int $minutesAgo, string $excerpt = 'Short story.'): Blog
    {
        return Blog::create(['title' => $title, 'slug' => Str::slug($title).'-'.Str::random(4), 'excerpt' => $excerpt, 'content' => 'Body.',
            'status' => 'published', 'published_at' => now()->subMinutes($minutesAgo), 'featured_image' => 'https://example.com/'.Str::slug($title).'.jpg']);
    }

    private function breaking(string $headline, int $minutesAgo): BreakingNews
    {
        $item = BreakingNews::create(['headline' => $headline, 'source' => 'admin', 'expires_at' => now()->addHours(6)]);
        $item->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $item;
    }

    public function test_the_hero_follows_live_then_radio_then_breaking_then_video_then_article(): void
    {
        $article = $this->article('Arsenal sign a striker', 300);
        $video = $this->video('The Lagos derby story', 200);
        $breaking = $this->breaking('Osimhen injured in training', 30);
        $radio = $this->radio(90);
        $match = $this->liveMatch();

        $this->assertSame('live', $this->heroKind());
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('LIVE NOW', $html);
        $this->assertLessThan(strpos($html, 'id="home-fresh"'), strpos($html, 'id="home-hero"'), 'the hero leads the page');

        $match->update(['status' => 'FINISHED']);
        $this->assertSame('radio', $this->heroKind());
        $this->get('/')->assertSee('id="matchday-radio" data-radio-player', false)->assertSee('On air · Matchday Radio');

        // Radio counts as on air for three hours after it is published.
        $this->travel(2)->hours();
        $this->assertSame('breaking', $this->heroKind());
        $html = $this->get('/')->assertSee('Osimhen injured in training')->getContent();
        $this->assertSame(1, substr_count($html, 'aria-label="Breaking news"'), 'the breaking item is the hero, not a second banner');

        $breaking->update(['ended_at' => now()]);
        BreakingNews::forget();
        $this->assertSame('video', $this->heroKind());
        $this->get('/')->assertSee('<video controls playsinline preload="none"', false)->assertSee('src="'.$video->videoUrl().'"', false)->assertSee('poster="'.$video->posterUrl().'"', false);

        $video->update(['status' => 'off']);
        $this->assertSame('article', $this->heroKind());
        $this->get('/')->assertSee('Arsenal sign a striker')->assertSee('Read the story');

        // Never empty: with nothing at all, the hero points at the matches.
        $article->update(['status' => 'draft']);
        $this->assertSame('matches', $this->heroKind());
    }

    public function test_a_live_matchday_stream_on_youtube_outranks_radio(): void
    {
        config(['stream.enabled' => true]);
        $this->radio(20);
        YoutubeBroadcast::create(['broadcast_id' => 'matchday001', 'window_key' => now()->toDateString(), 'title' => 'Matchday Live',
            'starts_at' => now(), 'ends_at' => now()->addHours(8), 'privacy' => 'unlisted', 'status' => 'live']);

        $this->assertSame('stream', $this->heroKind());
        $this->get('/')->assertSee('https://www.youtube.com/watch?v=matchday001', false)->assertSee('Matchday Live is on air now');
    }

    public function test_african_teams_lead_the_live_hero_and_the_pulse_keeps_the_score_fresh(): void
    {
        $this->liveMatch('Arsenal FC', 'Chelsea FC');
        $afcon = $this->liveMatch('Nigeria', 'Ghana', 'AFCON');

        $html = $this->get('/')->getContent();
        $this->assertLessThan(strpos($html, 'Arsenal'), strpos($html, 'Nigeria'), 'the African match is the lead');
        $this->assertStringContainsString('Also live · 1 more', $html);
        preg_match('/data-hero-key="([^"]+)"/', $html, $key);

        // The pulse answers with the same key while nothing changed, and a new one after a goal.
        $this->getJson(route('home.pulse', ['hero' => $key[1]]))->assertOk()->assertJsonPath('hero_key', $key[1]);
        $afcon->update(['home_score' => 3]);
        $pulse = $this->getJson(route('home.pulse', ['hero' => $key[1]]))->assertOk()->json();
        $this->assertNotSame($key[1], $pulse['hero_key']);
        $this->assertStringContainsString('3–1', $pulse['hero']);
        $this->assertStringContainsString('id="home-updated"', $pulse['updated']);
    }

    public function test_new_on_matchday_mixes_every_kind_newest_first_with_new_badges(): void
    {
        $this->article('Old feature', 20 * 60);           // 20 hours ago: no "New" badge
        $this->slideshow(5 * 60);
        $this->article('Arsenal sign a striker', 2 * 60);
        $this->radio(60);
        $this->video('The Lagos derby story', 30);
        $this->breaking('Osimhen injured in training', 10);

        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('/data-fresh-item="([a-z]+):\d+"/', $html, $kinds);
        $this->assertSame(['breaking', 'video', 'radio', 'article', 'slideshow', 'article'], $kinds[1]);
        $this->assertSame(5, substr_count($html, '<span class="md-front-new">New</span>'));
        foreach (['Breaking', 'Mini-documentary', 'Radio', 'Article', 'Slideshow'] as $label) {
            $this->assertStringContainsString('<span class="md-front-kind">'.$label.'</span>', $html);
        }
        // Updated HH:MM comes from the newest item (10 minutes before noon Lagos time: 11:50 WAT, 10:50 UTC).
        $this->assertStringContainsString('Updated <time datetime="2026-11-20T10:50:00Z">11:50</time> WAT', $html);

        // The pulse sends the rail again only when it changed.
        preg_match('/data-fresh-key="([^"]+)"/', $html, $key);
        $this->getJson(route('home.pulse', ['hero' => '', 'fresh' => $key[1]]))->assertOk()->assertJsonMissingPath('fresh_html');
        $this->article('Super Eagles name their squad', 0);
        Cache::flush();
        $fresh = $this->getJson(route('home.pulse', ['hero' => '', 'fresh' => $key[1]]))->assertOk()->json('fresh_html');
        $this->assertStringContainsString('Super Eagles name their squad', $fresh);
    }

    public function test_new_on_matchday_hides_when_there_is_nothing(): void
    {
        $this->get('/')->assertOk()->assertSee('id="home-fresh" data-fresh-key', false)->assertDontSee('New on Matchday')
            ->assertSee('data-hero-kind="matches"', false);
    }

    public function test_the_as_seen_on_tiktok_row_shows_once_a_long_video_exists(): void
    {
        $this->get('/')->assertOk()->assertDontSee('As seen on TikTok');
        Cache::flush();
        $video = $this->video('The Lagos derby story', 30, featured: true);

        $this->get('/')->assertOk()->assertSee('As seen on TikTok')->assertSee('href="'.$video->pageUrl().'" data-track="featured_video"', false);
    }

    public function test_latest_news_puts_african_stories_first_and_drops_week_old_ones(): void
    {
        $nigeria = League::create(['short_code' => 'NPFL', 'espn_slug' => 'nga.1', 'name' => 'Nigeria Premier Football League', 'football_data_id' => 77]);
        $team = Team::create(['name' => 'Liverpool FC']);
        Player::create(['team_id' => $team->id, 'name' => 'Mohamed Salah', 'nationality' => 'Egypt', 'nationality_code' => 'EGY', 'is_active' => true]);

        $chelsea = $this->article('Chelsea win again', 10);
        $salah = $this->article('Salah scores twice at Anfield', 60);
        $spurs = $this->article('Spurs change their manager', 120);
        $eagles = $this->article('Super Eagles name their squad', 180);
        $npfl = $this->article('Late drama in the Nigeria Premier Football League', 240);
        $tagged = Blog::create(['title' => 'Transfer round-up', 'slug' => 'transfer-round-up', 'excerpt' => 'All the moves.', 'content' => 'Body.', 'status' => 'published',
            'published_at' => now()->subMinutes(300), 'metadata' => ['region' => 'africa']]);
        $this->article('City look sharp', 400);
        $this->article('Ghana qualify for the World Cup', 10 * 24 * 60); // ten days old: newer stories exist

        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('/data-news-id="(\d+)"/', $html, $ids);
        $this->assertSame([$salah->id, $eagles->id, $npfl->id, $tagged->id, $chelsea->id, $spurs->id], array_map('intval', $ids[1]));
        $this->assertStringNotContainsString('Ghana qualify for the World Cup', substr($html, strpos($html, 'id="home-news"')));
        $this->assertSame(4, substr_count($html, 'data-african'));
    }

    public function test_on_a_quiet_week_older_news_still_fills_the_list(): void
    {
        $old = $this->article('Ghana qualify for the World Cup', 10 * 24 * 60);
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-news-id="'.$old->id.'"', $html);
    }

    public function test_fragments_are_cached_for_less_than_a_minute(): void
    {
        $this->assertLessThanOrEqual(60, FrontPage::CACHE_SECONDS);
        $this->get('/')->assertOk()->assertDontSee('Arsenal sign a striker');
        $this->article('Arsenal sign a striker', 0);

        $this->travel(FrontPage::CACHE_SECONDS + 1)->seconds();
        $this->get('/')->assertOk()->assertSee('Arsenal sign a striker');
    }
}
