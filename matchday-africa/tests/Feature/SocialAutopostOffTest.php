<?php

namespace Tests\Feature;

use App\Jobs\UploadReelToYouTube;
use App\Models\FootballMatch;
use App\Models\GrowthPost;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Models\YoutubeAccount;
use App\Services\BufferClient;
use App\Services\BufferPublisher;
use App\Services\MatchStoryService;
use App\Services\ReelService;
use App\Services\TwitterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SOCIAL_AUTOPOST_ENABLED=false: the site plans and renders as before (drafts, reels, YouTube
 * Shorts) but nothing reaches Buffer or X, whatever BUFFER_AUTO and the credentials say.
 */
class SocialAutopostOffTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL = 'UCJ9Asmir1hBOHHQs9qSCkHg';

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-07 17:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        config([
            'social.autopost_enabled' => false,
            'app.url' => 'https://matchday.africa',
            'buffer.key' => 'dummy-buffer-key',
            'buffer.channels' => ['x' => 'chan-x', 'instagram' => 'chan-ig', 'tiktok' => 'chan-tt'],
            'buffer.x.auto' => true,
            'buffer.reels_now.enabled' => true,
            'services.twitter.client_id' => 'dummy-client', 'services.twitter.client_secret' => 'dummy-secret',
            'services.twitter_api.access_token' => 'dummy-access-token',
            'griot_challenge.enabled' => true,
            'youtube.client_id' => 'client-123', 'youtube.client_secret' => 'secret-456', 'youtube.channel_id' => self::CHANNEL,
            'youtube.uploads.enabled' => true,
        ]);
        YoutubeAccount::create(['channel_id' => self::CHANNEL, 'channel_title' => 'Matchday Africa', 'refresh_token' => 'refresh-1',
            'access_token' => 'access-1', 'expires_at' => now()->addHour(), 'scopes' => 'https://www.googleapis.com/auth/youtube.upload']);
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now('UTC')->subMinutes(110)->format('Y-m-d H:i:s'), 'status' => 'FINISHED', 'home_score' => 1, 'away_score' => 0,
        ]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->match->id, 'team_id' => $this->match->home_team_id, 'type' => 'goal', 'minute' => 41, 'player_name' => 'Bukayo Saka']);
        app(MatchStoryService::class)->syncRecent();

        Http::fake(fn (Request $r) => str_contains($r->url(), 'api.twitter.com/2/users/me')
            ? Http::response(['data' => ['id' => '1', 'name' => 'Matchday Africa', 'username' => 'matchdayafrica']])
            : Http::response(['data' => []]));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path("generated/reels/{$this->match->id}"));
        parent::tearDown();
    }

    private function assertNothingSentToSocial(): void
    {
        Http::assertNotSent(fn (Request $r) => in_array(parse_url($r->url(), PHP_URL_HOST), ['api.buffer.com', 'api.twitter.com', 'api.x.com'], true));
    }

    /** The GitHub renderer's file lands: the reel becomes ready the normal way. */
    private function finishReel(): void
    {
        $this->match->update(['metadata' => ['reel' => ['status' => 'dispatched', 'source' => 'github', 'started_at' => now()->subMinute()->toIso8601String()]]]);
        File::ensureDirectoryExists(public_path("generated/reels/{$this->match->id}"));
        file_put_contents(public_path("generated/reels/{$this->match->id}/{$this->match->id}.mp4"), str_repeat('x', 2048));
        $this->assertSame(1, app(ReelService::class)->collectUploads());
    }

    public function test_reels_still_go_to_youtube_and_drafts_are_made_but_nothing_is_posted(): void
    {
        $this->finishReel();
        Queue::assertPushed(UploadReelToYouTube::class, fn ($job) => true);

        $this->artisan('buffer:publish')->expectsOutput('Social autopost is off; nothing sent.')->assertSuccessful();
        $this->artisan('twitter:engage')->assertSuccessful();
        $this->artisan('griot:challenge')->assertSuccessful();
        $this->artisan('buffer:publish')->assertSuccessful();

        $reelPosts = GrowthPost::where('content_key', 'like', "reel:{$this->match->id}%")->get();
        $this->assertNotEmpty($reelPosts, 'the reel post is still planned');
        $this->assertSame(['draft'], $reelPosts->pluck('status')->unique()->values()->all());
        $this->assertSame(0, GrowthPost::whereIn('status', ['queued', 'sending', 'sent', 'published'])->count());
        $this->assertSame(0, \DB::table('social_posts')->count());
        $this->assertNothingSentToSocial();
    }

    public function test_the_services_refuse_to_send_on_their_own(): void
    {
        $this->assertSame([0, 0], app(BufferPublisher::class)->publishPending(), 'the live goal card is held back');
        $this->assertSame([0, 0], app(BufferPublisher::class)->publishTimeline());

        $result = app(BufferClient::class)->createPost('chan-x', 'x', 'hello');
        $this->assertTrue($result['disabled']);
        $this->assertTrue(app(BufferClient::class)->query('mutation DeletePost { deletePost }')['disabled'] ?? false);

        $tweet = app(TwitterService::class)->postTweet('hello');
        $this->assertFalse($tweet['success']);
        $this->assertSame(TwitterService::DISABLED_MESSAGE, $tweet['error']);
        $this->assertCount(2, app(TwitterService::class)->postMultipleTweets(['a', 'b'], 0));
        $this->assertNothingSentToSocial();
    }

    public function test_reading_buffer_channels_still_works(): void
    {
        app(BufferClient::class)->listOrganizations();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.buffer.com') && str_contains($r['query'], 'GetOrganizations'));
    }

    public function test_twitter_commands_say_posting_is_disabled(): void
    {
        $this->artisan('twitter:tweet-matches')->expectsOutput(TwitterService::DISABLED_MESSAGE)->assertSuccessful();
        $this->artisan('twitter:test-oauth2 --tweet')->expectsOutput('2. '.TwitterService::DISABLED_MESSAGE);
        $this->artisan('twitter:test --tweet')->expectsOutput(TwitterService::DISABLED_MESSAGE);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/2/tweets'));
    }

    public function test_redrawn_reels_are_not_marked_for_reposting(): void
    {
        $this->match->update(['metadata' => ['reel' => ['status' => 'ready', 'style' => 'classic', 'ready_at' => now()->toIso8601String()]]]);
        app(ReelService::class)->queue($this->match->fresh(), repost: true);

        $this->assertNull(data_get($this->match->fresh()->metadata, 'reel.repost'));
    }
}
