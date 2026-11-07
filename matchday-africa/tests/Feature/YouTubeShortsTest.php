<?php

namespace Tests\Feature;

use App\Jobs\UploadReelToYouTube;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\MatchMoment;
use App\Models\Team;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Services\YouTube\ReelUploads;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class YouTubeShortsTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL = 'UCJ9Asmir1hBOHHQs9qSCkHg';
    private const SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=abc';

    private League $pl;
    private array $files = [];
    /** @var array<int, array> */
    private array $initiated = [];
    private array $ranges = [];

    protected function setUp(): void
    {
        parent::setUp();
        // In the app's timezone: under a UTC test clock, Carbon would read dateTime columns back as UTC.
        $this->travelTo(Carbon::parse('2026-11-07 20:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        config([
            'app.url' => 'https://matchday.africa',
            'youtube.client_id' => 'client-123', 'youtube.client_secret' => 'secret-456', 'youtube.channel_id' => self::CHANNEL,
            'youtube.uploads.enabled' => true, 'youtube.uploads.daily_uploads' => 6, 'youtube.uploads.chunk_bytes' => 256 * 1024,
        ]);
        YoutubeAccount::create(['channel_id' => self::CHANNEL, 'channel_title' => 'Matchday Africa', 'refresh_token' => 'refresh-1',
            'access_token' => 'access-1', 'expires_at' => now()->addHour(), 'scopes' => 'https://www.googleapis.com/auth/youtube.upload']);
        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** A finished match with a ready reel of $bytes bytes. */
    private function match(string $home, string $away, int $h, int $a, int $bytes = 600 * 1024, bool $reel = true): FootballMatch
    {
        $match = FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => Team::create(['name' => $home])->id,
            'away_team_id' => Team::create(['name' => $away])->id, 'match_date' => '2026-11-07 15:00:00', 'status' => 'FINISHED',
            'home_score' => $h, 'away_score' => $a,
            'metadata' => $reel ? ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]] : []]);
        if ($reel) {
            @mkdir(storage_path('app/reels'), 0777, true);
            file_put_contents($file = storage_path("app/reels/{$match->id}.mp4"), str_repeat('x', $bytes));
            $this->files[] = $file;
        }

        return $match;
    }

    private function goal(FootballMatch $match, string $side, int $minute, string $player, bool $own = false): void
    {
        $event = MatchEvent::create(['football_data_id' => random_int(1, 1_000_000), 'match_id' => $match->id, 'type' => 'goal', 'minute' => $minute, 'player_name' => $player, 'is_own_goal' => $own,
            'team_id' => $side === 'home' ? $match->home_team_id : $match->away_team_id]);
        MatchMoment::create(['match_id' => $match->id, 'key' => "{$match->id}:goal:{$event->id}", 'type' => 'goal', 'side' => $side,
            'minute' => $minute, 'player_name' => $player, 'match_event_id' => $event->id]);
    }

    /** Google's resumable upload: 308 with the received range until the last chunk, then the video. */
    private function fakeYouTube(array $initStatus = [200, []], string $privacy = 'public'): void
    {
        Http::fake(function (Request $request) use ($initStatus, $privacy) {
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-2', 'expires_in' => 3600]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')) {
                [$status, $body] = $initStatus;
                if ($status !== 200) {
                    return Http::response($body, $status);
                }
                $this->initiated[] = ['body' => $request->data(), 'headers' => $request->headers()];

                return Http::response('', 200, ['Location' => self::SESSION]);
            }
            if ($request->method() === 'PUT' && $request->url() === self::SESSION) {
                $range = $request->header('Content-Range')[0];
                $this->ranges[] = $range;
                preg_match('~bytes (\d+)-(\d+)/(\d+)~', $range, $m);

                return (int) $m[2] + 1 === (int) $m[3]
                    ? Http::response(['id' => 'vid-'.count($this->initiated), 'status' => ['privacyStatus' => $privacy]], 201)
                    : Http::response('', 308, ['Range' => "bytes=0-{$m[2]}"]);
            }

            return Http::response([], 404);
        });
    }

    public function test_a_ready_reel_is_uploaded_as_a_public_short_with_a_factual_title_and_description(): void
    {
        $this->fakeYouTube();
        $match = $this->match('Arsenal FC', 'Chelsea FC', 2, 1);
        $this->goal($match, 'away', 9, 'Cole Palmer');
        $this->goal($match, 'home', 30, 'Bukayo Saka');

        app(ReelUploads::class)->reelReady($match); // the queue is sync in tests: the job runs now

        $match->refresh();
        $this->assertSame('vid-1', $match->youtube_video_id);
        $this->assertSame('uploaded', $match->youtube_status);
        $this->assertNotNull($match->youtube_uploaded_at);
        $this->assertNull($match->youtube_upload_session);

        $body = $this->initiated[0]['body'];
        $this->assertSame("Arsenal 2-1 Chelsea | Cole Palmer opens the scoring, 9' #Shorts", $body['snippet']['title']);
        $this->assertSame('17', $body['snippet']['categoryId']);
        $this->assertSame(['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false, 'embeddable' => true], $body['status']);
        $this->assertSame("Arsenal beat Chelsea 2-1 in the Premier League on 7 November 2026.\n\n"
            ."Watch the full match story: https://matchday.africa/matches/{$match->id}?utm_source=youtube&utm_medium=social&utm_campaign=reels\n\n"
            .'#MatchdayAfrica #PremierLeague', $body['snippet']['description']);
        $this->assertSame((string) (600 * 1024), $this->initiated[0]['headers']['X-Upload-Content-Length'][0]);
        // 600 KiB in 256 KiB chunks.
        $this->assertSame(['bytes 0-262143/614400', 'bytes 262144-524287/614400', 'bytes 524288-614399/614400'], $this->ranges);
        $this->assertSame(1, app(ReelUploads::class)->usedToday());
    }

    public function test_titles_for_draws_own_goals_and_long_names(): void
    {
        $uploads = app(ReelUploads::class);
        $draw = $this->match('Leeds United FC', 'Sunderland AFC', 1, 1, reel: false);
        $this->goal($draw, 'home', 12, 'Joe Rodon');
        $this->assertSame('Leeds United 1-1 Sunderland | Points shared #Shorts', $uploads->title($draw));
        $this->assertStringStartsWith('Leeds United and Sunderland drew 1-1 in the Premier League', $uploads->description($draw));

        $own = $this->match('Everton FC', 'Fulham FC', 0, 1, reel: false);
        $this->goal($own, 'away', 45, 'James Tarkowski', own: true);
        $this->assertSame("Everton 0-1 Fulham | James Tarkowski own goal opens the scoring, 45' #Shorts", $uploads->title($own));
        $this->assertStringStartsWith('Fulham won 1-0 at Everton', $uploads->description($own));

        $noScorer = $this->match('Brentford FC', 'Burnley FC', 3, 0, reel: false);
        $this->assertSame('Brentford 3-0 Burnley | Brentford win #Shorts', $uploads->title($noScorer));

        $long = $this->match(str_repeat('Wolverhampton Wanderers ', 2).'FC', 'Brighton & Hove Albion FC', 2, 0, reel: false);
        $this->goal($long, 'home', 90, str_repeat('Very Long Player Name ', 5));
        $title = $uploads->title($long);
        $this->assertLessThanOrEqual(100, mb_strlen($title));
        $this->assertStringEndsWith(' #Shorts', $title);
    }

    public function test_nothing_is_uploaded_twice(): void
    {
        $this->fakeYouTube();
        $match = $this->match('Arsenal FC', 'Chelsea FC', 0, 0);
        $match->forceFill(['youtube_video_id' => 'already', 'youtube_status' => 'uploaded'])->save();

        app(ReelUploads::class)->reelReady($match);
        $this->artisan('youtube:uploads')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('already', $match->fresh()->youtube_video_id);
    }

    public function test_the_daily_quota_holds_the_rest_for_the_next_day(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        config(['youtube.uploads.daily_uploads' => 1]);
        $older = $this->match('Arsenal FC', 'Chelsea FC', 1, 0);
        $newer = $this->match('Liverpool FC', 'Everton FC', 2, 0);
        $newer->forceFill(['match_date' => '2026-11-07 17:30:00'])->save();
        $uploads = app(ReelUploads::class);
        $uploads->reelReady($older);
        $uploads->reelReady($newer);
        Queue::assertPushed(UploadReelToYouTube::class, 2);

        $this->artisan('youtube:uploads')->expectsOutputToContain('1 uploaded, 1 waiting')->assertSuccessful();
        $this->assertNotNull($newer->fresh()->youtube_video_id); // newest first
        $this->assertSame('pending', $older->fresh()->youtube_status);

        // After midnight Pacific time the quota is back.
        $this->travelTo(Carbon::parse('2026-11-08 08:05:00', 'UTC')->setTimezone('Africa/Lagos'));
        $this->artisan('youtube:uploads')->assertSuccessful();
        $this->assertNotNull($older->fresh()->youtube_video_id);
    }

    public function test_youtube_quota_errors_wait_for_the_next_day_without_using_an_attempt(): void
    {
        $this->fakeYouTube([403, ['error' => ['code' => 403, 'message' => 'The request cannot be completed because you have exceeded your quota.', 'errors' => [['reason' => 'quotaExceeded']]]]]);
        $match = $this->match('Arsenal FC', 'Chelsea FC', 1, 0);

        app(ReelUploads::class)->reelReady($match);

        $match->refresh();
        $this->assertSame('pending', $match->youtube_status);
        $this->assertSame(0, $match->youtube_attempts);
        $this->assertTrue($match->youtube_next_at->equalTo(Carbon::parse('2026-11-08 08:00:00', 'UTC')));
        $this->assertSame(0, app(ReelUploads::class)->left());
    }

    public function test_failures_are_retried_three_times_then_shown_as_failed(): void
    {
        $this->fakeYouTube([500, ['error' => ['code' => 500, 'message' => 'Backend Error']]]);
        $match = $this->match('Arsenal FC', 'Chelsea FC', 1, 0);
        app(ReelUploads::class)->reelReady($match);
        $this->assertSame(['pending', 1], [$match->fresh()->youtube_status, $match->fresh()->youtube_attempts]);
        $this->assertLessThan(2, abs($match->fresh()->youtube_next_at->diffInSeconds(now()->addMinutes(5))));

        foreach ([20, 60, 0] as $wait) {
            $this->travel(61)->minutes();
            $this->artisan('youtube:uploads')->assertSuccessful();
        }
        $match->refresh();
        $this->assertSame('failed', $match->youtube_status);
        $this->assertSame(4, $match->youtube_attempts);
        $this->assertStringContainsString('Backend Error', $match->youtube_error);

        // A retry from the admin page starts again.
        Http::fake(fn () => Http::response([], 404));
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/youtube/uploads')->assertOk()->assertSee('Backend Error')->assertSee('Retry upload');
        $this->actingAs($admin)->post("/admin/youtube/uploads/{$match->id}/retry")->assertRedirect()->assertSessionHas('success');
        $this->assertSame(['pending', 0], [$match->fresh()->youtube_status, $match->fresh()->youtube_attempts]);
        Queue::assertPushed(UploadReelToYouTube::class, fn ($job) => $job->matchId === $match->id);
    }

    public function test_an_interrupted_upload_resumes_its_session(): void
    {
        $match = $this->match('Arsenal FC', 'Chelsea FC', 1, 0);
        $match->forceFill(['youtube_status' => 'uploading', 'youtube_next_at' => now()->subMinute(),
            'youtube_upload_session' => ['size' => 600 * 1024, 'url' => self::SESSION]])->save();
        // The status query answers that the first chunk arrived.
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-2', 'expires_in' => 3600]);
            }
            $range = $request->header('Content-Range')[0] ?? '';
            $this->ranges[] = $range;
            if ($range === 'bytes */614400') {
                return Http::response('', 308, ['Range' => 'bytes=0-262143']);
            }
            preg_match('~bytes (\d+)-(\d+)/(\d+)~', $range, $m);

            return (int) $m[2] + 1 === (int) $m[3] ? Http::response(['id' => 'resumed', 'status' => ['privacyStatus' => 'public']], 200) : Http::response('', 308, ['Range' => "bytes=0-{$m[2]}"]);
        });

        $this->artisan('youtube:uploads')->assertSuccessful();

        $this->assertSame('resumed', $match->fresh()->youtube_video_id);
        $this->assertSame(['bytes */614400', 'bytes 262144-524287/614400', 'bytes 524288-614399/614400'], $this->ranges);
        $this->assertSame(0, app(ReelUploads::class)->usedToday()); // resuming costs no new insert
    }

    public function test_an_upload_youtube_locks_to_private_is_flagged(): void
    {
        $this->fakeYouTube(privacy: 'private');
        $match = $this->match('Arsenal FC', 'Chelsea FC', 1, 0);
        app(ReelUploads::class)->reelReady($match);
        $this->assertSame('uploaded', $match->fresh()->youtube_status);
        $this->assertStringContainsString('audit', $match->fresh()->youtube_error);
    }

    public function test_uploads_stay_off_until_switched_on_and_never_go_to_another_channel(): void
    {
        $this->fakeYouTube();
        $match = $this->match('Arsenal FC', 'Chelsea FC', 1, 0);

        config(['youtube.uploads.enabled' => false]);
        app(ReelUploads::class)->reelReady($match);
        config(['youtube.uploads.enabled' => true, 'youtube.channel_id' => 'UCsomeoneelse0000000000']);
        app(ReelUploads::class)->reelReady($match);

        Http::assertNothingSent();
        $this->assertNull($match->fresh()->youtube_status);
    }

    public function test_backfill_queues_recent_ready_reels(): void
    {
        $this->fakeYouTube();
        $recent = $this->match('Arsenal FC', 'Chelsea FC', 1, 0);
        $old = $this->match('Liverpool FC', 'Everton FC', 1, 0);
        $old->forceFill(['match_date' => '2026-10-20 15:00:00'])->save();
        $noReel = $this->match('Fulham FC', 'Burnley FC', 1, 0, reel: false);

        $this->artisan('youtube:backfill', ['--days' => 7])->expectsOutputToContain('Queued 1 reels')->assertSuccessful();

        $this->assertNotNull($recent->fresh()->youtube_video_id);
        $this->assertNull($old->fresh()->youtube_status);
        $this->assertNull($noReel->fresh()->youtube_status);
    }

    public function test_the_migration_marks_the_reels_already_on_youtube(): void
    {
        $team = fn ($n) => Team::create(['name' => $n])->id;
        FootballMatch::query()->insert(['id' => 5085, 'league_id' => $this->pl->id, 'home_team_id' => $team('A'), 'away_team_id' => $team('B'),
            'match_date' => '2026-11-01 15:00:00', 'status' => 'FINISHED', 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_11_07_000001_add_youtube_shorts_uploads.php'))->up();

        $match = FootballMatch::find(5085);
        $this->assertSame('se0Iqt-b7wE', $match->youtube_video_id);
        $this->assertSame('uploaded', $match->youtube_status);
    }

    public function test_connecting_another_channel_is_refused(): void
    {
        YoutubeAccount::query()->delete();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600, 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/youtube.upload']),
            '*/channels*' => Http::response(['items' => [['id' => 'UCother', 'snippet' => ['title' => 'Personal']]]]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $redirect = $this->actingAs($admin)->get('/admin/youtube/connect')->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertStringContainsString('youtube.upload', $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);

        $this->actingAs($admin)->get('/admin/youtube/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]))
            ->assertRedirect('/admin/youtube')->assertSessionHas('error');
        $this->assertSame(0, YoutubeAccount::count());
    }

    public function test_an_upload_only_channel_connects_without_a_live_stream_key(): void
    {
        YoutubeAccount::query()->delete();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600, 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/youtube.upload']),
            '*/channels*' => Http::response(['items' => [['id' => self::CHANNEL, 'snippet' => ['title' => 'Matchday Africa']]]]),
            '*/liveStreams*' => Http::response(['error' => ['message' => 'The user is not enabled for live streaming.']], 403),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $redirect = $this->actingAs($admin)->get('/admin/youtube/connect');
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->actingAs($admin)->get('/admin/youtube/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]))
            ->assertRedirect('/admin/youtube')->assertSessionHas('success');

        $account = YoutubeAccount::first();
        $this->assertNull($account->stream_id);
        $this->assertTrue(app(ReelUploads::class)->enabled());
        Cache::flush();
    }
}
