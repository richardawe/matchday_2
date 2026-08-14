<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_live_match_is_not_advertised_on_homepage(): void
    {
        Carbon::setTestNow('2026-08-15 14:00:00');
        $match = $this->match(['match_date' => now()->subDay(), 'last_api_update' => now()->subDay()]);

        $this->get('/')
            ->assertOk()
            ->assertSee('No live matches right now.')
            ->assertDontSee($match->homeTeam->name);
    }

    public function test_recent_active_match_is_shown_as_live(): void
    {
        Carbon::setTestNow('2026-08-15 14:00:00');
        $match = $this->match(['match_date' => now()->subHour(), 'last_api_update' => now()->subMinutes(5)]);

        $this->get('/')
            ->assertOk()
            ->assertSee('LIVE NOW')
            ->assertSee($match->homeTeam->name);
    }

    public function test_fixture_later_in_the_week_is_shown_as_upcoming(): void
    {
        Carbon::setTestNow('2026-08-15 14:00:00');
        $match = $this->match([
            'status' => 'SCHEDULED',
            'match_date' => now()->addDays(5),
            'last_api_update' => now(),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Next 7 days')
            ->assertSee($match->homeTeam->name);
    }

    private function match(array $attributes): FootballMatch
    {
        $league = League::create(['football_data_id' => 100, 'name' => 'Test League']);
        $home = Team::create(['football_data_id' => 200, 'name' => 'Accra Stars']);
        $away = Team::create(['football_data_id' => 201, 'name' => 'Lagos Lions']);

        return FootballMatch::create(array_merge([
            'football_data_id' => 300,
            'league_id' => $league->id,
            'league_football_data_id' => $league->football_data_id,
            'home_team_id' => $home->id,
            'home_team_football_data_id' => $home->football_data_id,
            'away_team_id' => $away->id,
            'away_team_football_data_id' => $away->football_data_id,
            'status' => 'LIVE',
            'home_score' => 1,
            'away_score' => 0,
        ], $attributes));
    }
}
