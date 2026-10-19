<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\MatchStoryService;
use App\Services\ReelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GriotReelTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-19 18:00:00');
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id,
            'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id,
            'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now('UTC')->subMinutes(110), 'status' => 'FINISHED', 'home_score' => 1, 'away_score' => 0,
            'home_possession' => 58, 'away_possession' => 42,
        ]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->match->id, 'team_id' => $this->match->home_team_id, 'type' => 'goal', 'minute' => 41, 'player_name' => 'Bukayo Saka']);
        app(MatchStoryService::class)->syncRecent();
    }

    public function test_props_tell_the_story_with_crest_free_art(): void
    {
        $props = app(ReelService::class)->props($this->match);

        $this->assertSame('Roman Legion v Ottoman Janissaries', $props['title']['headline']);
        $this->assertStringEndsWith('resources/armies/roman-legion.webp', $props['home']['art_path']);
        $this->assertSame(['kickoff', 'goal'], array_column($props['beats'], 'type'));
        $this->assertSame('First blood', $props['beats'][1]['headline']);
        $this->assertSame(58, $props['stats'][0]['home']);
        $this->assertSame('The Roman Legion prevail', $props['final']['headline']);

        $urls = app(ReelService::class)->props($this->match, urls: true);
        $this->assertStringContainsString('war/army/roman-legion-cutout-960.webp', $urls['home']['art_url']);
    }

    public function test_reel_waits_for_late_corrections_then_dispatches_to_github(): void
    {
        config(['reel.renderer' => 'github', 'reel.github.token' => 'token']);
        Http::fake(['api.github.com/*' => Http::response(null, 204)]);

        $this->assertCount(0, app(ReelService::class)->pending(), 'too soon after full time');
        $this->travel(4)->minutes();
        $this->assertCount(1, app(ReelService::class)->pending());

        $this->artisan('reel:render')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'actions/workflows/render-reel.yml/dispatches')
            && $request['inputs']['match_id'] === (string) $this->match->id
            && str_contains($request['inputs']['props_url'], 'signature='));
        $this->assertSame('dispatched', data_get($this->match->fresh()->metadata, 'reel.status'));
        $this->assertCount(0, app(ReelService::class)->pending(), 'not dispatched twice');
    }

    public function test_props_endpoint_needs_a_signature(): void
    {
        $this->getJson(route('reels.props', $this->match))->assertForbidden();
        $this->getJson(URL::temporarySignedRoute('reels.props', now()->addHour(), $this->match))
            ->assertOk()->assertJsonPath('home.army', 'Roman Legion');
    }

    public function test_reel_is_off_by_default(): void
    {
        $this->travel(10)->minutes();
        $this->artisan('reel:render')->expectsOutput('REEL_RENDERER is off; nothing rendered.')->assertSuccessful();
        $this->get(route('matches.reel', $this->match))->assertNotFound();
    }
}
