<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Models\YoutubeBroadcast;
use App\Services\YouTube\YouTubeBroadcasts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeLiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $lifeCycle = 'ready';

    protected function setUp(): void
    {
        parent::setUp();
        // Saturday 7 Nov 2026, 09:00 UTC.
        $this->travelTo(Carbon::parse('2026-11-07 09:00:00', 'UTC'));
        config(['youtube.client_id' => 'client-123', 'youtube.client_secret' => 'secret-456', 'youtube.channel_id' => 'UC123', 'stream.competitions' => ['PL'], 'youtube.privacy' => 'unlisted']);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        // Saturday 12:30, 15:00, 17:30 UTC and Sunday 14:00 UTC (match_date is UTC wall-clock).
        foreach ([['Liverpool FC', 'Arsenal FC', '2026-11-07 12:30:00'], ['Chelsea FC', 'Everton FC', '2026-11-07 15:00:00'],
            ['Fulham FC', 'Brentford FC', '2026-11-07 17:30:00'], ['Leeds United FC', 'Sunderland AFC', '2026-11-08 14:00:00']] as [$h, $a, $at]) {
            FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => Team::create(['name' => $h])->id,
                'away_team_id' => Team::create(['name' => $a])->id, 'match_date' => $at, 'status' => 'TIMED']);
        }
    }

    private function fakeGoogle(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_starts_with($url, 'https://oauth2.googleapis.com/token') => Http::response(['access_token' => 'access-1', 'expires_in' => 3600, 'refresh_token' => 'refresh-1']),
                str_starts_with($url, 'https://oauth2.googleapis.com/revoke') => Http::response([]),
                str_contains($url, '/channels') => Http::response(['items' => [['id' => 'UC123', 'snippet' => ['title' => 'Matchday Africa']]]]),
                str_contains($url, '/liveStreams') && $request->method() === 'GET' && str_contains($url, 'mine=true') => Http::response(['items' => []]),
                str_contains($url, '/liveStreams') && $request->method() === 'GET' => Http::response(['items' => [['status' => ['streamStatus' => 'inactive']]]]),
                str_contains($url, '/liveStreams') => Http::response(['id' => 'stream-9', 'cdn' => ['ingestionInfo' => ['streamName' => 'abcd-efgh-ijkl', 'ingestionAddress' => 'rtmp://a.rtmp.youtube.com/live2']]]),
                str_contains($url, '/liveBroadcasts/bind') => Http::response(['id' => 'x']),
                str_contains($url, '/liveBroadcasts/transition') => Http::response(['id' => 'x']),
                str_contains($url, '/liveBroadcasts') && $request->method() === 'GET' => Http::response(['items' => [['status' => ['lifeCycleStatus' => $this->lifeCycle]]]]),
                str_contains($url, '/liveBroadcasts') => Http::response(['id' => 'bc-'.substr(md5($request->body()), 0, 6)]),
                default => Http::response([], 404),
            };
        });
    }

    private function connect(): void
    {
        $this->fakeGoogle();
        $redirect = $this->actingAs($this->admin)->get('/admin/youtube/connect');
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->actingAs($this->admin)->get('/admin/youtube/callback?'.http_build_query(['code' => 'code-1', 'state' => $query['state']]))
            ->assertRedirect('/admin/youtube');
    }

    public function test_the_page_explains_the_google_setup_until_the_client_is_set(): void
    {
        config(['youtube.client_id' => null]);
        $this->actingAs($this->admin)->get('/admin/youtube')->assertOk()
            ->assertSee('YouTube Data API v3')->assertSee(route('admin.youtube.callback'))->assertDontSee('Connect YouTube</a>', false);
        $this->actingAs(User::factory()->create())->get('/admin/youtube')->assertForbidden();
    }

    public function test_connecting_stores_the_channel_and_stream_key_encrypted(): void
    {
        $this->actingAs($this->admin)->get('/admin/youtube')->assertSee('Connect YouTube');
        $this->fakeGoogle();
        $redirect = $this->actingAs($this->admin)->get('/admin/youtube/connect')->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString(urlencode(route('admin.youtube.callback')), $location);

        // A callback without the matching state is refused.
        $this->actingAs($this->admin)->get('/admin/youtube/callback?code=x&state=wrong')->assertRedirect('/admin/youtube')->assertSessionHas('error');
        $this->assertSame(0, YoutubeAccount::count());

        $this->connect();
        $account = YoutubeAccount::first();
        $this->assertSame('Matchday Africa', $account->channel_title);
        $this->assertSame('refresh-1', $account->refresh_token);
        $this->assertSame('abcd-efgh-ijkl', $account->stream_key);
        $raw = DB::table('youtube_accounts')->first();
        $this->assertStringNotContainsString('refresh-1', $raw->refresh_token);
        $this->assertStringNotContainsString('abcd-efgh-ijkl', $raw->stream_key);

        $this->actingAs($this->admin)->get('/admin/youtube')->assertOk()->assertSee('Matchday Africa')
            ->assertSee('rtmp://a.rtmp.youtube.com/live2')->assertSee('Inactive')->assertSee('Schedule now');

        $this->actingAs($this->admin)->post('/admin/youtube/disconnect')->assertRedirect();
        $this->assertSame(0, YoutubeAccount::count());
    }

    public function test_matchdays_become_windows(): void
    {
        $windows = app(YouTubeBroadcasts::class)->windows();

        $this->assertCount(2, $windows);
        $this->assertSame('2026-11-07', $windows[0]['key']);
        $this->assertSame('2026-11-07 12:15', $windows[0]['start']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-07 19:55', $windows[0]['end']->format('Y-m-d H:i'));
        $this->assertCount(3, $windows[0]['matches']);
        $this->assertSame('2026-11-08', $windows[1]['key']);
        $this->assertStringContainsString('Premier League scores', app(YouTubeBroadcasts::class)->title($windows[0]));
        $this->assertStringContainsString('13:30 Liverpool v Arsenal', app(YouTubeBroadcasts::class)->description($windows[0]));
    }

    public function test_broadcasts_are_made_once_per_matchday_and_ended_after(): void
    {
        $this->connect();
        config(['youtube.auto' => true]);

        $this->artisan('youtube:broadcasts')->assertSuccessful();
        $this->assertSame(['2026-11-07', '2026-11-08'], YoutubeBroadcast::orderBy('starts_at')->pluck('window_key')->all());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/liveBroadcasts/bind') && str_contains($r->url(), 'streamId=stream-9'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/liveBroadcasts?') && $r->method() === 'POST'
            && $r['contentDetails']['enableAutoStart'] === true && $r['status']['privacyStatus'] === 'unlisted');

        $this->artisan('youtube:broadcasts');
        $this->assertSame(2, YoutubeBroadcast::count());

        // After Saturday's window the live broadcast is ended.
        $this->lifeCycle = 'live';
        $this->travelTo(Carbon::parse('2026-11-07 20:00:00', 'UTC'));
        $this->artisan('youtube:broadcasts');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/liveBroadcasts/transition') && str_contains($r->url(), 'broadcastStatus=complete'));
        $this->assertSame('complete', YoutubeBroadcast::where('window_key', '2026-11-07')->value('status'));
        $this->assertSame('scheduled', YoutubeBroadcast::where('window_key', '2026-11-08')->value('status'));
    }

    public function test_nothing_is_scheduled_automatically_unless_switched_on(): void
    {
        $this->connect();
        $this->artisan('youtube:broadcasts')->assertSuccessful();
        $this->assertSame(0, YoutubeBroadcast::count());

        // The admin can still schedule a matchday, or make a private test.
        $this->actingAs($this->admin)->post('/admin/youtube/schedule', ['window' => '2026-11-08'])->assertSessionHas('success');
        $this->actingAs($this->admin)->post('/admin/youtube/test')->assertSessionHas('success');
        $this->assertSame(['2026-11-08', 'private'], [YoutubeBroadcast::first()->window_key, YoutubeBroadcast::latest('id')->first()->privacy]);
        $this->actingAs($this->admin)->post('/admin/youtube/schedule', ['window' => '2026-11-08'])->assertSessionHas('error');
    }
}
