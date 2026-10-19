<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\MatchStoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\FakesBuffer;
use Tests\TestCase;

class BufferPublisherTest extends TestCase
{
    use RefreshDatabase, FakesBuffer;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-19 18:00:00');
        config(['buffer.key' => 'key', 'buffer.channels' => ['x' => 'chan-x', 'instagram' => 'chan-ig', 'tiktok' => 'chan-tt']]);
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now('UTC')->subMinutes(50), 'status' => 'IN_PLAY', 'home_score' => 1, 'away_score' => 0,
        ]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->match->id, 'team_id' => $this->match->home_team_id, 'type' => 'goal', 'minute' => 41, 'player_name' => 'Bukayo Saka']);
        app(MatchStoryService::class)->syncRecent();
    }

    public function test_goal_card_goes_to_x_and_instagram_once(): void
    {
        $this->fakeBuffer();

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 2 sent, 0 failed')->assertSuccessful();
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');

        $posts = collect($this->bufferPosts())->keyBy('channelId');
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('First blood', $posts['chan-x']['text']);
        $this->assertStringStartsWith(url('/moments'), $posts['chan-x']['assets'][0]['image']['url']);
        $this->assertSame('shareNow', $posts['chan-x']['mode']);
        $this->assertArrayNotHasKey('metadata', $posts['chan-x']);
        // Instagram refuses a post without its type, which lives at metadata.instagram.type.
        $this->assertSame(['instagram' => ['type' => 'post', 'shouldShareToFeed' => true]], $posts['chan-ig']['metadata']);
        $this->assertSame(2, DB::table('social_posts')->where('status', 'sent')->count());
        $this->assertGreaterThanOrEqual(2, DB::table('buffer_usage')->value('requests'));
    }

    public function test_failures_are_kept_and_retried(): void
    {
        $this->fakeBuffer(['message' => 'Channel disconnected'], ['post' => ['id' => 'p2']]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 1 failed')->assertFailed();
        $this->assertSame('Channel disconnected', DB::table('social_posts')->where('status', 'failed')->value('error'));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');
    }

    public function test_full_time_reel_goes_to_all_three_including_tiktok(): void
    {
        $this->fakeBuffer();
        // Past the goal card's 30 minutes, so the reel has the slots to itself.
        $this->travel(31)->minutes();
        $this->match->update(['status' => 'FINISHED', 'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        File::ensureDirectoryExists(storage_path('app/reels'));
        File::put(storage_path("app/reels/{$this->match->id}.mp4"), 'mp4');

        $this->artisan('buffer:publish')->assertSuccessful();

        $posts = collect($this->bufferPosts())->keyBy('channelId');
        $this->assertStringStartsWith(url("/matches/{$this->match->id}/reel.mp4"), $posts['chan-tt']['assets'][0]['video']['url']);
        $this->assertSame('reel', $posts['chan-ig']['metadata']['instagram']['type']);
        $this->assertSame(['instagram', 'tiktok', 'x'], DB::table('social_posts')->where('subject', "reel:{$this->match->id}")->orderBy('channel')->pluck('channel')->all());
        File::delete(storage_path("app/reels/{$this->match->id}.mp4"));
    }

    public function test_live_cards_wait_for_a_free_slot(): void
    {
        $this->fakeBuffer();
        \App\Models\GrowthPost::create(['content_key' => 'news:1', 'channel' => 'x', 'type' => 'news', 'message' => 'Earlier post',
            'status' => 'sent', 'published_at' => now()->subMinutes(20)]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');

        $this->assertSame(['chan-ig'], array_column($this->bufferPosts(), 'channelId'));
    }

    public function test_posting_stops_at_the_monthly_request_limit(): void
    {
        $this->fakeBuffer();
        DB::table('buffer_usage')->insert(['month' => now('UTC')->format('Ym'), 'requests' => 2900]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');
        $this->assertSame([], $this->bufferPosts());
    }

    public function test_nothing_is_sent_without_a_key(): void
    {
        config(['buffer.key' => null]);
        Http::fake();
        $this->artisan('buffer:publish')->expectsOutput('BUFFER_API_KEY is not set; nothing sent.');
        Http::assertNothingSent();
    }
}
