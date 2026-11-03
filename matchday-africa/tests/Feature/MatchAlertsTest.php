<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchAlert;
use App\Models\MatchEvent;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\MatchAlerts;
use App\Services\Alerts\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatchAlertsTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;
    private User $fan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-07 14:40:00');
        // Any competition: alerts are not limited to the Premier League.
        $league = League::create(['football_data_id' => 2014, 'short_code' => 'PD', 'name' => 'La Liga']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id,
            'home_team_id' => Team::create(['name' => 'Real Betis'])->id,
            'away_team_id' => Team::create(['name' => 'Sevilla FC'])->id,
            // match_date holds UTC wall-clock time; kick-off in 20 minutes.
            'match_date' => now('UTC')->addMinutes(20)->format('Y-m-d H:i:s'), 'status' => 'TIMED',
        ]);
        $this->fan = User::factory()->create();
    }

    private function titles(User $user): array
    {
        return $user->notifications()->get()->sortBy('created_at')->pluck('data.title')->values()->all();
    }

    public function test_guests_are_told_they_need_an_account(): void
    {
        $this->get(route('matches.show', $this->match))->assertOk()->assertSee('You need an account to get match alerts.');
        $this->postJson(route('matches.alert', $this->match))->assertUnauthorized();
    }

    public function test_a_followed_match_is_told_once_each_step(): void
    {
        $this->actingAs($this->fan)->postJson(route('matches.alert', $this->match), ['on' => true])->assertOk()->assertJson(['on' => true]);
        $other = User::factory()->create(); // didn't ask: gets nothing
        $alerts = app(MatchAlerts::class);

        $alerts->scan();
        $this->assertSame(['Real Betis v Sevilla soon'], $this->titles($this->fan));

        $this->travel(21)->minutes();
        $this->match->update(['status' => 'IN_PLAY', 'home_score' => 0, 'away_score' => 0]);
        $alerts->scan();
        $alerts->scan();
        $this->match->update(['home_score' => 1]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->match->id, 'team_id' => $this->match->home_team_id, 'type' => 'goal', 'minute' => 23, 'player_name' => 'Isco']);
        MatchEvent::create(['football_data_id' => 2, 'match_id' => $this->match->id, 'team_id' => $this->match->away_team_id, 'type' => 'red_card', 'minute' => 30, 'player_name' => 'Badé']);
        $alerts->scan();
        $this->match->update(['status' => 'PAUSED']);
        $alerts->scan();
        $this->match->update(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1]);
        $alerts->scan();
        $alerts->scan();

        $this->assertSame([
            'Real Betis v Sevilla soon', 'Kick-off!', 'GOAL! Real Betis 1–0 Sevilla', 'Red card! Sevilla down to ten',
            'Half-time: Real Betis 1–0 Sevilla', 'GOAL! Real Betis 2–1 Sevilla', 'Full time: Real Betis 2–1 Sevilla',
        ], $this->titles($this->fan));
        $this->assertSame(0, $other->notifications()->count());
        $this->assertStringContainsString('Isco 23′', $this->fan->notifications()->get()->firstWhere('data.title', 'GOAL! Real Betis 1–0 Sevilla')->data['body']);
        $this->assertSame(route('matches.show', $this->match), $this->fan->notifications()->first()->data['url']);
    }

    public function test_following_a_match_under_way_does_not_replay_it(): void
    {
        $this->travel(50)->minutes();
        $this->match->update(['status' => 'IN_PLAY', 'home_score' => 2, 'away_score' => 0]);

        app(MatchAlerts::class)->follow($this->fan, $this->match);
        app(MatchAlerts::class)->scan();
        $this->assertSame([], $this->titles($this->fan));

        $this->match->update(['home_score' => 2, 'away_score' => 1]);
        app(MatchAlerts::class)->scan();
        $this->assertSame(['GOAL! Real Betis 2–1 Sevilla'], $this->titles($this->fan));
    }

    public function test_muted_users_and_unfollowed_matches_get_nothing(): void
    {
        $muted = User::factory()->create();
        NotificationPreference::create(['user_id' => $muted->id, 'match_alerts' => false]);
        app(MatchAlerts::class)->follow($muted, $this->match);
        $this->actingAs($this->fan)->postJson(route('matches.alert', $this->match), ['on' => true]);
        $this->actingAs($this->fan)->postJson(route('matches.alert', $this->match), ['on' => false])->assertJson(['on' => false]);

        app(MatchAlerts::class)->scan();

        $this->assertSame(0, $muted->notifications()->count() + $this->fan->notifications()->count());
        $this->assertSame(1, MatchAlert::count());
    }

    public function test_alerts_go_to_phones_and_dead_subscriptions_are_dropped(): void
    {
        config(['push.enabled' => true, 'push.vapid' => WebPush::createKeys() + ['subject' => 'mailto:hello@matchday.africa']]);
        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'updates.push.services.mozilla.com/*' => Http::response('', 410),
        ]);
        $browser = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($browser)['ec'];
        $keys = ['p256dh' => WebPush::b64("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => WebPush::b64(random_bytes(16))];
        $this->actingAs($this->fan)->postJson(route('push.subscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/phone', 'keys' => $keys])->assertOk();
        $this->actingAs($this->fan)->postJson(route('push.subscribe'), ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/old', 'keys' => $keys])->assertOk();
        $this->actingAs($this->fan)->postJson(route('matches.alert', $this->match), ['on' => true])->assertJson(['push' => true, 'devices' => 2]);

        app(MatchAlerts::class)->scan();

        Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/fcm/send/phone' && $r->hasHeader('Content-Encoding', 'aes128gcm')
            && str_starts_with($r->header('Authorization')[0], 'vapid t=') && $r->header('Topic')[0] === "match-{$this->match->id}");
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/phone'], PushSubscription::pluck('endpoint')->all(), 'the 410 one is gone');
    }

    public function test_subscriptions_must_be_https_and_need_push_on(): void
    {
        $keys = ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)];
        $this->actingAs($this->fan)->postJson(route('push.subscribe'), ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => $keys])->assertNotFound();

        config(['push.enabled' => true, 'push.vapid' => WebPush::createKeys() + ['subject' => 'mailto:x@y.z']]);
        $this->actingAs($this->fan)->postJson(route('push.subscribe'), ['endpoint' => 'http://evil.test/x', 'keys' => $keys])->assertUnprocessable();
    }

    public function test_inbox_lists_alerts_and_marks_them_read(): void
    {
        app(MatchAlerts::class)->follow($this->fan, $this->match);
        app(MatchAlerts::class)->scan();

        $this->actingAs($this->fan)->get(route('notifications.index'))->assertOk()->assertSee('Real Betis v Sevilla soon')->assertSee('Stop alerts');
        $this->actingAs($this->fan)->get(route('matches.show', $this->match))->assertSee('md-bell-count', false)->assertSee('Alerts on');
        $note = $this->fan->notifications()->first();
        $this->actingAs($this->fan)->get(route('notifications.open', $note->id))->assertRedirect(route('matches.show', $this->match));
        $this->assertNotNull($note->fresh()->read_at);
    }

    public function test_admins_message_users(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->fan)->get(route('admin.messages.index'))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.messages.index'))->assertOk()->assertSee('Messages');
        $this->actingAs($admin)->post(route('admin.messages.store'), ['title' => 'Radio is live', 'body' => 'Three bulletins a day.', 'audience' => 'all'])
            ->assertSessionHas('success', 'Sent to 2 inboxes.');

        $this->assertSame(['Radio is live'], $this->titles($this->fan));
        $this->assertSame('announcement', $this->fan->notifications()->first()->data['type']);
        $this->assertDatabaseHas('announcements', ['title' => 'Radio is live', 'recipients' => 2, 'pushed' => false]);
    }
}
