<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchCentreOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(League $league, string $home, string $away, int $minutesFromNow, string $status = 'SCHEDULED'): FootballMatch
    {
        return FootballMatch::create([
            'league_id' => $league->id,
            'home_team_id' => Team::create(['name' => $home])->id,
            'away_team_id' => Team::create(['name' => $away])->id,
            'match_date' => now()->addMinutes($minutesFromNow),
            'status' => $status,
            'last_api_update' => now(),
        ]);
    }

    public function test_home_pulse_puts_headline_competitions_before_earlier_kickoffs_elsewhere(): void
    {
        $this->travelTo('2026-10-19 12:00:00');
        $turkey = League::create(['espn_slug' => 'tur.1', 'name' => 'Süper Lig']);
        $premier = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->fixture($turkey, 'Genclerbirligi', 'Galatasaray', 30);
        $this->fixture($premier, 'Liverpool FC', 'Manchester City FC', 240);

        $html = $this->getJson(route('home.pulse'))->assertOk()->json('html');

        $this->assertLessThan(strpos($html, 'Genclerbirligi'), strpos($html, 'Liverpool FC'));
    }

    public function test_match_centre_groups_by_competition_and_filters_by_region_and_team(): void
    {
        $this->travelTo('2026-10-19 08:00:00');
        $turkey = League::create(['espn_slug' => 'tur.1', 'name' => 'Süper Lig', 'country_name' => 'Turkey']);
        $caf = League::create(['espn_slug' => 'caf.champions', 'name' => 'CAF Champions League']);
        $this->fixture($turkey, 'Genclerbirligi', 'Galatasaray', 30);
        $this->fixture($caf, 'African Stars', 'Orlando Pirates', 90);

        $this->get(route('matches.index'))->assertOk()
            ->assertSeeInOrder(['CAF Champions League', 'African Stars', 'Süper Lig', 'Genclerbirligi'])
            ->assertSee('Africa <b>1</b>', false);

        $this->get(route('matches.index', ['region' => 'africa']))->assertOk()
            ->assertSee('Orlando Pirates')->assertDontSee('Galatasaray');

        $this->get(route('matches.index', ['q' => 'galata']))->assertOk()
            ->assertSee('Galatasaray')->assertDontSee('Orlando Pirates');
    }

    public function test_tweet_links_with_only_a_date_open_that_day(): void
    {
        $this->travelTo('2026-10-19 08:00:00');
        $this->get(route('matches.index', ['date' => '2026-10-25']))->assertOk()->assertSee('Sunday 25 October 2026');
    }
}
