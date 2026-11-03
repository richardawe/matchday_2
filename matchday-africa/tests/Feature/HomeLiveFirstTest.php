<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeLiveFirstTest extends TestCase
{
    use RefreshDatabase;

    private function liveMatch(): FootballMatch
    {
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 2021, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create([
            'football_data_id' => 901, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now()->subMinutes(40), 'status' => 'IN_PLAY', 'home_score' => 2, 'away_score' => 1,
            'last_api_update' => now()->subMinute(),
        ]));
    }

    public function test_live_scores_lead_both_homepages_while_a_match_is_on(): void
    {
        $this->liveMatch();
        foreach (['/', '/?home=v2'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $live = strpos($html, 'id="home-live"');
            $this->assertNotFalse($live, $url);
            $this->assertStringContainsString('LIVE NOW', $html);
            $this->assertStringNotContainsString('id="home-live" aria-label="Live scores" hidden', $html);
            // Live scores come before everything else on the page body.
            if ($url === '/?home=v2') $this->assertLessThan(strpos($html, 'id="reels-hero"'), $live);
            $this->assertLessThan(strpos($html, 'id="matchday-pulse"'), $live);
        }
    }

    public function test_the_live_block_waits_hidden_when_nothing_is_on_and_the_pulse_refills_it(): void
    {
        $this->get('/')->assertOk()->assertSee('id="home-live" aria-label="Live scores" hidden', false);
        $this->getJson(route('home.pulse'))->assertOk()->assertJsonPath('has_live', false)
            ->assertJson(fn ($json) => $json->where('live', fn ($html) => str_contains($html, 'id="home-live"') && str_contains($html, ' hidden'))->etc());

        $this->liveMatch();
        $this->getJson(route('home.pulse'))->assertOk()->assertJsonPath('has_live', true)
            ->assertJson(fn ($json) => $json->where('live', fn ($html) => str_contains($html, 'LIVE NOW') && str_contains($html, 'Arsenal'))->etc());
    }

    public function test_the_installed_app_has_a_back_button_except_on_home(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-app-back', false);
        $this->get('/matches')->assertOk()->assertSee('data-app-back', false);
        $this->get('/login')->assertOk()->assertSee('data-app-back', false);
    }
}
