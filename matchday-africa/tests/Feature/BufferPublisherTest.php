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

    public function test_full_time_reel_waits_in_the_queue_and_goes_to_all_three(): void
    {
        config(['buffer.x.auto' => true]);
        $this->fakeBuffer();
        // Past the goal card's 30 minutes, so the reel has the slots to itself.
        $this->travel(31)->minutes();
        $this->match->update(['status' => 'FINISHED', 'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        app(MatchStoryService::class)->sync($this->match->fresh());
        File::ensureDirectoryExists(storage_path('app/reels'));
        File::put(storage_path("app/reels/{$this->match->id}.mp4"), 'mp4');
        $video = route('matches.reel', ['match' => $this->match, 'v' => now()->timestamp]);
        Http::fake([$video => Http::response('m', 206, ['Content-Type' => 'video/mp4'])]);

        $this->artisan('twitter:engage');
        $this->assertSame(['instagram', 'tiktok', 'x'], \App\Models\GrowthPost::where('type', 'reel')->orderBy('channel')->pluck('channel')->all());
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 3 sent, 0 failed');

        $posts = collect($this->bufferPosts())->keyBy('channelId');
        $this->assertSame($video, $posts['chan-tt']['assets'][0]['video']['url']);
        $this->assertSame('reel', $posts['chan-ig']['metadata']['instagram']['type']);
        $this->assertStringContainsString('Arsenal 1–0 Chelsea: the whole match in 20 seconds', $posts['chan-x']['text']);
        File::delete(storage_path("app/reels/{$this->match->id}.mp4"));
    }

    public function test_a_reel_whose_video_does_not_load_is_not_sent(): void
    {
        config(['buffer.x.auto' => true]);
        $this->fakeBuffer();
        $this->travel(31)->minutes();
        $this->match->update(['status' => 'FINISHED', 'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        Http::fake([url('/matches').'/*' => Http::response('', 404)]);

        $this->artisan('twitter:engage');
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 3 failed');

        $this->assertSame([], $this->bufferPosts());
        $this->assertStringContainsString('The media did not load', \App\Models\GrowthPost::first()->last_error);
    }

    public function test_live_cards_wait_for_a_free_slot(): void
    {
        $this->fakeBuffer();
        \App\Models\GrowthPost::create(['content_key' => 'news:1', 'channel' => 'x', 'type' => 'news', 'message' => 'Earlier post',
            'status' => 'sent', 'published_at' => now()->subMinutes(20)]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');

        $this->assertSame(['chan-ig'], array_column($this->bufferPosts(), 'channelId'));
    }

    public function test_channels_are_listed_through_the_account(): void
    {
        Http::fake(['api.buffer.com/*' => function ($request) {
            if (str_contains($request['query'], 'account { organizations')) {
                return Http::response(['data' => ['account' => ['organizations' => [['id' => 'org1', 'name' => 'Matchday']]]]]);
            }
            if (str_contains($request['query'], 'IntrospectType')) {
                return Http::response(['data' => ['__type' => null]]);
            }

            return Http::response(['data' => ['channels' => [['id' => 'c1', 'name' => 'matchdayafrica', 'service' => 'twitter']]]]);
        }]);

        $this->artisan('buffer:publish --channels')->expectsTable(['ID', 'Name', 'Service', 'Health'], [['c1', 'matchdayafrica', 'twitter', '']])->assertSuccessful();
    }

    public function test_chapters_rebuilt_for_an_old_match_are_not_posted_as_live(): void
    {
        $this->fakeBuffer();
        $this->match->update(['match_date' => now('UTC')->subDays(2)]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');
        $this->assertSame([], $this->bufferPosts());
    }

    public function test_a_second_sender_waits_for_the_first(): void
    {
        $this->fakeBuffer();
        $lock = \Illuminate\Support\Facades\Cache::lock('buffer:publish', 600);
        $lock->get();

        $this->artisan('buffer:publish')->expectsOutput('Another buffer:publish is running; nothing sent.');
        $this->assertSame([], $this->bufferPosts());
        $lock->release();
    }

    public function test_a_reel_from_an_older_match_is_still_planned_when_it_becomes_ready(): void
    {
        config(['buffer.x.auto' => true]);
        $this->match->update(['status' => 'FINISHED', 'match_date' => now('UTC')->subDays(3),
            'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);

        $this->artisan('twitter:engage');

        $this->assertSame(['instagram', 'tiktok', 'x'], \App\Models\GrowthPost::where('type', 'reel')->orderBy('channel')->pluck('channel')->all());
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
