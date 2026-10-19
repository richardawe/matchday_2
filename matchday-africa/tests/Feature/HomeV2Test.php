<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\MatchStoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HomeV2Test extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-21 10:00:00');
        $pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $team = fn ($n) => Team::create(['name' => $n])->id;
        $finished = function ($home, $away, $hs, $as, $daysAgo) use ($pl, $team) {
            $m = FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => $team($home), 'away_team_id' => $team($away),
                'match_date' => now('UTC')->subDays($daysAgo), 'status' => 'FINISHED', 'home_score' => $hs, 'away_score' => $as]);
            $m->forceFill(['metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]])->save();
            File::ensureDirectoryExists(storage_path('app/reels'));
            foreach (['mp4', 'jpg'] as $ext) {
                File::put($this->files[] = storage_path("app/reels/{$m->id}.{$ext}"), 'x');
            }

            return $m;
        };
        $finished('Everton FC', 'Chelsea FC', 0, 2, 3);
        $thriller = $finished('Manchester United FC', 'Wolverhampton Wanderers FC', 3, 3, 2);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $thriller->id, 'team_id' => $thriller->away_team_id, 'type' => 'goal', 'minute' => 90, 'player_name' => 'Hwang']);
        app(MatchStoryService::class)->sync($thriller);
        FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => $team('Arsenal FC'), 'away_team_id' => $team('Liverpool FC'),
            'match_date' => now('UTC')->addDays(3)->setTime(14, 0), 'status' => 'TIMED']);
    }

    protected function tearDown(): void
    {
        File::delete($this->files);
        parent::tearDown();
    }

    public function test_the_current_homepage_stays_until_switched_on_and_v2_can_be_previewed(): void
    {
        $this->get('/')->assertOk()->assertDontSee('id="reels-hero"', false);

        $this->get('/?home=v2')->assertOk()
            ->assertSee('id="reels-hero"', false)
            ->assertSee('Every match, <em>told in twenty seconds.</em>', false)
            // The strongest recent reel leads: a 3–3 with a 90th-minute goal and Manchester United.
            ->assertSee('Manchester United 3–3 Wolverhampton Wanderers')
            ->assertSee('data-reel-title="Everton 0–2 Chelsea"', false)
            ->assertSee('MATCHWEEK BOARD')
            ->assertSee('Arsenal v Liverpool')
            ->assertSee('/card.png', false);
    }

    public function test_switched_on_it_replaces_the_homepage_and_the_arena_moves_to_war(): void
    {
        config(['home.v2' => true]);

        $this->get('/')->assertOk()->assertSee('id="reels-hero"', false)->assertDontSee('id="arena"', false);
        $this->get(route('war.arena'))->assertOk()->assertSee('Enter Matchday War');
    }

    public function test_without_reels_the_hero_shows_the_next_fixture_card(): void
    {
        FootballMatch::query()->update(['metadata' => null]);

        $this->get('/?home=v2')->assertOk()
            ->assertSee('This round\'s reels appear here.', false)
            ->assertSee('md-reels-fallback', false);
    }
}
