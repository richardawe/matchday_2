<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GuestPrediction;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Support\Attribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SignupDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'Africa/Lagos'));
        $this->admin = User::factory()->create(['is_admin' => true, 'created_at' => now()->subDays(60)]);
    }

    private function signup(string $when, ?string $source, ?string $campaign = null, ?string $landing = null, ?string $content = null): User
    {
        return User::factory()->create(['created_at' => Carbon::parse($when, 'Africa/Lagos'), 'utm_source' => $source,
            'utm_medium' => $source ? 'social' : null, 'utm_campaign' => $campaign, 'signup_landing' => $landing, 'utm_content' => $content]);
    }

    private function fixture(): void
    {
        $this->signup('2026-11-14 09:00', 'tiktok', 'bio', '/start');
        $this->signup('2026-11-14 10:00', 'tiktok', 'bio', '/start', 'slideshow-4');
        $this->signup('2026-11-14 11:00', 'instagram', 'bio', '/start');
        $this->signup('2026-11-13 20:00', 'x', 'reels', '/reels/9');
        $this->signup('2026-11-13 21:00', 'youtube', 'reels', '/reels/9');
        $this->signup('2026-11-13 22:00', null, null, '/');
        $this->signup('2026-11-01 12:00', 'tiktok', 'bio', '/start'); // 13 days ago: in 28/30, not in 7
    }

    public function test_the_day_by_source_table_counts_each_source_per_day(): void
    {
        $this->fixture();

        $html = $this->actingAs($this->admin)->get(route('admin.users.index', ['window' => 7]))->assertOk()
            ->assertSee('Signups per day, by source.')
            ->getContent();
        $this->assertMatchesRegularExpression('#window=7"\s+aria-current="page"\s*>7 days</a>#', $html);

        $this->assertMatchesRegularExpression('#<th>Date</th><th>Total</th><th>tiktok</th>#', $html);
        $headers = $this->headers($html);
        $this->assertSame(['Date', 'Total', 'tiktok', 'direct', 'instagram', 'x', 'youtube', 'Premium starts'], $headers);
        $sat = $this->row($html, 'Sat 14 Nov', $headers);
        $this->assertEquals(['Total' => '3', 'tiktok' => '2', 'instagram' => '1', 'x' => '0', 'youtube' => '0', 'direct' => '0'], array_intersect_key($sat, array_flip(['Total', 'tiktok', 'instagram', 'x', 'youtube', 'direct'])));
        $fri = $this->row($html, 'Fri 13 Nov', $headers);
        $this->assertEquals(['Total' => '3', 'tiktok' => '0', 'instagram' => '0', 'x' => '1', 'youtube' => '1', 'direct' => '1'], array_intersect_key($fri, array_flip(['Total', 'tiktok', 'instagram', 'x', 'youtube', 'direct'])));
        // A quiet day in the window is listed with zeros.
        $this->assertSame('0', $this->row($html, 'Thu 12 Nov', $headers)['Total']);
        // The 7-day window leaves out 1 Nov.
        $this->assertStringNotContainsString('Sun 1 Nov', $this->table($html));
        // Landing pages in the window.
        $this->assertMatchesRegularExpression('#<td>/start</td><td>3</td>#', $html);
        $this->assertStringContainsString('slideshow-4 (1)', $html);
    }

    public function test_the_wider_windows_include_older_signups(): void
    {
        $this->fixture();

        foreach ([28, 30] as $window) {
            $html = $this->actingAs($this->admin)->get(route('admin.users.index', ['window' => $window]))->assertOk()->getContent();
            $this->assertStringContainsString('Sun 1 Nov', $this->table($html));
            $this->assertSame('1', $this->row($html, 'Sun 1 Nov', $this->headers($html))['tiktok']);
        }
        // An unknown window falls back to 30 days.
        $this->actingAs($this->admin)->get(route('admin.users.index', ['window' => 5]))->assertOk()->assertSee('BY DAY AND SOURCE · 30 DAYS');
    }

    public function test_the_csv_matches_the_table(): void
    {
        $this->fixture();

        $response = $this->actingAs($this->admin)->get(route('admin.users.export', ['window' => 7]));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('signups-by-source-2026-11-14-7d.csv', $response->headers->get('content-disposition'));
        $lines = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(8, $lines); // header + 7 days
        $header = $lines[0];
        $this->assertSame(['date', 'total'], array_slice($header, 0, 2));
        $rows = collect(array_slice($lines, 1))->mapWithKeys(fn ($l) => [$l[0] => array_combine($header, $l)]);
        $this->assertSame(['2026-11-14', '2026-11-13', '2026-11-12', '2026-11-11', '2026-11-10', '2026-11-09', '2026-11-08'], $rows->keys()->all());
        $this->assertEquals(['date' => '2026-11-14', 'total' => '3', 'tiktok' => '2', 'instagram' => '1', 'x' => '0', 'youtube' => '0', 'direct' => '0'],
            array_intersect_key($rows['2026-11-14'], array_flip(['date', 'total', 'tiktok', 'instagram', 'x', 'youtube', 'direct'])));
        $this->assertSame('1', $rows['2026-11-13']['direct']);
        $this->assertSame('0', $rows['2026-11-12']['total']);
    }

    public function test_guest_picks_appear_by_source(): void
    {
        $pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $match = FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id,
            'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id, 'match_date' => now('UTC')->addDay(), 'status' => 'SCHEDULED']);
        $touch = json_encode(['utm_source' => 'tiktok', 'utm_medium' => 'social', 'utm_campaign' => 'bio', 'signup_landing' => '/start']);

        $this->withCookie(Attribution::COOKIE, $touch)->post(route('matches.prediction.store', $match), ['home_score' => 1, 'away_score' => 0]);
        $this->assertDatabaseHas('guest_predictions', ['match_id' => $match->id, 'utm_source' => 'tiktok']);
        GuestPrediction::create(['guest_token' => str_repeat('b', 40), 'match_id' => $match->id, 'home_score' => 0, 'away_score' => 0]);

        $this->actingAs($this->admin)->get(route('admin.users.index', ['window' => 7]))->assertOk()
            ->assertSee('Calls made without an account.')
            ->assertSee('<tr><td>tiktok</td><td>1</td><td>1</td><td>0</td></tr>', false)
            ->assertSee('<tr><td>direct</td><td>1</td><td>1</td><td>0</td></tr>', false);
    }

    public function test_register_from_start_stores_the_tags_and_landing_page(): void
    {
        $response = $this->get('/start?utm_source=tiktok&utm_medium=social&utm_campaign=bio&utm_content=launch');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Attribution::COOKIE);
        $raw = $cookie->getValue();
        try {
            $raw = \Illuminate\Cookie\CookieValuePrefix::remove(app('encrypter')->decrypt($raw, false));
        } catch (\Throwable) {
        }

        $this->withCookie(Attribution::COOKIE, $raw)->post('/register', [
            'name' => 'Ada', 'email' => 'ada-desk@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'ada-desk@example.com', 'utm_source' => 'tiktok', 'utm_medium' => 'social',
            'utm_campaign' => 'bio', 'utm_content' => 'launch', 'signup_landing' => '/start']);
    }

    public function test_email_signups_keep_utm_content(): void
    {
        $touch = json_encode(['utm_source' => 'tiktok', 'utm_medium' => 'social', 'utm_campaign' => 'bio', 'utm_content' => 'launch']);
        $this->withCookie(Attribution::COOKIE, $touch)->post(route('subscribe'), ['email' => 'fan-desk@example.com']);

        $this->assertDatabaseHas('email_subscribers', ['email' => 'fan-desk@example.com', 'utm_source' => 'tiktok', 'utm_content' => 'launch']);
        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertSee('tiktok · bio · ');
    }

    public function test_the_desk_and_csv_are_admin_only(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->get(route('admin.users.export'))->assertRedirect(route('login'));
        $fan = User::factory()->create();
        $this->actingAs($fan)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($fan)->get(route('admin.users.export'))->assertForbidden();
    }

    private function table(string $html): string
    {
        preg_match('#<table class="md-signup-table md-signup-daily">.*?</table>#s', $html, $m);

        return $m[0];
    }

    /** @return list<string> the daily table's header cells */
    private function headers(string $html): array
    {
        preg_match('#<table class="md-signup-table md-signup-daily"><thead><tr>(.*?)</tr>#s', $html, $m);
        preg_match_all('#<th>(.*?)</th>#', $m[1], $cells);

        return $cells[1];
    }

    private function row(string $html, string $date, array $headers): array
    {
        preg_match('#<tr><td>'.preg_quote($date, '#').'</td>(.*?)</tr>#s', $html, $m);
        $this->assertNotEmpty($m, "No row for {$date}");
        preg_match_all('#<td>(?:<b>)?(.*?)(?:</b>)?</td>#', '<td>'.$date.'</td>'.$m[1], $cells);

        return array_combine($headers, $cells[1]);
    }
}
