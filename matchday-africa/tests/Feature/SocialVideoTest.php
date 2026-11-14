<?php

namespace Tests\Feature;

use App\Models\FeaturedItem;
use App\Models\RadioBulletin;
use App\Models\Slideshow;
use App\Models\SocialVideo;
use App\Models\User;
use App\Services\SocialQueue;
use App\Services\SocialVideos;
use App\Support\XText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Long videos for TikTok: the "As seen on TikTok" row on /reels, uploads (admin and bot), pages and queue items. */
class SocialVideoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-queue-token-0123456789abcdef';

    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://matchday.africa', 'social.queue_token' => self::TOKEN, 'social.autopost_enabled' => false,
            'radio.enabled' => true, 'slideshow.ffmpeg' => '/nonexistent/ffmpeg', 'radio.ffmpeg' => '/nonexistent/ffmpeg']);
    }

    protected function tearDown(): void
    {
        foreach (SocialVideo::pluck('id') as $id) {
            File::deleteDirectory(public_path("generated/videos/{$id}"));
        }
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private static function mp4(int $seconds): string
    {
        $box = fn (string $type, string $body) => pack('N', 8 + strlen($body)).$type.$body;
        $mvhd = $box('mvhd', "\0\0\0\0".pack('NNNN', 0, 0, 1000, $seconds * 1000).str_repeat("\0", 80));

        return $box('ftyp', 'isom'.pack('N', 512).'isomiso2').$box('mdat', str_repeat("\0", 64)).$box('moov', $mvhd);
    }

    private function upload(int $seconds = 240): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.mp4', self::mp4($seconds));
    }

    private function api(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json'];
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function slideshow(bool $approved = true): Slideshow
    {
        $show = Slideshow::create(['key' => 'milestone:'.uniqid(), 'type' => 'milestone', 'status' => 'ready', 'approved' => $approved,
            'approved_at' => $approved ? now() : null, 'video_path' => 'slideshows/x/slideshow.mp4', 'duration_seconds' => 16,
            'facts' => ['title' => 'Semenyo scores again']]);
        File::ensureDirectoryExists(dirname($show->publicVideoPath()));
        File::put($show->publicVideoPath(), 'mp4');
        $this->dirs[] = dirname($show->publicVideoPath());

        return $show;
    }

    private function radioVideo(): RadioBulletin
    {
        $b = RadioBulletin::create(['slot' => '2026-11-14-12', 'title' => 'The midday update', 'status' => 'published', 'stage' => 'audio',
            'published_at' => now()->subMinutes(5), 'script' => 'Hello.', 'duration_seconds' => 70]);
        File::ensureDirectoryExists(dirname($b->publicVideoPath()));
        File::put($b->publicVideoPath(), str_repeat('v', 64));
        $this->dirs[] = dirname($b->publicVideoPath());
        $b->update(['video_status' => 'ready', 'video_seconds' => 70.0, 'video_bytes' => 64, 'video_rendered_at' => now()]);

        return $b;
    }

    // ---------------------------------------------------------------- the row on /reels

    public function test_reels_has_no_featured_row_when_nothing_qualifies(): void
    {
        $this->slideshow(approved: false);

        $this->get('/reels')->assertOk()
            ->assertDontSee('As seen on TikTok')
            ->assertSee('Every match, told in twenty seconds.');
    }

    public function test_reels_shows_the_row_with_a_published_slideshow_or_a_ready_radio_video(): void
    {
        $show = $this->slideshow();
        $this->get('/reels')->assertOk()->assertSee('As seen on TikTok')->assertSee($show->pageUrl())
            ->assertSee('Every match, told in twenty seconds.');

        $show->update(['approved' => false]);
        $b = $this->radioVideo();
        $this->get('/reels')->assertOk()->assertSee('As seen on TikTok')->assertSee(route('radio.show', $b))->assertDontSee($show->pageUrl());
    }

    public function test_featured_videos_lead_then_pinned_items_then_the_rest(): void
    {
        $old = $this->slideshow();
        $old->update(['approved_at' => now()->subDay()]);
        $new = $this->slideshow();
        $video = app(SocialVideos::class)->create($this->upload(), null, ['title' => 'The Lagos derby story', 'featured' => true], 240, null, 'admin', 'approved');
        FeaturedItem::create(['subject' => "slideshow:{$old->id}"]);

        $this->assertSame(["video:{$video->id}", "slideshow:{$old->id}", "slideshow:{$new->id}"], app(SocialVideos::class)->row()->pluck('key')->all());
    }

    // ---------------------------------------------------------------- the admin page

    public function test_an_admin_uploads_features_and_sees_the_video_on_reels_and_its_page(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/videos')->assertOk()->assertSee('As seen on TikTok.');

        $this->actingAs($admin)->post('/admin/videos', ['file' => $this->upload(185), 'title' => 'How Lookman lit up Bergamo',
            'type' => 'documentary', 'caption' => 'Ten minutes on the boy from Wandsworth.', 'credits' => 'Footage: Matchday Africa', 'featured' => '1'])
            ->assertRedirect('/admin/videos')->assertSessionHasNoErrors();

        $video = SocialVideo::firstOrFail();
        $this->assertSame(['approved', true, 185.0, 'documentary', $admin->id], [$video->status, $video->featured, $video->duration_seconds, $video->campaign, $video->uploaded_by]);
        $this->assertFileExists(public_path("generated/videos/{$video->id}/video.mp4"));

        auth()->logout();
        $this->get('/reels')->assertOk()->assertSee('As seen on TikTok')->assertSee('How Lookman lit up Bergamo')->assertSee("/reels/videos/{$video->id}");
        $page = $this->get("/reels/videos/{$video->id}")->assertOk()
            ->assertSee('<title>How Lookman lit up Bergamo: mini-documentary | Matchday Africa</title>', false)
            ->assertSee("/generated/videos/{$video->id}/video.mp4")
            ->assertSee('Credits: Footage: Matchday Africa')
            ->assertSee('Create my free account');
        $this->assertStringNotContainsString('/watch/', $page->getContent());

        // Not on the queue until approved onto it.
        $this->assertNull(app(SocialQueue::class)->find("video:{$video->id}"));
        $this->actingAs($admin)->post("/admin/videos/{$video->id}/status", ['status' => 'queued'])->assertRedirect();
        $this->assertNotNull(app(SocialQueue::class)->find("video:{$video->id}"));
    }

    public function test_a_non_admin_cannot_use_the_admin_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/admin/videos')->assertForbidden();
        $this->actingAs($user)->post('/admin/videos', ['file' => $this->upload(), 'title' => 'Nope'])->assertForbidden();
        $this->assertSame(0, SocialVideo::count());
        auth()->logout();
        $this->get('/admin/videos')->assertRedirect();
    }

    public function test_the_admin_upload_rejects_short_clips_unless_marked_short(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/videos', ['file' => $this->upload(45), 'title' => 'Short'])->assertSessionHasErrors('file');
        $this->actingAs($admin)->post('/admin/videos', ['file' => UploadedFile::fake()->createWithContent('a.png', 'x'), 'title' => 'Png'])->assertSessionHasErrors('file');
        $this->assertSame(0, SocialVideo::count());
        $this->actingAs($admin)->post('/admin/videos', ['file' => $this->upload(45), 'title' => 'Short', 'short' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, SocialVideo::count());
    }

    public function test_unfeaturing_and_taking_down_hide_the_video(): void
    {
        $admin = $this->admin();
        $video = app(SocialVideos::class)->create($this->upload(), null, ['title' => 'Compilation of the week', 'type' => 'compilation', 'featured' => true], 240, $admin->id, 'admin', 'queued');
        $this->get('/reels')->assertSee('Compilation of the week');

        $this->actingAs($admin)->post("/admin/videos/{$video->id}/feature", ['featured' => '0'])->assertRedirect();
        auth()->logout();
        $this->get('/reels')->assertDontSee('Compilation of the week')->assertDontSee('As seen on TikTok');
        $this->get("/reels/videos/{$video->id}")->assertOk();

        $this->actingAs($admin)->post("/admin/videos/{$video->id}/status", ['status' => 'approved']);
        $this->assertNull(app(SocialQueue::class)->find("video:{$video->id}"));
        $this->get("/reels/videos/{$video->id}")->assertOk();

        $this->actingAs($admin)->post("/admin/videos/{$video->id}/feature", ['featured' => '1']);
        $this->actingAs($admin)->post("/admin/videos/{$video->id}/status", ['status' => 'off']);
        auth()->logout();
        $this->get('/reels')->assertDontSee('Compilation of the week');
        $this->get("/reels/videos/{$video->id}")->assertNotFound();
        $this->assertNull(app(SocialQueue::class)->find("video:{$video->id}"));
        $ids = collect($this->getJson('/api/social-queue', $this->api())->json('items'))->pluck('id');
        $this->assertNotContains("video:{$video->id}", $ids);
    }

    public function test_pinning_a_slideshow_needs_an_admin_and_puts_it_first_among_the_rest(): void
    {
        $pinned = $this->slideshow();
        $pinned->update(['approved_at' => now()->subDays(2)]);
        $newer = $this->slideshow();
        $this->post('/admin/videos/pin', ['subject' => "slideshow:{$pinned->id}", 'pinned' => '1'])->assertRedirect();
        $this->assertSame(0, FeaturedItem::count());

        $this->actingAs($this->admin())->post('/admin/videos/pin', ['subject' => "slideshow:{$pinned->id}", 'pinned' => '1'])->assertRedirect();
        $this->assertSame(["slideshow:{$pinned->id}", "slideshow:{$newer->id}"], app(SocialVideos::class)->row()->pluck('key')->all());

        $pinned->update(['approved' => false]); // unapproved: off the row even when pinned
        $this->assertSame(["slideshow:{$newer->id}"], app(SocialVideos::class)->row()->pluck('key')->all());
    }

    // ---------------------------------------------------------------- the bot's API

    public function test_the_api_upload_needs_the_bearer_token(): void
    {
        $this->post('/api/social-queue/videos', ['file' => $this->upload(), 'title' => 'X'], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->post('/api/social-queue/videos', ['file' => $this->upload(), 'title' => 'X'], ['Authorization' => 'Bearer wrong', 'Accept' => 'application/json'])->assertUnauthorized();
        $this->assertSame(0, SocialVideo::count());
    }

    public function test_the_bot_uploads_a_video_and_the_queue_lists_it(): void
    {
        $response = $this->post('/api/social-queue/videos', ['file' => $this->upload(312), 'title' => 'Africa at the 2026 World Cup',
            'caption' => 'Every African goal at the World Cup, in five minutes.', 'campaign' => 'wc-recap', 'credits' => 'Voice: Kokoro (AI)',
            'featured' => '1', 'type' => 'compilation'], $this->api())->assertCreated();

        $video = SocialVideo::firstOrFail();
        $id = "video:{$video->id}";
        $response->assertJsonPath('id', $id)->assertJsonPath('type', 'video');
        $this->assertSame(['queued', 'api', null], [$video->status, $video->source, $video->uploaded_by]);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());

        $item = collect($this->getJson('/api/social-queue', $this->api())->assertOk()->json('items'))->firstWhere('id', $id);
        $this->assertNotNull($item);
        $this->assertSame("https://matchday.africa/generated/videos/{$video->id}/video.mp4", $item['video_url']);
        $this->assertSame($item['video_url'], $item['reel_url']);
        $this->assertSame(312, (int) $item['reel_duration_seconds']);
        $this->assertSame('Voice: Kokoro (AI)', $item['credits']);
        $this->assertSame("https://matchday.africa/reels/videos/{$video->id}?utm_source=tiktok&utm_medium=social&utm_campaign=wc-recap&utm_content=compilation-{$video->id}", $item['landing_urls']['tiktok']);
        $this->assertLessThanOrEqual(XText::LIMIT, XText::length($item['captions']['x']));
        $this->assertStringContainsString('utm_source=x', $item['captions']['x']);
        $this->assertStringContainsString('Credits: Voice: Kokoro (AI)', $item['captions']['tiktok']);
        $this->assertFalse($item['channels']['tiktok']['posted']);

        // The existing posted endpoint takes video:<id>.
        $this->postJson("/api/social-queue/{$id}/posted", ['channel' => 'tiktok', 'external_post_id' => '7311'], $this->api())->assertOk()
            ->assertJsonPath('channels.tiktok.posted', true);

        $this->get('/reels')->assertSee('Africa at the 2026 World Cup');
        $this->get("/reels/videos/{$video->id}")->assertOk();
    }

    public function test_the_api_defaults_the_campaign_and_rejects_short_or_unreadable_files(): void
    {
        $this->post('/api/social-queue/videos', ['file' => $this->upload(60), 'title' => 'Clip'], $this->api())->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post('/api/social-queue/videos', ['file' => UploadedFile::fake()->createWithContent('v.mp4', 'ftypisom-not-really'), 'title' => 'Bad'], $this->api())->assertStatus(422);
        $this->post('/api/social-queue/videos', ['file' => $this->upload(240)], $this->api())->assertStatus(422)->assertJsonValidationErrors('title');
        $this->assertSame(0, SocialVideo::count());

        $this->post('/api/social-queue/videos', ['file' => $this->upload(30), 'title' => 'Clip', 'short' => '1'], $this->api())->assertCreated()
            ->assertJsonPath('landing_urls.x', fn ($url) => str_contains($url, 'utm_campaign=documentary'));
    }

    public function test_long_videos_never_touch_the_match_youtube_columns(): void
    {
        $this->post('/api/social-queue/videos', ['file' => $this->upload(), 'title' => 'Doc'], $this->api())->assertCreated();
        $this->assertSame(0, \App\Models\FootballMatch::whereNotNull('youtube_video_id')->count());
    }
}
