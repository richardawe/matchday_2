<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\Stream\StreamFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamPageTest extends TestCase
{
    use RefreshDatabase;

    private League $pl;
    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        config(['stream.enabled' => true, 'stream.competitions' => ['PL', 'eng.1']]);
        $this->travelTo('2026-11-07 16:10:00');
        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = $this->match('Liverpool FC', 'Manchester City FC', now('UTC')->subMinutes(70), [
            'status' => 'IN_PLAY', 'minute' => 67, 'home_score' => 2, 'away_score' => 1, 'last_api_update' => now(),
        ]);
    }

    private function match(string $home, string $away, $kickoff, array $extra = [], ?League $league = null): FootballMatch
    {
        return FootballMatch::create([
            'league_id' => ($league ?? $this->pl)->id,
            'home_team_id' => Team::firstOrCreate(['name' => $home])->id,
            'away_team_id' => Team::firstOrCreate(['name' => $away])->id,
            // match_date holds UTC wall-clock time (AGENTS rule 5).
            'match_date' => $kickoff->format('Y-m-d H:i:s'),
        ] + $extra + ['status' => 'TIMED']);
    }

    private function goal(int $id, string $side, int $minute, ?string $player): MatchEvent
    {
        return MatchEvent::create(['football_data_id' => $id, 'match_id' => $this->match->id,
            'team_id' => $side === 'home' ? $this->match->home_team_id : $this->match->away_team_id,
            'type' => 'goal', 'minute' => $minute, 'player_name' => $player]);
    }

    private function live(): array
    {
        return app(StreamFeed::class)->snapshot()['live'][0];
    }

    public function test_the_stream_is_off_until_switched_on(): void
    {
        config(['stream.enabled' => false]);
        $this->get('/stream')->assertNotFound();
        $this->get('/stream/feed')->assertNotFound();
    }

    public function test_the_page_and_feed_load_when_on(): void
    {
        $this->get('/stream')->assertOk()->assertSee('MATCHDAY LIVE')->assertSee(route('stream.feed'), false)
            ->assertHeader('X-Robots-Tag', 'noindex');
        $this->getJson('/stream/feed')->assertOk()
            ->assertJsonPath('live.0.home.name', 'Liverpool')
            ->assertJsonPath('live.0.away.name', 'Manchester City')
            ->assertJsonPath('live.0.score', [2, 1])
            ->assertJsonPath('live.0.clock', '67′')
            ->assertJsonPath('stale', false);
    }

    public function test_only_the_chosen_competitions_go_on_stream(): void
    {
        $liga = League::create(['football_data_id' => 2014, 'short_code' => 'PD', 'name' => 'La Liga']);
        $this->match('Real Betis', 'Sevilla FC', now('UTC')->subMinutes(30), ['status' => 'IN_PLAY', 'home_score' => 0, 'away_score' => 0], $liga);

        $live = app(StreamFeed::class)->snapshot()['live'];
        $this->assertCount(1, $live);
        $this->assertSame($this->match->id, $live[0]['id']);
    }

    public function test_goal_ids_stay_the_same_when_the_scorer_is_named_later(): void
    {
        $this->goal(1, 'home', 23, 'Mohamed Salah');
        $this->goal(2, 'away', 41, 'Erling Haaland');
        // The score shows the second home goal before the feed names the scorer.
        $before = collect($this->live()['goals'])->keyBy('id');
        $this->assertSame(["{$this->match->id}:home:1", "{$this->match->id}:home:2", "{$this->match->id}:away:1"], $before->keys()->all());
        $this->assertSame('Mohamed Salah', $before["{$this->match->id}:home:1"]['scorer']);
        $this->assertNull($before["{$this->match->id}:home:2"]['scorer']);

        $this->goal(3, 'home', 64, 'Cody Gakpo');
        $after = collect($this->live()['goals'])->keyBy('id');
        $this->assertSame($before->keys()->all(), $after->keys()->all());
        $this->assertSame('Cody Gakpo', $after["{$this->match->id}:home:2"]['scorer']);
        $this->assertSame('64′', $after["{$this->match->id}:home:2"]['minute']);
    }

    public function test_a_goal_ruled_out_disappears_from_the_snapshot(): void
    {
        $this->match->update(['home_score' => 3]);
        $this->assertContains("{$this->match->id}:home:3", collect($this->live()['goals'])->pluck('id'));

        $this->match->update(['home_score' => 2]);
        $ids = collect($this->live()['goals'])->pluck('id');
        $this->assertNotContains("{$this->match->id}:home:3", $ids);
        $this->assertCount(3, $ids);
    }

    public function test_red_cards_carry_a_stable_id(): void
    {
        MatchEvent::create(['football_data_id' => 77, 'match_id' => $this->match->id, 'team_id' => $this->match->away_team_id,
            'type' => 'red_card', 'minute' => 70, 'player_name' => 'Rodri']);

        $reds = $this->live()['reds'];
        $this->assertSame([['id' => "{$this->match->id}:red:77", 'side' => 'away', 'minute' => '70′', 'player' => 'Rodri', 'photo' => null]], $reds);
        $this->assertSame($reds, $this->live()['reds']);
    }

    public function test_the_screen_says_updating_when_the_scores_stop_moving(): void
    {
        $this->match->update(['last_api_update' => now()->subMinutes(5)]);
        $this->assertTrue(app(StreamFeed::class)->snapshot()['stale']);
    }

    public function test_between_matches_it_counts_down_to_the_next_kick_off_in_utc(): void
    {
        $this->match->update(['status' => 'FINISHED', 'home_score' => 3, 'away_score' => 1]);
        $this->match('Arsenal FC', 'Chelsea FC', now('UTC')->addMinutes(80));
        $this->match('Newcastle United FC', 'Tottenham Hotspur FC', now('UTC')->addHours(3));

        $snapshot = app(StreamFeed::class)->snapshot();
        $this->assertSame([], $snapshot['live']);
        $this->assertSame('Arsenal', $snapshot['next']['home']['name']);
        // It is 15:10 UTC (16:10 in Lagos): kick-off at 16:30 UTC wall-clock = 17:30 in Lagos.
        $this->assertSame('2026-11-07T16:30:00+00:00', $snapshot['next']['kickoff']);
        $this->assertSame('17:30', $snapshot['next']['kickoff_lagos']);
        $this->assertSame('Newcastle United', $snapshot['later'][0]['home']['name']);
        $this->assertSame([3, 1], $snapshot['results'][0]['score']);
    }

    public function test_teams_use_their_crest_free_army_and_kit_colours(): void
    {
        $home = $this->live()['home'];
        $this->assertSame('Norse Vikings', $home['army']);
        $this->assertSame('#C8102E', $home['colour']);
        $this->assertSame('#ffffff', $home['ink']);
        $this->assertStringContainsString('war/army/norse-viking-solid.webp', $home['art']);
    }

    public function test_the_board_feed_gathers_events_from_every_live_match_newest_first(): void
    {
        $other = $this->match('Arsenal FC', 'Chelsea FC', now('UTC')->subMinutes(50), [
            'status' => 'IN_PLAY', 'minute' => 47, 'home_score' => 1, 'away_score' => 0, 'last_api_update' => now(),
        ]);
        $this->goal(1, 'home', 23, 'Mohamed Salah');
        $this->travel(1)->minutes();
        MatchEvent::create(['football_data_id' => 2, 'match_id' => $other->id, 'team_id' => $other->home_team_id, 'type' => 'goal', 'minute' => 41, 'player_name' => 'Bukayo Saka']);
        $this->travel(1)->minutes();
        MatchEvent::create(['football_data_id' => 3, 'match_id' => $this->match->id, 'team_id' => $this->match->away_team_id, 'type' => 'red_card', 'minute' => 60, 'player_name' => 'Rodri']);

        $snap = app(StreamFeed::class)->snapshot();
        $this->assertCount(2, $snap['live']);
        $this->assertSame(['RED CARD! Rodri is sent off', 'GOAL! Bukayo Saka scores for Arsenal', 'GOAL! Mohamed Salah scores for Liverpool'],
            array_column($snap['events'], 'headline'));
        $this->assertSame([$this->match->id, $other->id, $this->match->id], array_column($snap['events'], 'match'));
        $this->assertSame("60′", $snap['events'][0]['clock']);

        // Nothing live: no board feed.
        FootballMatch::query()->update(['status' => 'FINISHED']);
        $this->assertSame([], app(StreamFeed::class)->snapshot()['events']);
    }
}
