<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Services\FootballDataService;
use App\Services\PlayerPhotos;
use App\Services\PlayerService;
use App\Services\Stream\StreamFeed;
use App\Support\PlayerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlayerPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Team $liverpool;
    private League $pl;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.api_football.key' => 'test-key', 'services.api_football.photo_leagues' => ['PL' => 39], 'stream.competitions' => ['PL']]);
        $this->travelTo('2026-11-07 16:10:00');
        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->liverpool = Team::create(['name' => 'Liverpool FC', 'football_data_id' => 64]);
        FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $this->liverpool->id,
            'away_team_id' => Team::create(['name' => 'Manchester City FC', 'football_data_id' => 65])->id,
            'match_date' => '2026-11-08 16:30:00', 'status' => 'TIMED']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('generated/players'));
        parent::tearDown();
    }

    private function fakeApi(): void
    {
        $png = "\x89PNG\r\n\x1a\n".str_repeat('x', 800);
        Http::fake([
            'v3.football.api-sports.io/teams*' => Http::response(['errors' => [], 'response' => [
                ['team' => ['id' => 40, 'name' => 'Liverpool']], ['team' => ['id' => 50, 'name' => 'Manchester City']],
            ]]),
            'v3.football.api-sports.io/players/squads*' => fn ($request) => Http::response(['errors' => [], 'response' => [[
                'team' => ['id' => (int) $request['team']],
                'players' => (int) $request['team'] === 40 ? [
                    ['id' => 306, 'name' => 'M. Salah', 'number' => 11, 'position' => 'Attacker', 'photo' => 'https://media.api-sports.io/football/players/306.png'],
                    ['id' => 999, 'name' => 'R. Ngumoha', 'number' => 73, 'position' => 'Attacker', 'photo' => 'https://media.api-sports.io/football/players/999.png'],
                ] : [],
            ]]]),
            'media.api-sports.io/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_names_match_across_providers(): void
    {
        $this->assertTrue(PlayerNames::same('M. Salah', 'Mohamed Salah'));
        $this->assertTrue(PlayerNames::same('Salah', 'Mohamed Salah'));
        $this->assertTrue(PlayerNames::same('Hakim Ziyech', 'Hakim Ziyech'));
        $this->assertTrue(PlayerNames::same('Yves Bissouma', 'Yves Bissoúma'));
        $this->assertFalse(PlayerNames::same('Mohamed Salah', 'Mohamed Elneny'));
        $this->assertFalse(PlayerNames::same('M. Salah', 'A. Salah'));
    }

    public function test_squad_photos_are_saved_as_our_own_files(): void
    {
        $this->fakeApi();
        $salah = Player::create(['team_id' => $this->liverpool->id, 'name' => 'Mohamed Salah', 'shirt_number' => 11, 'nationality_code' => 'EGY']);

        $result = app(PlayerPhotos::class)->sync();

        $this->assertSame(2, $result['teams']);
        $this->assertSame(2, $result['photos']);
        $this->assertSame(1, $result['added']);
        $this->assertSame(40, $this->liverpool->fresh()->metadata['api_football_team_id']);
        $salah->refresh();
        $this->assertStringContainsString("generated/players/{$salah->id}.png", $salah->photo_url);
        $this->assertFileExists(public_path("generated/players/{$salah->id}.png"));
        $this->assertSame(306, $salah->metadata['api_football_player_id']);
        $this->assertNotNull(Player::where('name', 'R. Ngumoha')->first()?->photo_url);

        // A week's squads are cached: running again spends no calls.
        $this->assertSame(0, app(PlayerPhotos::class)->sync()['calls']);
    }

    public function test_clubs_are_found_by_name_when_the_plan_has_no_current_season(): void
    {
        $png = "\x89PNG\r\n\x1a\n".str_repeat('x', 800);
        Http::fake([
            // API-Football's free plan refuses the current season's league list.
            'v3.football.api-sports.io/teams?league*' => Http::response(['errors' => ['plan' => 'Free plans do not have access to this season, try from 2022 to 2024.'], 'response' => []]),
            'v3.football.api-sports.io/teams?search=Liverpool*' => Http::response(['errors' => [], 'response' => [
                ['team' => ['id' => 9999, 'name' => 'Liverpool Montevideo', 'national' => false]],
                ['team' => ['id' => 40, 'name' => 'Liverpool', 'national' => false]],
            ]]),
            'v3.football.api-sports.io/teams?search=*' => Http::response(['errors' => [], 'response' => [['team' => ['id' => 50, 'name' => 'Manchester City', 'national' => false]]]]),
            'v3.football.api-sports.io/players/squads*' => Http::response(['errors' => [], 'response' => [['players' => [
                ['id' => 306, 'name' => 'M. Salah', 'number' => 11, 'photo' => 'https://media.api-sports.io/football/players/306.png'],
            ]]]]),
            'media.api-sports.io/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $result = app(PlayerPhotos::class)->sync();

        $this->assertSame(40, $this->liverpool->fresh()->metadata['api_football_team_id']);
        $this->assertSame(2, $result['teams']);
        $this->assertStringContainsString('Free plans do not have access', $result['error']);
        $this->artisan('players:photos')->assertSuccessful();
    }

    public function test_club_names_with_symbols_are_searched_as_letters_and_one_refusal_does_not_stop_the_rest(): void
    {
        $brighton = Team::create(['name' => 'Brighton & Hove Albion FC', 'football_data_id' => 397]);
        FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $brighton->id, 'away_team_id' => $this->liverpool->id,
            'match_date' => '2026-11-09 15:00:00', 'status' => 'TIMED']);
        $searches = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$searches) {
            if (str_contains($request->url(), 'teams?league')) {
                return Http::response(['errors' => ['plan' => 'Free plans do not have access to this season.'], 'response' => []]);
            }
            if (str_contains($request->url(), 'search=')) {
                $searches[] = $request['search'];
                if (preg_match('/[^A-Za-z0-9 ]/', $request['search'])) {
                    return Http::response(['errors' => ['search' => 'The Search field may only contain alpha-numeric characters and spaces.'], 'response' => []]);
                }
                return Http::response(['errors' => [], 'response' => match (true) {
                    $request['search'] === 'Brighton Hove Albion' => [],
                    $request['search'] === 'Brighton' => [['team' => ['id' => 51, 'name' => 'Brighton', 'national' => false]]],
                    str_starts_with($request['search'], 'Liverpool') => [['team' => ['id' => 40, 'name' => 'Liverpool', 'national' => false]]],
                    default => [['team' => ['id' => 50, 'name' => 'Manchester City', 'national' => false]]],
                }]);
            }
            if (str_contains($request->url(), 'squads') && (int) $request['team'] === 50) {
                return Http::response(['errors' => ['team' => 'refused'], 'response' => []]);
            }

            return Http::response(['errors' => [], 'response' => [['players' => []]]]);
        });

        $result = app(PlayerPhotos::class)->sync();

        $this->assertSame(51, $brighton->fresh()->metadata['api_football_team_id']);
        $this->assertContains('Brighton Hove Albion', $searches);
        $this->assertSame([], array_filter($searches, fn ($s) => preg_match('/[^A-Za-z0-9 ]/', $s)));
        // Man City's squad was refused; Liverpool's and Brighton's still went through.
        $this->assertSame(2, $result['teams']);
    }

    public function test_nothing_is_fetched_without_a_key(): void
    {
        config(['services.api_football.key' => '']);
        Http::fake();
        $this->assertSame(0, app(PlayerPhotos::class)->sync()['teams']);
        Http::assertNothingSent();
    }

    public function test_the_weekly_squad_refresh_keeps_players_and_their_photos(): void
    {
        $salah = Player::create(['team_id' => $this->liverpool->id, 'football_data_id' => 3754, 'name' => 'Mohamed Salah',
            'shirt_number' => 11, 'photo_url' => 'https://matchday.africa/generated/players/1.png', 'metadata' => ['api_football_player_id' => 306]]);
        $gone = Player::create(['team_id' => $this->liverpool->id, 'football_data_id' => 1, 'name' => 'Old Boy', 'shirt_number' => 9]);
        $this->mock(FootballDataService::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['squad' => [
            ['id' => 3754, 'name' => 'Mohamed Salah', 'position' => 'Right Winger', 'nationality' => 'Egypt', 'shirtNumber' => 11],
            ['id' => 5000, 'name' => 'Hugo Ekitike', 'position' => 'Centre-Forward', 'nationality' => 'France', 'shirtNumber' => 9],
        ]]));

        $result = app(PlayerService::class)->syncTeamPlayers(64);

        $this->assertSame(2, $result['success']);
        $salah->refresh();
        $this->assertSame('https://matchday.africa/generated/players/1.png', $salah->photo_url);
        $this->assertSame(306, $salah->metadata['api_football_player_id']);
        $this->assertSame('Egypt', $salah->nationality);
        // The departed player stays (page links keep working) but is inactive and gives up the 9 shirt.
        $gone->refresh();
        $this->assertFalse($gone->is_active);
        $this->assertNull($gone->shirt_number);
        $this->assertSame(9, Player::where('name', 'Hugo Ekitike')->first()->shirt_number);
    }

    public function test_the_stream_shows_the_scorers_photo(): void
    {
        $match = FootballMatch::first();
        $match->update(['status' => 'IN_PLAY', 'minute' => 30, 'home_score' => 1, 'away_score' => 0, 'match_date' => '2026-11-07 14:40:00', 'last_api_update' => now()]);
        Player::create(['team_id' => $this->liverpool->id, 'name' => 'Mohamed Salah', 'photo_url' => 'https://matchday.africa/generated/players/7.png?v=1']);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $match->id, 'team_id' => $this->liverpool->id, 'type' => 'goal', 'minute' => 23, 'player_name' => 'Mo Salah']);

        $goal = app(StreamFeed::class)->snapshot()['live'][0]['goals'][0];
        $this->assertSame('Mo Salah', $goal['scorer']);
        $this->assertSame('https://matchday.africa/generated/players/7.png?v=1', $goal['photo']);
    }
}
