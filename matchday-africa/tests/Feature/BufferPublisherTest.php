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

    public function test_a_reel_goes_to_all_three_in_a_reel_slot_the_day_after(): void
    {
        config(['buffer.x.auto' => true]);
        $this->fakeBuffer();
        $this->match->update(['status' => 'FINISHED']);
        app(MatchStoryService::class)->sync($this->match->fresh());
        // The next morning: no Premier League match today, and 08:00 is a reel slot.
        $this->travelTo(now('Africa/Lagos')->addDay()->setTime(8, 30));
        $this->match->update(['metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
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
        $this->assertStringContainsString('Full story, stats and predictions on Matchday Africa', $posts['chan-x']['text']);
        File::delete(storage_path("app/reels/{$this->match->id}.mp4"));
    }

    public function test_reels_wait_out_matchdays_and_the_afternoon_slot(): void
    {
        config(['buffer.x.auto' => true]);
        $this->fakeBuffer();
        $this->match->update(['status' => 'FINISHED', 'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        $this->travel(31)->minutes();
        $this->artisan('twitter:engage');
        Http::fake([url('/matches').'/*' => Http::response('m', 206, ['Content-Type' => 'video/mp4'])]);

        // 19:31 on the matchday itself: held back.
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');
        // Next day at 13:30, the afternoon slot belongs to news and previews.
        $this->travelTo(now('Africa/Lagos')->addDay()->setTime(13, 30));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');
        // 19:00 is a reel slot.
        $this->travelTo(now('Africa/Lagos')->setTime(19, 5));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 3 sent, 0 failed');
        $this->assertSame(['reel'], \App\Models\GrowthPost::where('status', 'sent')->pluck('type')->unique()->values()->all());
    }

    public function test_a_round_s_reels_stay_in_the_pool_until_the_next_round(): void
    {
        config(['buffer.x.auto' => true]);
        $next = FootballMatch::create(['league_id' => $this->match->league_id, 'home_team_id' => $this->match->away_team_id, 'away_team_id' => $this->match->home_team_id,
            'match_date' => now('UTC')->addDays(6)->setTime(14, 0), 'status' => 'TIMED']);
        $this->match->update(['status' => 'FINISHED', 'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);

        $this->artisan('twitter:engage');

        $expires = \App\Models\GrowthPost::where('content_key', "reel:{$this->match->id}")->value('expires_at');
        $this->assertSame(now('UTC')->addDays(6)->setTime(14, 0)->setTimezone('Africa/Lagos')->format('Y-m-d H:i'), \Carbon\Carbon::parse($expires)->format('Y-m-d H:i'));
    }

    public function test_a_reel_whose_video_does_not_load_is_not_sent(): void
    {
        config(['buffer.x.auto' => true]);
        $this->fakeBuffer();
        $this->travelTo(now('Africa/Lagos')->addDay()->setTime(8, 30));
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

    public function test_a_timed_out_post_is_never_retried_automatically(): void
    {
        config(['buffer.x.auto' => true]);
        \App\Models\GrowthPost::create(['content_key' => 'news:9', 'channel' => 'x', 'type' => 'news', 'message' => 'Hello',
            'status' => 'queued', 'scheduled_at' => now()->subMinute()]);
        $this->travel(31)->minutes();
        Http::fake(['api.buffer.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 45001 milliseconds')]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 1 failed');
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');

        $post = \App\Models\GrowthPost::first();
        $this->assertSame(['failed', 1], [$post->status, $post->attempts]);
        $this->assertStringContainsString('Check Buffer before retrying', $post->last_error);
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

    public function test_reel_posts_move_to_the_extensionless_url_and_unreadable_ones_retry(): void
    {
        $row = fn (string $key, string $status, ?string $error = null) => \App\Models\GrowthPost::create(['content_key' => $key, 'channel' => 'tiktok', 'type' => 'reel', 'message' => 'm',
            'video_url' => "https://matchday.africa/matches/1/reel.mp4?v=1", 'status' => $status, 'attempts' => 3, 'last_error' => $error, 'scheduled_at' => now()]);
        $failed = $row('a', 'failed', 'Invalid post: Video could not be read from its URL.');
        $other = $row('b', 'failed', 'Channel disconnected');
        $sent = $row('c', 'sent');

        (require database_path('migrations/2026_10_22_000000_move_reel_posts_to_extensionless_url.php'))->up();

        $this->assertSame(['queued', 0, null, 'https://matchday.africa/matches/1/reel?v=1'], [$failed->fresh()->status, $failed->fresh()->attempts, $failed->fresh()->last_error, $failed->fresh()->video_url]);
        $this->assertSame(['failed', 'https://matchday.africa/matches/1/reel?v=1'], [$other->fresh()->status, $other->fresh()->video_url]);
        $this->assertSame('https://matchday.africa/matches/1/reel.mp4?v=1', $sent->fresh()->video_url);
    }
}
