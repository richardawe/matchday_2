<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerHeritage;
use App\Models\Team;
use App\Models\User;
use App\Services\PlayerPages;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** /african-players, the weekly roster and player pages: African internationals and confirmed descent only. */
class PlayerPagesTest extends TestCase
{
    use RefreshDatabase;

    private League $pl;
    private Team $arsenal;
    private Team $chelsea;
    private Player $saka;
    private Player $partey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 10:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        config(['app.url' => 'https://matchday.africa', 'griot_challenge.enabled' => false]);
        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->arsenal = Team::create(['name' => 'Arsenal FC']);
        $this->chelsea = Team::create(['name' => 'Chelsea FC']);
        $this->saka = Player::create(['team_id' => $this->arsenal->id, 'name' => 'Bukayo Saka', 'nationality' => 'England', 'nationality_code' => 'ENG',
            'position' => 'Attacker', 'shirt_number' => 7, 'is_active' => true]);
        $this->partey = Player::create(['team_id' => $this->arsenal->id, 'name' => 'Thomas Partey', 'nationality' => 'Ghana', 'nationality_code' => 'GHA',
            'position' => 'Midfielder', 'shirt_number' => 5, 'is_active' => true]);
        FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $this->arsenal->id, 'away_team_id' => $this->chelsea->id,
            'match_date' => '2026-11-14 15:00:00', 'status' => 'SCHEDULED']);
    }

    private function heritage(string $status): PlayerHeritage
    {
        return PlayerHeritage::create(['player_id' => $this->saka->id, 'country' => 'Nigeria', 'country_code' => 'NGA',
            'source_url' => 'https://example.org/saka-parents', 'status' => $status]);
    }

    public function test_an_african_international_gets_a_page_with_title_json_ld_cta_and_signup(): void
    {
        $this->get("/african-players/{$this->partey->id}")->assertOk()
            ->assertSee('<title>Thomas Partey: Ghana, Arsenal | Matchday Africa</title>', false)
            ->assertSee('"@type":"Person"', false)
            ->assertSee('Call the score: Arsenal v Chelsea')
            ->assertSee('#prediction-arena', false)
            ->assertSee('Create my free account')
            ->assertSee('Arsenal FC v Chelsea FC')
            ->assertSee('utm_campaign=player', false);
    }

    public function test_heritage_decides_whether_a_non_african_international_has_a_page(): void
    {
        $this->get("/african-players/{$this->saka->id}")->assertNotFound();

        $row = $this->heritage('pending');
        $this->get("/african-players/{$this->saka->id}")->assertNotFound();
        $row->update(['status' => 'rejected']);
        $this->get("/african-players/{$this->saka->id}")->assertNotFound();

        $row->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        $this->get("/african-players/{$this->saka->id}")->assertOk()
            ->assertSee('Bukayo Saka: of Nigerian descent, Arsenal | Matchday Africa', false)
            ->assertSee('Of Nigerian descent.')
            ->assertDontSee('Nigeria international')
            // The source is for editors, not the public page.
            ->assertDontSee('https://example.org/saka-parents');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get("/african-players/{$this->saka->id}")
            ->assertSee('https://example.org/saka-parents');
    }

    public function test_the_premier_league_roster_groups_in_scope_players_by_club_with_an_as_of_date(): void
    {
        $this->heritage('pending');
        Player::create(['team_id' => $this->chelsea->id, 'name' => 'Nicolas Jackson', 'nationality' => 'Senegal', 'nationality_code' => 'SEN', 'is_active' => true]);
        Player::create(['team_id' => $this->chelsea->id, 'name' => 'Cole Palmer', 'nationality' => 'England', 'nationality_code' => 'ENG', 'is_active' => true, 'shirt_number' => 20]);

        $response = $this->get('/african-players/premier-league')->assertOk()
            ->assertSee('African players in the Premier League this week, club by club | Matchday Africa', false)
            ->assertSee('As of 12 November 2026')
            ->assertSeeInOrder(['Arsenal', 'Thomas Partey', 'Chelsea', 'Nicolas Jackson'])
            ->assertSee('Create my free account')
            ->assertSee('utm_campaign=roster', false);
        $response->assertDontSee('Bukayo Saka')->assertDontSee('Cole Palmer');

        PlayerHeritage::query()->update(['status' => 'confirmed']);
        $this->artisan('players:roster')->assertSuccessful();
        $this->get('/african-players/premier-league')->assertSee('Bukayo Saka')->assertSee('of Nigerian descent');
    }

    public function test_missing_numbers_are_left_out_and_real_zeros_are_shown(): void
    {
        $pages = app(PlayerPages::class);
        // No finished league match yet: nothing to count, so no numbers at all.
        $this->assertSame([], $pages->season($this->partey, $this->pl));
        $this->get("/african-players/{$this->partey->id}")->assertDontSee('GOALS');

        $played = FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $this->chelsea->id, 'away_team_id' => $this->arsenal->id,
            'match_date' => '2026-11-07 15:00:00', 'status' => 'FINISHED', 'home_score' => 0, 'away_score' => 1]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $played->id, 'team_id' => $this->arsenal->id, 'type' => 'goal', 'minute' => 30, 'player_name' => 'Kai Havertz']);

        $season = $pages->season($this->partey, $this->pl);
        $this->assertSame(0, $season['goals']);
        $this->assertSame(0, $season['assists']);
        // No stored line-up for that match: starts are unknown, not zero.
        $this->assertArrayNotHasKey('starts', $season);

        $played->update(['metadata' => ['api_football_lineups' => [['team' => 'Arsenal', 'starters' => [['name' => 'T. Partey']], 'substitutes' => []]]]]);
        $this->assertSame(1, $pages->season($this->partey, $this->pl)['starts']);
    }

    public function test_titles_are_unique_across_the_player_pages(): void
    {
        $titles = collect(['/', '/african-players', '/african-players/premier-league', '/african-players/history', "/african-players/{$this->partey->id}"])
            ->map(fn ($url) => preg_match('#<title>(.*?)</title>#s', $this->get($url)->getContent(), $m) ? trim($m[1]) : null);

        $this->assertCount(5, $titles->filter()->unique());
        $this->assertStringContainsString('African players and players of African descent', $titles[1]);
    }

    public function test_the_index_lists_confirmed_descent_players_and_filters_by_heritage_country(): void
    {
        $this->heritage('confirmed');

        $this->get('/african-players')->assertOk()->assertSee('Bukayo Saka')->assertSee('of Nigerian descent')->assertSee('Thomas Partey');
        $this->get('/african-players?country=NGA')->assertSee('Bukayo Saka')->assertDontSee('Thomas Partey');
    }

    public function test_the_heritage_import_seeds_to_verify_rows_as_pending_and_keeps_editor_decisions(): void
    {
        $nwaneri = Player::create(['team_id' => $this->arsenal->id, 'name' => 'Ethan Nwaneri', 'nationality_code' => 'ENG', 'is_active' => true, 'shirt_number' => 53]);
        $file = storage_path('app/testing-heritage.json');
        file_put_contents($file, json_encode([
            ['name' => 'Bukayo Saka', 'club' => 'Arsenal', 'country' => 'Nigeria', 'country_code' => 'NGA', 'source_url' => 'https://example.org/a'],
            ['name' => 'Ethan Nwaneri', 'club' => 'Arsenal', 'country' => 'Nigeria', 'country_code' => 'NGA', 'source_url' => 'https://example.org/b', 'to_verify' => true],
            ['name' => 'Nobody Known', 'club' => 'Arsenal', 'country_code' => 'NGA', 'source_url' => 'https://example.org/c'],
        ]));

        $this->artisan('players:heritage-import', ['file' => $file])->expectsOutputToContain('Not matched to a player in our squads: Nobody Known')->assertSuccessful();

        $this->assertSame('confirmed', $this->saka->heritages()->first()->status);
        $this->assertSame('pending', $nwaneri->heritages()->first()->status);
        $this->get("/african-players/{$nwaneri->id}")->assertNotFound();

        // An editor's rejection survives a re-import.
        $this->saka->heritages()->update(['status' => 'rejected', 'confirmed_by' => User::factory()->create(['is_admin' => true])->id]);
        $this->artisan('players:heritage-import', ['file' => $file])->assertSuccessful();
        $this->assertSame('rejected', $this->saka->heritages()->first()->status);
        File::delete($file);
    }

    public function test_match_pages_list_confirmed_descent_players_with_the_right_wording(): void
    {
        $this->heritage('confirmed');
        $match = FootballMatch::first();
        foreach ([$this->saka, $this->partey] as $p) {
            $p->update(['metadata' => ['api_football_lineup' => ['match_id' => $match->id, 'starter' => true]]]);
        }

        $this->get("/matches/{$match->id}")->assertOk()->assertSee('of Nigerian descent')->assertSee("/african-players/{$this->saka->id}", false);
    }

    public function test_slideshow_landing_links_go_to_descent_players_pages(): void
    {
        $this->heritage('confirmed');

        $this->assertSame("/african-players/{$this->saka->id}", PlayerPages::landing($this->saka->fresh(), FootballMatch::first()));
        $this->assertSame("https://matchday.africa/african-players/{$this->partey->id}?utm_source=tiktok&utm_medium=social&utm_campaign=player&utm_content=player-{$this->partey->id}",
            PlayerPages::url($this->partey, 'tiktok'));
    }

    public function test_a_sourced_international_stored_under_another_nationality_is_named_by_country_not_descent(): void
    {
        $wanBissaka = Player::create(['team_id' => $this->arsenal->id, 'name' => 'Aaron Wan-Bissaka', 'nationality' => 'England', 'nationality_code' => 'ENG', 'is_active' => true, 'shirt_number' => 29]);
        PlayerHeritage::create(['player_id' => $wanBissaka->id, 'country' => 'DR Congo', 'country_code' => 'COD', 'kind' => 'international',
            'source_url' => 'https://example.org/caps', 'status' => 'confirmed']);
        $konsa = Player::create(['team_id' => $this->arsenal->id, 'name' => 'Ezri Konsa', 'nationality_code' => 'ENG', 'is_active' => true, 'shirt_number' => 4]);
        foreach (['COD' => 'DR Congo', 'ANG' => 'Angola'] as $code => $country) {
            PlayerHeritage::create(['player_id' => $konsa->id, 'country' => $country, 'country_code' => $code, 'source_url' => 'https://example.org/k', 'status' => 'confirmed']);
        }

        $this->assertSame('DR Congo', \App\Support\AfricanScope::label($wanBissaka));
        $this->assertTrue(\App\Support\AfricanScope::isNational($wanBissaka));
        $this->assertSame('of Congolese and Angolan descent', \App\Support\AfricanScope::label($konsa));
        $this->get("/african-players/{$wanBissaka->id}")->assertOk()->assertSee('Aaron Wan-Bissaka: DR Congo, Arsenal', false)->assertDontSee('descent');
    }

    public function test_the_seed_roster_matches_the_owners_file(): void
    {
        $rows = collect(json_decode(file_get_contents(database_path('seeders/data/african_heritage_epl.json')), true));

        $this->assertCount(36, $rows->where('kind', 'international')->unique('name'));
        $this->assertCount(22, $rows->where('kind', 'descent')->unique('name'));
        $this->assertSame(['Eberechi Eze', 'Thierno Barry', 'Ethan Ampadu', 'Jeremie Frimpong'], $rows->where('to_verify', true)->pluck('name')->values()->all());
        foreach ($rows as $row) {
            $this->assertNotNull(\App\Support\AfricanScope::nation($row['country_code']), $row['name']);
            $this->assertMatchesRegularExpression('#^https://#', $row['source_url'], $row['name']);
        }
        $saka = $rows->firstWhere('name', 'Bukayo Saka');
        $this->assertSame(['NGA', 'descent', false], [$saka['country_code'], $saka['kind'], $saka['to_verify']]);
        $this->assertSame(['COD', 'ANG'], $rows->where('name', 'Ezri Konsa')->pluck('country_code')->values()->all());
        $this->assertSame('international', $rows->firstWhere('name', 'Aaron Wan-Bissaka')['kind']);
    }

    public function test_the_import_matches_a_transferred_player_by_full_name_but_never_a_namesake(): void
    {
        $palace = Team::create(['name' => 'Crystal Palace FC']);
        $guehi = Player::create(['team_id' => $palace->id, 'name' => 'Marc Guéhi', 'nationality_code' => 'ENG', 'is_active' => true, 'shirt_number' => 6]);
        Player::create(['team_id' => Team::create(['name' => 'AC Pisa 1909'])->id, 'name' => 'Nicolas', 'nationality_code' => 'BRA', 'is_active' => true]);
        $file = storage_path('app/testing-heritage.json');
        file_put_contents($file, json_encode([
            ['name' => 'Marc Guéhi', 'club' => 'Manchester City', 'country' => 'Ivory Coast', 'country_code' => 'CIV', 'kind' => 'descent', 'source_url' => 'https://example.org/g'],
            ['name' => 'Nicolas Jackson', 'club' => 'Aston Villa', 'country' => 'Senegal', 'country_code' => 'SEN', 'kind' => 'international', 'source_url' => 'https://example.org/j'],
        ]));

        $this->artisan('players:heritage-import', ['file' => $file])
            ->expectsOutputToContain('Marc Guéhi: listed at Manchester City, matched to the only Marc Guéhi we have')
            ->expectsOutputToContain('Not matched to a player in our squads: Nicolas Jackson (Aston Villa)')->assertSuccessful();

        $this->assertSame('of Ivorian descent', \App\Support\AfricanScope::label($guehi->fresh()));
        $this->assertSame(1, PlayerHeritage::count());
        File::delete($file);
    }
}
