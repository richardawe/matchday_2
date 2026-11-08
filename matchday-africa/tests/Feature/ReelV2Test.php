<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Models\GrowthPost;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\MatchStoryService;
use App\Services\ReelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\FakesBuffer;
use Tests\TestCase;

/** Reel style v2 (moods, Griot timeline), release at the final whistle, and instant Buffer reels. */
class ReelV2Test extends TestCase
{
    use FakesBuffer;
    use RefreshDatabase;

    private FootballMatch $match;
    private string $pose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-19 18:00:00');
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id,
            'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id,
            'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now('UTC')->subMinutes(110), 'status' => 'FINISHED', 'home_score' => 1, 'away_score' => 0,
        ]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->match->id, 'team_id' => $this->match->home_team_id, 'type' => 'goal', 'minute' => 41, 'player_name' => 'Bukayo Saka']);
        app(MatchStoryService::class)->syncRecent();
        $this->pose = public_path('war/army/poses/roman-legion-victory.webp');
    }

    protected function tearDown(): void
    {
        File::delete($this->pose);
        File::delete(storage_path("app/reels/{$this->match->id}.mp4"));
        parent::tearDown();
    }

    private function griotPost(string $kind, ?string $side, int $minute, int $seq, int $home = 0, int $away = 0, array $facts = []): GriotPost
    {
        return GriotPost::create(['match_id' => $this->match->id, 'key' => "{$kind}:{$seq}", 'kind' => $kind, 'side' => $side, 'minute' => $minute,
            'seq' => $seq, 'home_score' => $home, 'away_score' => $away, 'facts' => $facts, 'play_keys' => []]);
    }

    public function test_classic_style_is_the_default(): void
    {
        $props = app(ReelService::class)->props($this->match);

        $this->assertSame('classic', $props['style']);
        $this->assertStringEndsWith('resources/armies/roman-legion.webp', $props['home']['art_path']);
        $this->assertArrayNotHasKey('pose_paths', $props['home']);
    }

    public function test_v2_uses_solid_art_and_only_approved_poses(): void
    {
        config(['reel.style' => 'v2']);
        File::ensureDirectoryExists(dirname($this->pose));
        File::put($this->pose, 'webp');

        $props = app(ReelService::class)->props($this->match);
        $this->assertSame('v2', $props['style']);
        $this->assertStringEndsWith('war/army/roman-legion-solid.webp', $props['home']['art_path']);
        $this->assertSame([], $props['home']['pose_paths'], 'a pose file nobody approved is not used');

        config(['armies.poses' => ['roman-legion' => ['victory', 'dejected']]]);
        $props = app(ReelService::class)->props($this->match);
        $this->assertSame(['victory' => $this->pose], $props['home']['pose_paths'], 'approved, and only the moods whose file exists');
        $this->assertSame([], $props['away']['pose_paths']);

        $urls = app(ReelService::class)->props($this->match, urls: true);
        $this->assertStringContainsString('war/army/roman-legion-solid.webp', $urls['home']['art_url']);
        $this->assertStringContainsString('war/army/poses/roman-legion-victory.webp', $urls['home']['pose_urls']['victory']);
    }

    public function test_v2_tells_the_story_from_the_griot_timeline(): void
    {
        config(['reel.style' => 'v2', 'griot.enabled' => true]);
        $this->griotPost('kickoff', null, 0, 1);
        $this->griotPost('woodwork', 'away', 12, 2, 0, 0, ['player' => 'Cole Palmer']);
        $this->griotPost('goal', 'home', 41, 3, 1, 0, ['player' => 'Bukayo Saka', 'angle' => 'opener']);
        $this->griotPost('full_time', 'home', 90, 4, 1, 0);

        $beats = app(ReelService::class)->props($this->match)['beats'];

        $this->assertSame(['kickoff', 'woodwork', 'goal'], array_column($beats, 'type'), 'full time is the closing scene, not a beat');
        $this->assertSame('OFF THE POST · 12′', $beats[1]['tag']);
        $this->assertSame('away', $beats[1]['side']);
        $this->assertSame([1, 0], [$beats[2]['home_score'], $beats[2]['away_score']]);
        $this->assertNotEmpty($beats[2]['say']);
        $this->assertSame("Saka scores for Arsenal, 41 minutes! It's one nil to Arsenal.", $beats[2]['say'][0], 'a commentator\'s call: surname, club, minute, score');
        $this->assertSame("Off the post! Palmer so close for Chelsea!", $beats[1]['say'][0]);
    }

    public function test_every_named_moment_carries_the_players_photo(): void
    {
        config(['reel.style' => 'v2', 'griot.enabled' => true]);
        $palmer = \App\Models\Player::create(['team_id' => $this->match->away_team_id, 'name' => 'Cole Palmer']);
        $file = public_path("generated/players/{$palmer->id}.png");
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($file));
        imagepng(imagecreatetruecolor(150, 150), $file);
        $palmer->update(['photo_url' => url("generated/players/{$palmer->id}.png")]);
        $this->griotPost('kickoff', null, 0, 1);
        $this->griotPost('woodwork', 'away', 12, 2, 0, 0, ['player' => 'Cole Palmer']);
        $this->griotPost('goal', 'home', 41, 3, 1, 0, ['player' => 'Bukayo Saka', 'angle' => 'opener']);

        try {
            $beats = app(ReelService::class)->props($this->match)['beats'];
            $this->assertSame($file, $beats[1]['photo_path'], 'the post hit by Palmer shows Palmer');
            $this->assertArrayNotHasKey('photo_path', $beats[2], 'no photo of Saka, so none is shown');
            $this->assertArrayNotHasKey('photo_path', $beats[0]);
            $this->assertStringContainsString("generated/players/{$palmer->id}.png", app(ReelService::class)->props($this->match, true)['beats'][1]['photo_url']);
        } finally {
            \Illuminate\Support\Facades\File::delete($file);
        }
    }

    public function test_v2_falls_back_to_chapters_without_a_timeline(): void
    {
        config(['reel.style' => 'v2', 'griot.enabled' => false]);
        $this->griotPost('woodwork', 'away', 12, 2);

        $this->assertSame(['kickoff', 'goal'], array_column(app(ReelService::class)->props($this->match)['beats'], 'type'));
    }

    public function test_github_renders_every_waiting_match_at_once(): void
    {
        config(['reel.renderer' => 'github', 'reel.github.token' => 'token']);
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);
        $second = FootballMatch::create([
            'league_id' => $this->match->league_id, 'home_team_id' => Team::create(['name' => 'Liverpool FC'])->id,
            'away_team_id' => Team::create(['name' => 'Everton FC'])->id, 'match_date' => now('UTC')->subMinutes(110),
            'status' => 'FINISHED', 'home_score' => 0, 'away_score' => 0,
        ]);
        app(MatchStoryService::class)->sync($second);
        $this->travel(4)->minutes();

        $this->artisan('reel:render')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(['dispatched', 'dispatched'], [data_get($this->match->fresh()->metadata, 'reel.status'), data_get($second->fresh()->metadata, 'reel.status')]);
    }

    public function test_a_server_sends_the_overflow_to_github(): void
    {
        config(['reel.renderer' => 'server', 'reel.overflow' => 'github', 'reel.github.token' => 'token', 'reel.node' => '/bin/false']);
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);
        $second = FootballMatch::create([
            'league_id' => $this->match->league_id, 'home_team_id' => Team::create(['name' => 'Liverpool FC'])->id,
            'away_team_id' => Team::create(['name' => 'Everton FC'])->id, 'match_date' => now('UTC')->subMinutes(100),
            'status' => 'FINISHED', 'home_score' => 0, 'away_score' => 0,
        ]);
        app(MatchStoryService::class)->sync($second);
        $this->travel(4)->minutes();

        $this->artisan('reel:render')->assertSuccessful();

        // The earlier match renders here (and fails: no Node in tests); the later one goes to GitHub.
        Http::assertSent(fn ($r) => $r['inputs']['match_id'] === (string) $second->id);
        Http::assertSentCount(1);
        $this->assertSame('dispatched', data_get($second->fresh()->metadata, 'reel.status'));
        $this->assertNotSame('dispatched', data_get($this->match->fresh()->metadata, 'reel.status'));
    }

    public function test_a_reel_is_made_again_once_when_its_story_changes(): void
    {
        $reels = app(ReelService::class);
        $this->match->update(['metadata' => ['reel' => ['status' => 'ready', 'ready_at' => now()->toIso8601String(), 'story' => $reels->storyKey($this->match)]]]);
        $this->assertSame(0, $reels->requeueCorrected(), 'nothing changed');

        // The feed corrects the score after the reel went out.
        MatchEvent::create(['football_data_id' => 2, 'match_id' => $this->match->id, 'team_id' => $this->match->away_team_id, 'type' => 'goal', 'minute' => 90, 'player_name' => 'Cole Palmer']);
        $this->match->update(['away_score' => 1]);
        app(MatchStoryService::class)->sync($this->match->fresh());

        $this->assertSame(1, $reels->requeueCorrected());
        $this->assertSame(['queued', 0, 1], [data_get($this->match->fresh()->metadata, 'reel.status'), data_get($this->match->fresh()->metadata, 'reel.attempts'), data_get($this->match->fresh()->metadata, 'reel.corrections')]);

        $this->match->update(['metadata' => array_merge($this->match->fresh()->metadata, ['reel' => array_merge($this->match->fresh()->metadata['reel'], ['status' => 'ready', 'story' => 'old'])])]);
        $this->assertSame(0, $reels->requeueCorrected(), 'only once');
    }

    public function test_reels_go_to_buffer_at_once_outside_the_slots(): void
    {
        config(['buffer.key' => 'key', 'buffer.channels' => ['x' => 'chan-x', 'instagram' => 'chan-ig', 'tiktok' => 'chan-tt'],
            'buffer.x.auto' => true, 'buffer.reels_now.enabled' => true]);
        $this->fakeBuffer();
        // The 13:00 slot is already used, and today is a matchday: without reels_now the reel would wait.
        GrowthPost::create(['content_key' => 'news:1', 'channel' => 'x', 'type' => 'news', 'message' => 'Earlier', 'status' => 'sent',
            'scheduled_at' => now()->subHour(), 'published_at' => now()->subHour()]);
        $this->match->update(['metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        File::ensureDirectoryExists(storage_path('app/reels'));
        File::put(storage_path("app/reels/{$this->match->id}.mp4"), 'mp4');
        Http::fake([url('/generated/reels').'/*' => Http::response('m', 206, ['Content-Type' => 'video/mp4'])]);

        $this->artisan('buffer:publish');

        $this->assertSame(['sent', 'sent', 'sent'], GrowthPost::where('type', 'reel')->orderBy('channel')->pluck('status')->all());
        $this->assertCount(3, collect($this->bufferPosts())->filter(fn ($p) => isset($p['assets'][0]['video'])));
        // TikTok gets no live cards, so only the reel was sent there.
        $this->assertTrue(app(\App\Services\BufferPublisher::class)->slotOpen('tiktok'), 'an instant reel does not use a slot');
    }

    public function test_an_old_reel_still_waits_for_a_slot(): void
    {
        config(['buffer.key' => 'key', 'buffer.channels' => ['x' => 'chan-x'], 'buffer.x.auto' => true, 'buffer.reels_now.enabled' => true]);
        $this->fakeBuffer();
        GrowthPost::create(['content_key' => 'news:1', 'channel' => 'x', 'type' => 'news', 'message' => 'Earlier', 'status' => 'sent',
            'scheduled_at' => now()->subHour(), 'published_at' => now()->subHour()]);
        // A backfilled reel made a day ago: not news any more.
        $this->match->update(['metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->subDay()->toIso8601String()]]]);
        File::ensureDirectoryExists(storage_path('app/reels'));
        File::put(storage_path("app/reels/{$this->match->id}.mp4"), 'mp4');
        Http::fake([url('/generated/reels').'/*' => Http::response('m', 206, ['Content-Type' => 'video/mp4'])]);

        $this->artisan('buffer:publish');

        $this->assertSame('queued', GrowthPost::where('type', 'reel')->value('status'));
    }
}
