<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use App\Services\Espn\EspnMatchMapper;
use App\Services\Espn\EspnSyncService;
use App\Services\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EspnSyncTest extends TestCase
{
    use RefreshDatabase;

    private League $premierLeague;
    private FootballMatch $bournemouthSunderland;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo('2026-10-18 16:00:00');
        config([
            'espn.enabled' => true,
            'espn.request_pause_ms' => 0,
            'espn.fixtures_days_back' => 0,
            'espn.fixtures_days_ahead' => 0,
            'espn.live_passes' => 1,
            'espn.leagues' => array_intersect_key(config('espn.leagues'), array_flip(['eng.1', 'caf.champions'])),
        ]);

        // As football-data.org stores it: its own IDs and "FC/AFC" names.
        $this->premierLeague = League::create(['football_data_id' => 2021, 'name' => 'Premier League', 'short_code' => 'PL', 'country_code' => 'ENG']);
        $home = Team::create(['football_data_id' => 1044, 'name' => 'AFC Bournemouth']);
        $away = Team::create(['football_data_id' => 71, 'name' => 'Sunderland AFC']);
        $this->bournemouthSunderland = FootballMatch::create([
            'football_data_id' => 538001, 'league_id' => $this->premierLeague->id, 'league_football_data_id' => 2021,
            'home_team_id' => $home->id, 'home_team_football_data_id' => 1044,
            'away_team_id' => $away->id, 'away_team_football_data_id' => 71,
            'match_date' => '2026-10-18 13:00:00', 'status' => 'IN_PLAY', 'home_score' => 1, 'away_score' => 0,
        ]);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/espn/{$name}.json")), true);
    }

    private function fakeFeed(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/eng.1/scoreboard*' => Http::response($this->fixture('scoreboard-eng.1')),
            '*/caf.champions/scoreboard*' => Http::response($this->fixture('scoreboard-caf.champions')),
            '*/eng.1/summary*' => Http::response($this->fixture('summary-eng.1-401879263')),
        ]);
    }

    public function test_fixtures_link_existing_football_data_match_and_take_espn_score_and_incidents(): void
    {
        $this->fakeFeed();
        // A goal football-data.org recorded earlier must not appear twice once ESPN has the match.
        MatchEvent::create(['football_data_id' => 77, 'match_id' => $this->bournemouthSunderland->id, 'match_football_data_id' => 538001,
            'team_id' => $this->bournemouthSunderland->home_team_id, 'team_football_data_id' => 1044, 'type' => 'goal', 'minute' => 6, 'player_name' => 'M. Tavernier']);

        $result = app(EspnSyncService::class)->syncFixtures();

        $match = $this->bournemouthSunderland->fresh();
        $this->assertSame(401879263, $match->espn_id);
        $this->assertSame('FINISHED', $match->status);
        $this->assertSame([2, 0], [$match->home_score, $match->away_score]);
        $this->assertSame('espn', $match->data_source);
        $this->assertSame(349, $match->homeTeam->espn_id);
        $this->assertSame(366, $match->awayTeam->espn_id);

        $goals = $match->events()->where('type', 'goal')->orderBy('minute')->pluck('player_name')->all();
        $this->assertSame(['Marcus Tavernier', 'Evanilson'], $goals);
        $this->assertSame(1, $match->events()->where('type', 'red_card')->count());
        $this->assertSame('espn', $match->events_source);

        // Brighton v Palace isn't in our football-data.org fixtures: reported, never duplicated.
        $this->assertSame(1, FootballMatch::where('league_id', $this->premierLeague->id)->count());
        $this->assertStringContainsString('Brighton & Hove Albion v Crystal Palace', $result['unmatched'][0]);
    }

    public function test_espn_only_competition_creates_league_teams_and_match_without_football_data_ids(): void
    {
        $this->fakeFeed();

        app(EspnSyncService::class)->syncFixtures();

        $league = League::where('espn_slug', 'caf.champions')->firstOrFail();
        $this->assertNull($league->football_data_id);
        $match = FootballMatch::where('espn_id', 401914241)->firstOrFail();
        $this->assertNull($match->football_data_id);
        $this->assertSame($league->id, $match->league_id);
        $this->assertSame('Rangers International', $match->homeTeam->name);
        $this->assertSame('MC Alger', $match->awayTeam->name);
        $this->assertSame([2, 0, 'FINISHED'], [$match->home_score, $match->away_score, $match->status]);
        // CAF scoreboards carry no incidents, so none are invented.
        $this->assertSame(0, $match->events()->count());
    }

    public function test_delayed_football_data_refresh_does_not_overwrite_fresher_espn_score(): void
    {
        $this->fakeFeed();
        app(EspnSyncService::class)->syncFixtures();

        app(MatchService::class)->createOrUpdateMatch([
            'id' => 538001, 'utcDate' => '2026-10-18T13:00:00Z', 'status' => 'IN_PLAY',
            'homeTeam' => ['id' => 1044, 'name' => 'AFC Bournemouth'], 'awayTeam' => ['id' => 71, 'name' => 'Sunderland AFC'],
            'score' => ['fullTime' => ['home' => 1, 'away' => 0], 'halfTime' => ['home' => 1, 'away' => 0]],
        ], $this->premierLeague->id, 2021);

        $match = $this->bournemouthSunderland->fresh();
        $this->assertSame(['FINISHED', 2, 0], [$match->status, $match->home_score, $match->away_score]);
        $this->assertSame('STATUS_FULL_TIME', data_get($match->metadata, 'espn_status'));
        $this->assertSame(538001, data_get($match->metadata, 'id'));
    }

    public function test_live_poll_only_requests_leagues_with_a_match_in_the_window_and_shows_the_clock(): void
    {
        $board = $this->fixture('scoreboard-eng.1');
        $board['events'][0]['status'] = ['displayClock' => "63'", 'type' => ['name' => 'STATUS_SECOND_HALF', 'state' => 'in', 'completed' => false]];
        $board['events'][0]['competitions'][0]['details'] = array_slice($board['events'][0]['competitions'][0]['details'], 0, 1);
        $board['events'][0]['competitions'][0]['competitors'][0]['score'] = '1';
        $this->fakeFeed(['*/eng.1/scoreboard*' => Http::response($board)]);

        app(EspnSyncService::class)->syncLive();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/eng.1/scoreboard') && $request['dates'] === '20261018');
        $match = $this->bournemouthSunderland->fresh();
        $this->assertSame(['IN_PLAY', 63, 1, 0], [$match->status, $match->minute, $match->home_score, $match->away_score]);
        $this->assertSame("Live · 63'", $match->live_label);

        $this->getJson(route('matches.live', $match))
            ->assertOk()
            ->assertJsonPath('score.home', 1)
            ->assertJsonPath('status', "Live · 63'")
            ->assertJsonPath('active', true);
    }

    public function test_live_window_reads_kickoff_as_utc_despite_lagos_app_timezone(): void
    {
        // 15:50 UTC (16:50 in Lagos) is 170 minutes after the 13:00 UTC kick-off: still live.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-18 15:50:00', 'UTC'));
        $this->fakeFeed();

        app(EspnSyncService::class)->syncLive();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/eng.1/scoreboard'));
    }

    public function test_details_add_stats_lineups_and_substitutions_without_duplicating_goals(): void
    {
        $this->fakeFeed();
        app(EspnSyncService::class)->syncFixtures();

        app(EspnSyncService::class)->syncDetails();

        $match = $this->bournemouthSunderland->fresh();
        $this->assertSame([65, 35], [$match->home_possession, $match->away_possession]);
        $this->assertNotNull($match->home_shots);
        $this->assertSame('AFC Bournemouth', data_get($match->metadata, 'espn_lineups.0.team'));
        $this->assertCount(11, data_get($match->metadata, 'espn_lineups.0.starters'));
        $this->assertSame(2, $match->events()->where('type', 'goal')->count());
        $this->assertSame(8, $match->events()->where('type', 'substitution')->count());
        $this->assertSame('Evanilson', $match->events()->where('type', 'goal')->where('minute', 6)->value('assist_player_name'));

        // Another scoreboard pass keeps the summary-only substitutions.
        app(EspnSyncService::class)->syncFixtures();
        $this->assertSame(8, $match->events()->where('type', 'substitution')->count());
    }

    public function test_shadow_mode_records_differences_without_changing_what_fans_see(): void
    {
        config(['espn.shadow' => true]);
        $this->fakeFeed();

        $result = app(EspnSyncService::class)->syncFixtures();

        $match = $this->bournemouthSunderland->fresh();
        $this->assertSame(['IN_PLAY', 1, 0], [$match->status, $match->home_score, $match->away_score]);
        $this->assertNull($match->espn_synced_at);
        $this->assertSame(0, $match->events()->count());
        $this->assertSame(['FINISHED', 2, 0], [data_get($match->metadata, 'espn_shadow.status'), data_get($match->metadata, 'espn_shadow.home_score'), data_get($match->metadata, 'espn_shadow.away_score')]);
        $this->assertSame(1, $result['differences']);
        $this->assertNull(FootballMatch::where('espn_id', 401914241)->first());
        $this->assertStringStartsWith('[shadow]', $result['message']);
    }

    public function test_repeated_failures_pause_the_feed_and_stop_requests(): void
    {
        config(['espn.breaker_failures' => 2]);
        Http::fake(['*' => Http::response('Forbidden', 403)]);
        $client = app(EspnClient::class);

        $client->scoreboard('eng.1', '2026-10-18');
        $client->scoreboard('eng.1', '2026-10-18');
        $this->assertTrue($client->isPaused());

        $this->assertNull($client->scoreboard('eng.1', '2026-10-18'));
        Http::assertSentCount(2);
    }

    public function test_status_and_clock_mapping(): void
    {
        $mapper = new EspnMatchMapper();
        $this->assertSame('SCHEDULED', $mapper->status(['name' => 'STATUS_SCHEDULED', 'state' => 'pre']));
        $this->assertSame('IN_PLAY', $mapper->status(['name' => 'STATUS_FIRST_HALF', 'state' => 'in']));
        $this->assertSame('PAUSED', $mapper->status(['name' => 'STATUS_HALFTIME', 'state' => 'in']));
        $this->assertSame('PENALTY_SHOOTOUT', $mapper->status(['name' => 'STATUS_SHOOTOUT', 'state' => 'in']));
        $this->assertSame('FINISHED', $mapper->status(['name' => 'STATUS_FINAL_PEN', 'state' => 'post']));
        $this->assertSame('POSTPONED', $mapper->status(['name' => 'STATUS_POSTPONED', 'state' => 'post']));
        $this->assertSame('CANCELLED', $mapper->status(['name' => 'STATUS_ABANDONED', 'state' => 'post']));
        $this->assertNull($mapper->status(['name' => 'STATUS_SOMETHING_NEW']));
        $this->assertSame([45, 2], $mapper->clock("45'+2'"));
        $this->assertSame([90, null], $mapper->clock("90'"));
    }

    public function test_command_is_a_no_op_while_disabled(): void
    {
        config(['espn.enabled' => false]);
        Http::fake();

        $this->artisan('espn:sync live')->assertSuccessful();

        Http::assertNothingSent();
    }
}
