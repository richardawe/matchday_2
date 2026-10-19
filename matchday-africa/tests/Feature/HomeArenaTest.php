<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use App\Support\Armies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeArenaTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $home, string $away, int $minutesFromNow, string $status, ?League $league = null): FootballMatch
    {
        $league ??= League::firstOrCreate(['short_code' => 'PL'], ['football_data_id' => 2021, 'name' => 'Premier League']);

        return FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::create(['name' => $home])->id, 'away_team_id' => Team::create(['name' => $away])->id,
            'match_date' => now('UTC')->addMinutes($minutesFromNow), 'status' => $status, 'last_api_update' => now(),
        ]);
    }

    public function test_arena_shows_the_next_premier_league_match_with_its_armies(): void
    {
        $this->travelTo('2026-10-19 08:00:00');
        $this->fixture('Liverpool FC', 'Manchester City FC', 300, 'SCHEDULED');
        $this->fixture('Newcastle United FC', 'Aston Villa FC', 120, 'SCHEDULED');
        $this->fixture('Kitara FC', 'Al Ahly', 30, 'SCHEDULED', League::create(['espn_slug' => 'caf.confed', 'name' => 'CAF Confederation Cup']));

        $this->get('/')->assertOk()
            ->assertSee('id="arena"', false)
            ->assertSee('Norse-Northumbrian Raiders')
            ->assertSee('war/army/northumbrian-raider-cutout-960.webp', false)
            ->assertSee('data-state="upcoming"', false)
            ->assertDontSee('Kitara FC v Al Ahly · told by the Griot');
    }

    public function test_live_match_wins_the_arena_and_its_score_rides_the_pulse(): void
    {
        $this->travelTo('2026-10-19 16:00:00');
        $this->fixture('Liverpool FC', 'Manchester City FC', 300, 'SCHEDULED');
        $live = $this->fixture('Arsenal FC', 'Chelsea FC', -50, 'IN_PLAY');
        $live->update(['home_score' => 2, 'away_score' => 1, 'minute' => 52]);

        $this->get('/')->assertSee('Roman Legion')->assertSee('data-state="live"', false);
        $this->getJson(route('home.pulse'))->assertJsonPath('arena.match_id', $live->id)
            ->assertJsonPath('arena.home', 2)->assertJsonPath('arena.state', 'live');
    }

    public function test_no_arena_without_a_premier_league_match(): void
    {
        $this->get('/')->assertOk()->assertDontSee('id="arena"', false);
    }

    public function test_dark_kits_glow_in_their_second_colour(): void
    {
        $this->assertSame('#FFFFFF', Armies::for('Newcastle United FC')['glow']);
        $this->assertSame('#EF0107', Armies::for('Arsenal FC')['glow']);
    }
}
