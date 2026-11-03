<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_redirects_to_register(): void
    {
        $this->get('/signup')->assertStatus(301)->assertRedirect('/register');
    }

    public function test_teams_without_a_badge_fall_back_to_a_file_that_exists(): void
    {
        $team = Team::create(['name' => 'Badgeless FC']);
        $this->assertStringEndsWith('/images/default-team-logo.png', $team->logo);
        $this->assertFileExists(public_path('images/default-team-logo.png'));
        $this->assertFileExists(public_path('images/default-team.png'));
    }

    public function test_predictions_and_arena_have_their_own_titles(): void
    {
        $this->get('/predictions')->assertOk()
            ->assertSee('<title>Football predictions: call the score, win the week | Matchday Africa</title>', false)
            ->assertSee('name="description" content="Free football predictions', false)
            ->assertDontSee('EPL Odds');
        $this->get('/war/arena')->assertOk()->assertSee('<title>The Arena: this week&#039;s Premier League armies in 3D | Matchday Africa</title>', false);
    }

    public function test_war_has_a_share_image(): void
    {
        $this->get('/war')->assertOk()
            ->assertSee('property="og:image" content="'.asset('images/war-share.jpg'), false)
            ->assertSee('name="twitter:image" content="'.asset('images/war-share.jpg'), false);
        $this->assertFileExists(public_path('images/war-share.jpg'));
    }

    public function test_kick_off_times_are_shown_in_wat_with_the_label(): void
    {
        $this->travelTo('2026-11-04 08:00:00');
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'Champions League', 'short_code' => 'CL', 'created_at' => now(), 'updated_at' => now()]);
        // Stored as UTC wall-clock, as the sync writes it: 17:45 UTC is 18:45 in Lagos.
        $match = FootballMatch::withoutEvents(fn () => FootballMatch::create([
            'football_data_id' => 4982, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'AEK'])->id, 'away_team_id' => Team::create(['name' => 'Real Madrid'])->id,
            'match_date' => '2026-11-04 17:45:00', 'status' => 'SCHEDULED',
        ]));

        $this->assertSame('18:45 WAT', $match->kickoffLabel('H:i'));
        $this->get('/predictions')->assertOk()->assertSee('Wed 4 Nov, 18:45 WAT')->assertDontSee('17:45');
        // In score boxes the zone sits in small type under the time, so it fits the box.
        $this->get('/')->assertOk()->assertSee('18:45<small class="md-tz">WAT</small>', false);
        // Structured data keeps the true instant with its offset (17:45+00:00); the page text shows WAT.
        $this->get(route('matches.show', $match))->assertOk()->assertSee('Wed, 4 Nov · 18:45 WAT')->assertSee('2026-11-04T17:45:00+00:00', false)->assertDontSee('· 17:45');
    }

    public function test_flags_for_guinea_and_sierra_leone(): void
    {
        $this->assertSame("\u{1F1EC}\u{1F1F3}", (new Player(['nationality_code' => 'GUI']))->nationality_flag);
        $this->assertSame("\u{1F1F8}\u{1F1F1}", (new Player(['nationality_code' => 'SLE']))->nationality_flag);
        $this->assertSame("\u{1F1FF}\u{1F1E6}", (new Player(['nationality_code' => 'RSA']))->nationality_flag);
        $this->assertSame('', (new Player(['nationality_code' => 'XXX']))->nationality_flag);
    }
}
