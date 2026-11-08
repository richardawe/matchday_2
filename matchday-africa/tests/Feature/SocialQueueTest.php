<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GrowthPost;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\SocialQueuePost;
use App\Models\Team;
use App\Models\User;
use App\Services\MatchStoryService;
use App\Services\SocialQueue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** /admin/social-queue and /api/social-queue: what the posting bot posts, and where it went. */
class SocialQueueTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-queue-token-0123456789abcdef';

    private FootballMatch $match;

    private GrowthPost $preview;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-07 18:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        config(['app.url' => 'https://matchday.africa', 'social.queue_token' => self::TOKEN, 'social.autopost_enabled' => false]);
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => '2026-11-07 15:00:00', 'status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1,
            'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'style' => 'v2r3', 'ready_at' => now()->subMinutes(30)->toIso8601String()]],
        ]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->match->id, 'team_id' => $this->match->home_team_id, 'type' => 'goal', 'minute' => 41, 'player_name' => 'Bukayo Saka']);
        app(MatchStoryService::class)->sync($this->match->fresh());
        File::ensureDirectoryExists(storage_path('app/reels'));
        file_put_contents(storage_path("app/reels/{$this->match->id}.mp4"), self::mp4(20));
        $this->match->forceFill(['youtube_video_id' => 'abcDEF12345', 'youtube_uploaded_at' => now()->subMinutes(10), 'youtube_status' => 'uploaded'])->save();

        $upcoming = FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::create(['name' => 'Liverpool FC'])->id, 'away_team_id' => Team::create(['name' => 'Everton FC'])->id,
            'match_date' => '2026-11-08 16:30:00', 'status' => 'SCHEDULED',
        ]);
        $this->preview = GrowthPost::create(['content_key' => "preview:{$upcoming->id}", 'channel' => 'x', 'type' => 'preview', 'status' => 'draft',
            'message' => "Liverpool v Everton ⚽ → Kick-off 17:30 WAT\n\nCall it:", 'image_url' => 'http://matchday.africa/matches/'.$upcoming->id.'/card.png',
            'scheduled_at' => now(), 'expires_at' => now()->addDay()]);
        GrowthPost::create(['content_key' => 'custom-1', 'channel' => 'x', 'type' => 'custom', 'status' => 'draft', 'message' => 'Hand-written', 'scheduled_at' => now()]);
    }

    protected function tearDown(): void
    {
        File::delete(storage_path("app/reels/{$this->match->id}.mp4"));
        File::deleteDirectory(public_path("generated/reels/{$this->match->id}"));
        parent::tearDown();
    }

    /** A minimal MP4: ftyp, then moov with an mvhd box saying $seconds at timescale 1000. */
    private static function mp4(int $seconds): string
    {
        $box = fn (string $type, string $body) => pack('N', 8 + strlen($body)).$type.$body;
        $mvhd = $box('mvhd', "\0\0\0\0".pack('NNNN', 0, 0, 1000, $seconds * 1000).str_repeat("\0", 80));

        return $box('ftyp', 'isom'.pack('N', 512).'isomiso2').$box('mdat', str_repeat("\0", 64)).$box('moov', $mvhd);
    }

    private function api(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json'];
    }

    public function test_items_list_the_reel_and_the_generated_posts_newest_first(): void
    {
        $items = app(SocialQueue::class)->items();

        $this->assertSame(["growth:{$this->preview->id}", "reel:{$this->match->id}"], $items->pluck('id')->all());
        $reel = $items->firstWhere('id', "reel:{$this->match->id}");
        $this->assertSame("https://matchday.africa/generated/reels/{$this->match->id}/{$this->match->id}.mp4", $reel['reel_url']);
        $this->assertFileExists(public_path("generated/reels/{$this->match->id}/{$this->match->id}.mp4"));
        $this->assertSame(20.0, $reel['reel_duration_seconds']);
        $this->assertSame('https://www.youtube.com/shorts/abcDEF12345', $reel['youtube_url']);
        $this->assertSame(['Arsenal', 'Chelsea', 'Premier League', '2-1'], [$reel['match']['home'], $reel['match']['away'], $reel['match']['competition'], $reel['match']['score']]);
        $this->assertSame('Sat 7 Nov 2026, 15:00 GMT', $reel['match']['kickoff_uk']);
        $this->assertStringStartsWith('https://matchday.africa/moments/', $reel['image_url']);
        $this->assertStringContainsString('Arsenal 2–1 Chelsea', $reel['caption']);
        $this->assertTrue($reel['channels']['youtube']['posted'], 'the Shorts upload counts as posted on YouTube');
        $this->assertFalse($reel['channels']['x']['posted']);

        $preview = $items->firstWhere('id', "growth:{$this->preview->id}");
        $this->assertSame("Liverpool v Everton Kick-off 17:30 WAT\n\nCall it:", $preview['caption'], 'no emoji or arrows');
        $this->assertStringStartsWith('https://', $preview['image_url']);
        $this->assertNull($preview['match']['score']);
    }

    public function test_the_mp4_header_gives_the_length(): void
    {
        $file = storage_path("app/reels/{$this->match->id}.mp4");
        file_put_contents($file, self::mp4(32));
        $this->assertSame(32.0, SocialQueue::mp4Seconds($file));
        file_put_contents($file, 'not an mp4');
        $this->assertNull(SocialQueue::mp4Seconds($file));
    }

    public function test_guests_and_non_admins_cannot_open_the_admin_page(): void
    {
        $this->get('/admin/social-queue')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/admin/social-queue')->assertForbidden();
        $this->post('/admin/social-queue/posted', ['id' => "reel:{$this->match->id}", 'channel' => 'x'])->assertForbidden();
        $this->assertSame(0, SocialQueuePost::count());
    }

    public function test_admin_sees_the_queue_and_marks_and_undoes_posts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/social-queue')->assertOk()
            ->assertSee("https://matchday.africa/generated/reels/{$this->match->id}/{$this->match->id}.mp4")
            ->assertSee('Mark posted')->assertSee(route('admin.social-queue.posted'));

        $id = "reel:{$this->match->id}";
        $this->actingAs($admin)->from('/admin/social-queue')->post('/admin/social-queue/posted', ['id' => $id, 'channel' => 'instagram'])
            ->assertRedirect('/admin/social-queue');
        $row = SocialQueuePost::where(['subject' => $id, 'channel' => 'instagram'])->first();
        $this->assertNotNull($row?->posted_at);
        $this->actingAs($admin)->get('/admin/social-queue')->assertSee('Undo');

        $this->actingAs($admin)->from('/admin/social-queue')->post('/admin/social-queue/undo', ['id' => $id, 'channel' => 'instagram'])
            ->assertRedirect('/admin/social-queue');
        $this->assertSame(0, SocialQueuePost::count());
    }

    public function test_the_admin_form_needs_csrf_outside_tests(): void
    {
        $this->assertStringContainsString('name="_token"', $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/social-queue')->getContent());
    }

    public function test_api_refuses_missing_wrong_and_unconfigured_tokens(): void
    {
        $this->getJson('/api/social-queue')->assertUnauthorized();
        $this->getJson('/api/social-queue', ['Authorization' => 'Bearer wrong-token'])->assertUnauthorized();
        $this->postJson("/api/social-queue/reel:{$this->match->id}/posted", ['channel' => 'x'], ['Authorization' => 'Bearer wrong-token'])->assertUnauthorized();

        config(['social.queue_token' => null]);
        $this->getJson('/api/social-queue', $this->api())->assertUnauthorized();
        config(['social.queue_token' => '']);
        $this->getJson('/api/social-queue', ['Authorization' => 'Bearer '])->assertUnauthorized();
        $this->assertStringNotContainsString(self::TOKEN, $this->getJson('/api/social-queue', ['Authorization' => 'Bearer x'])->getContent());
    }

    public function test_api_lists_the_items(): void
    {
        $response = $this->getJson('/api/social-queue', $this->api())->assertOk()
            ->assertJsonStructure(['generated_at', 'days', 'items' => [['id', 'type', 'match', 'reel_url', 'reel_duration_seconds', 'youtube_url', 'image_url', 'caption', 'created_at',
                'channels' => ['x' => ['posted', 'posted_at', 'external_post_id', 'url'], 'instagram', 'tiktok', 'youtube']]]]);

        $items = $response->json('items');
        $this->assertCount(2, $items);
        $this->assertSame("reel:{$this->match->id}", $items[1]['id']);
        $this->assertSame('2026-11-07T17:30:00Z', $items[1]['created_at']);
        $this->assertSame('2026-11-07T15:00:00Z', $items[1]['match']['kickoff_utc']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $response->json('generated_at'));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_api_marks_posted_and_updates_an_existing_row(): void
    {
        $id = "reel:{$this->match->id}";
        $this->postJson("/api/social-queue/{$id}/posted", ['channel' => 'x', 'external_post_id' => '1850000000000000000', 'url' => 'https://x.com/matchdayafrica/status/1850000000000000000'], $this->api())
            ->assertOk()->assertJsonPath('id', $id)->assertJsonPath('channels.x.posted', true)
            ->assertJsonPath('channels.x.external_post_id', '1850000000000000000')
            ->assertJsonPath('channels.x.url', 'https://x.com/matchdayafrica/status/1850000000000000000');

        $this->postJson("/api/social-queue/{$id}/posted", ['channel' => 'x', 'external_post_id' => 'second'], $this->api())->assertOk();
        $this->assertSame(1, SocialQueuePost::count());
        $this->assertSame('second', SocialQueuePost::first()->external_post_id);

        $this->postJson("/api/social-queue/growth:{$this->preview->id}/posted", ['channel' => 'tiktok'], $this->api())
            ->assertOk()->assertJsonPath('channels.tiktok.posted', true);
    }

    public function test_api_validates_channel_url_and_id(): void
    {
        $id = "reel:{$this->match->id}";
        $this->postJson("/api/social-queue/{$id}/posted", ['channel' => 'facebook'], $this->api())->assertStatus(422);
        $this->postJson("/api/social-queue/{$id}/posted", ['channel' => 'x', 'url' => 'not a url'], $this->api())->assertStatus(422);
        $this->postJson('/api/social-queue/reel:999999/posted', ['channel' => 'x'], $this->api())->assertNotFound();
        $this->postJson('/api/social-queue/growth:999999/posted', ['channel' => 'x'], $this->api())->assertNotFound();
        $this->assertSame(0, SocialQueuePost::count());
    }

    public function test_api_is_rate_limited(): void
    {
        RateLimiter::clear('social-queue-read');
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/social-queue', $this->api())->assertOk();
        }
        $this->getJson('/api/social-queue', $this->api())->assertStatus(429);

        $id = "reel:{$this->match->id}";
        for ($i = 0; $i < 30; $i++) {
            $this->postJson("/api/social-queue/{$id}/posted", ['channel' => 'x'], $this->api())->assertOk();
        }
        $this->postJson("/api/social-queue/{$id}/posted", ['channel' => 'x'], $this->api())->assertStatus(429);
    }

    public function test_api_needs_no_session_or_csrf(): void
    {
        $response = $this->withHeaders($this->api())->post("/api/social-queue/reel:{$this->match->id}/posted", ['channel' => 'youtube']);

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies(), 'no session cookie');
    }
}
