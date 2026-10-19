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
use Tests\TestCase;

class BufferPublisherTest extends TestCase
{
    use RefreshDatabase;

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
        Http::fake(['api.buffer.com*' => Http::response(['data' => ['createPost' => ['post' => ['id' => 'p1']]]])]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 2 sent, 0 failed')->assertSuccessful();
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r['query'], 'channelId: "chan-x"') && str_contains($r['query'], 'First blood')
            && str_contains($r['query'], 'image: { url: "'.url('/moments')) && str_contains($r['query'], 'mode: shareNow'));
        $this->assertSame(2, DB::table('social_posts')->where('status', 'sent')->count());
    }

    public function test_failures_are_kept_and_retried(): void
    {
        Http::fakeSequence('api.buffer.com*')
            ->push(['data' => ['createPost' => ['message' => 'Channel disconnected']]])
            ->push(['data' => ['createPost' => ['post' => ['id' => 'p2']]]])
            ->push(['data' => ['createPost' => ['post' => ['id' => 'p3']]]]);

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 1 failed')->assertFailed();
        $this->assertSame('Channel disconnected', DB::table('social_posts')->where('status', 'failed')->value('error'));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');
    }

    public function test_full_time_reel_goes_to_all_three_including_tiktok(): void
    {
        Http::fake(['api.buffer.com*' => Http::response(['data' => ['createPost' => ['post' => ['id' => 'p']]]])]);
        $this->match->update(['status' => 'FINISHED', 'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        File::ensureDirectoryExists(storage_path('app/reels'));
        File::put(storage_path("app/reels/{$this->match->id}.mp4"), 'mp4');

        $this->artisan('buffer:publish')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r['query'], 'channelId: "chan-tt"') && str_contains($r['query'], 'video: { url: "'.url("/matches/{$this->match->id}/reel.mp4")));
        $this->assertSame(['instagram', 'tiktok', 'x'], DB::table('social_posts')->where('subject', "reel:{$this->match->id}")->orderBy('channel')->pluck('channel')->all());
        File::delete(storage_path("app/reels/{$this->match->id}.mp4"));
    }

    public function test_nothing_is_sent_without_a_key(): void
    {
        config(['buffer.key' => null]);
        Http::fake();
        $this->artisan('buffer:publish')->expectsOutput('BUFFER_API_KEY is not set; nothing sent.');
        Http::assertNothingSent();
    }
}
