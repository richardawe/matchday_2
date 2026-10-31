<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BareUserAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bare_mozilla_token_gets_the_page_and_static_files(): void
    {
        $headers = ['User-Agent' => 'Mozilla/5.0'];

        $this->get('/terms', $headers)->assertOk()->assertSee('Terms');
        $robots = $this->get('/robots.txt', $headers)->assertOk();
        $this->assertStringContainsString('Sitemap: https://matchday.africa/sitemap.xml', file_get_contents((string) $robots->baseResponse->getFile()));
        $this->get('/', ['User-Agent' => 'Mozilla/4.0'])->assertOk();

        $match = $this->match();
        $this->get(route('matches.show', $match), $headers)->assertOk()
            ->assertSee('<meta property="og:type" content="article">', false);
    }

    private function match(): FootballMatch
    {
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create([
            'football_data_id' => 77, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now()->addDay(), 'status' => 'SCHEDULED',
        ]));
    }
}
