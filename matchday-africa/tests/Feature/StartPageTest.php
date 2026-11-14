<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GriotChallenge;
use App\Models\League;
use App\Models\Player;
use App\Models\PlayerHeritage;
use App\Models\Team;
use App\Support\Attribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StartPageTest extends TestCase
{
    use RefreshDatabase;

    private const BIO = 'utm_source=tiktok&utm_medium=social&utm_campaign=bio';

    private League $pl;
    private Team $arsenal;
    private Team $chelsea;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 10:00:00', 'UTC'));
        config(['app.url' => 'https://matchday.africa', 'griot_challenge.enabled' => false]);
        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->arsenal = Team::create(['name' => 'Arsenal FC']);
        $this->chelsea = Team::create(['name' => 'Chelsea FC']);
    }

    protected function tearDown(): void
    {
        File::delete($this->files);
        parent::tearDown();
    }

    public function test_start_is_a_landing_page_with_its_own_title(): void
    {
        $this->get('/start')->assertOk()
            ->assertSee('<title>Start here: African players, reels and Beat the Griot | Matchday Africa</title>', false)
            ->assertSee('African footballers, every matchday.')
            ->assertSee('width=device-width', false);
    }

    public function test_old_bio_links_redirect_to_start_with_their_tags(): void
    {
        foreach (['/tiktok', '/watch'] as $alias) {
            $response = $this->get($alias.'?'.self::BIO);
            $response->assertStatus(301);
            $location = $response->headers->get('Location');
            $this->assertSame('/start', parse_url($location, PHP_URL_PATH));
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            $this->assertEquals(['utm_source' => 'tiktok', 'utm_medium' => 'social', 'utm_campaign' => 'bio'], $query);
        }
        $this->get('/watch')->assertRedirect('/start');
    }

    public function test_arriving_from_the_bio_is_remembered_through_a_guest_pick_and_signup(): void
    {
        $match = $this->upcoming();
        $response = $this->get('/start?'.self::BIO)->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Attribution::COOKIE);
        $this->assertNotNull($cookie);
        $touch = $this->decode($cookie->getValue());
        $this->assertSame(['utm_source' => 'tiktok', 'utm_medium' => 'social', 'utm_campaign' => 'bio', 'signup_landing' => '/start'], $touch);

        $this->withCookie(Attribution::COOKIE, json_encode($touch))
            ->post(route('matches.prediction.store', $match), ['home_score' => 2, 'away_score' => 1]);
        $this->assertDatabaseHas('guest_predictions', ['match_id' => $match->id, 'utm_source' => 'tiktok']);

        $this->withCookie(Attribution::COOKIE, json_encode($touch))->post('/register', [
            'name' => 'Ada', 'email' => 'ada-start@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'ada-start@example.com', 'utm_source' => 'tiktok',
            'utm_medium' => 'social', 'utm_campaign' => 'bio', 'signup_landing' => '/start']);
    }

    public function test_an_open_griot_round_is_the_one_primary_action_with_tags_carried(): void
    {
        config(['griot_challenge.enabled' => true]);
        $this->upcoming();
        GriotChallenge::forceCreate(['status' => 'open', 'first_kickoff' => '2026-11-14 15:00:00', 'last_kickoff' => '2026-11-15 16:30:00']);

        $html = $this->get('/start?'.self::BIO)->assertOk()->getContent();
        $this->assertStringContainsString('href="/beat-the-griot?utm_source=tiktok&amp;utm_medium=social&amp;utm_campaign=bio"', $html);
        $this->assertStringContainsString('Enter Beat the Griot', $html);
        $this->assertStringNotContainsString('data-track="start_match"', $html);
        $this->assertSame(1, substr_count($html, 'md-start-go'));
    }

    public function test_without_a_round_the_button_calls_the_next_match_with_african_players(): void
    {
        $match = $this->upcoming();
        Player::create(['team_id' => $this->arsenal->id, 'name' => 'Thomas Partey', 'nationality' => 'Ghana', 'nationality_code' => 'GHA', 'is_active' => true]);

        $this->get('/start?utm_source=instagram&utm_medium=social')->assertOk()
            ->assertSee('data-track="start_match"', false)
            ->assertSee('href="/matches/'.$match->id.'?utm_source=instagram&amp;utm_medium=social&amp;utm_campaign=predictions#prediction-arena"', false)
            ->assertDontSee('Get match alerts');
    }

    public function test_with_no_round_and_no_match_it_offers_alerts_by_email_and_an_account(): void
    {
        $html = $this->get('/start?'.self::BIO)->assertOk()->getContent();
        $this->assertStringContainsString('Get match alerts', $html);
        $this->assertStringContainsString('action="'.route('subscribe').'"', $html);
        $this->assertStringContainsString('Create my free account', $html);
        $this->assertStringContainsString(route('register', ['utm_source' => 'tiktok', 'utm_medium' => 'social', 'utm_campaign' => 'bio']), html_entity_decode($html));
        $this->assertStringContainsString('data-track="start_alerts"', $html);
        $this->assertStringNotContainsString('data-track="start_match"', $html);
    }

    public function test_only_in_scope_players_appear_and_missing_stats_are_left_out(): void
    {
        FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $this->arsenal->id, 'away_team_id' => $this->chelsea->id,
            'match_date' => '2026-11-14 12:30:00', 'status' => 'IN_PLAY']);
        $today = now()->toDateString();
        $partey = Player::create(['team_id' => $this->arsenal->id, 'name' => 'Thomas Partey', 'nationality' => 'Ghana', 'nationality_code' => 'GHA',
            'is_active' => true, 'metadata' => ['api_football_lineup' => ['date' => $today]]]);
        Player::create(['team_id' => $this->chelsea->id, 'name' => 'Nicolas Jackson', 'nationality' => 'Senegal', 'nationality_code' => 'SEN',
            'is_active' => true, 'metadata' => ['api_football_stats' => ['date' => $today, 'statistics' => ['goals' => ['total' => 2], 'games' => ['minutes' => 70]]]]]);
        $saka = Player::create(['team_id' => $this->arsenal->id, 'name' => 'Bukayo Saka', 'nationality' => 'England', 'nationality_code' => 'ENG',
            'is_active' => true, 'metadata' => ['api_football_lineup' => ['date' => $today]]]);
        PlayerHeritage::create(['player_id' => $saka->id, 'country' => 'Nigeria', 'country_code' => 'NGA', 'source_url' => 'https://example.org/s', 'status' => 'pending']);

        $html = $this->get('/start')->assertOk()->getContent();
        $this->assertStringContainsString("Today's African players", $html);
        $this->assertStringContainsString('Thomas Partey', $html);
        $this->assertStringContainsString('Nicolas Jackson', $html);
        $this->assertStringContainsString('<em>2 goals</em>', $html);
        $this->assertStringNotContainsString('Bukayo Saka', $html);
        $this->assertStringNotContainsString('of Nigerian descent', $html);
        // Partey has a line-up but no stats yet: no number, never a zero.
        preg_match('#Thomas Partey</b>.*?</a>#s', $html, $card);
        $this->assertStringNotContainsString('<em>', $card[0]);
        $this->assertStringContainsString(route('discovery.show', $partey, false), $html);
    }

    public function test_a_reel_page_offers_a_call_and_signup_to_guests(): void
    {
        $next = $this->upcoming();
        $match = FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $this->chelsea->id, 'away_team_id' => $this->arsenal->id,
            'match_date' => '2026-11-08 15:00:00', 'status' => 'FINISHED', 'home_score' => 1, 'away_score' => 1,
            'metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        File::ensureDirectoryExists(storage_path('app/reels'));
        File::put($this->files[] = storage_path("app/reels/{$match->id}.mp4"), 'x');
        $this->files[] = public_path("generated/reels/{$match->id}/{$match->id}.mp4");

        $this->get(route('reels.show', $match))->assertOk()
            ->assertSee('Call the score: Arsenal v Chelsea')
            ->assertSee('href="/matches/'.$next->id.'#prediction-arena"', false)
            ->assertSee('Create my free account');

        config(['griot_challenge.enabled' => true]);
        GriotChallenge::forceCreate(['status' => 'open', 'first_kickoff' => '2026-11-14 15:00:00', 'last_kickoff' => '2026-11-15 16:30:00']);
        $this->get(route('reels.show', $match))->assertOk()->assertSee('Beat the Griot')->assertSee('href="/beat-the-griot"', false);
    }

    private function upcoming(): FootballMatch
    {
        return FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $this->arsenal->id, 'away_team_id' => $this->chelsea->id,
            'match_date' => '2026-11-15 15:00:00', 'status' => 'SCHEDULED']);
    }

    private function decode(string $raw): array
    {
        try {
            $raw = \Illuminate\Cookie\CookieValuePrefix::remove(app('encrypter')->decrypt($raw, false));
        } catch (\Throwable) {
        }

        return json_decode($raw, true);
    }
}
