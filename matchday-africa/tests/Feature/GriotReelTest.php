<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Services\MatchStoryService;
use App\Services\ReelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    public function test_props_carry_factual_commentary_longest_first(): void
    {
        $props = app(ReelService::class)->props($this->match);

        $this->assertSame(['Arsenal against Chelsea.'], $props['title']['say']);
        $goal = collect($props['beats'])->firstWhere('type', 'goal');
        $this->assertSame(['Saka scores, 41 minutes! Arsenal 1, Chelsea 0.', 'Saka scores! Arsenal 1, Chelsea 0.', 'Goal, Saka! 1 nil.', 'Saka scores!', 'Goal!'], $goal['say']);
        $this->assertSame(['Full time. Arsenal 1, Chelsea 0.', 'Full time.'], $props['final']['say']);
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

    public function test_a_failing_reel_is_tried_three_times_and_a_lost_github_render_times_out(): void
    {
        config(['reel.renderer' => 'github', 'reel.github.token' => 'token']);
        Http::fake(['api.github.com/*' => Http::response('nope', 500)]);
        $this->travel(4)->minutes();
        $reels = app(ReelService::class);

        foreach (range(1, 3) as $try) {
            $this->assertCount(1, $reels->pending(), "try {$try}");
            $this->assertSame('failed', $reels->make($reels->pending()->first()));
        }
        $this->assertCount(0, $reels->pending(), 'not retried every minute forever');

        // A dispatched render that never arrives is marked failed after 45 minutes.
        $this->match->update(['metadata' => ['reel' => ['status' => 'dispatched', 'started_at' => now()->toIso8601String()]]]);
        $this->travel(46)->minutes();
        $reels->collectUploads();
        $this->assertSame('failed', data_get($this->match->fresh()->metadata, 'reel.status'));
    }

    public function test_a_render_that_died_mid_way_is_retried(): void
    {
        config(['reel.renderer' => 'server', 'reel.timeout' => 600]);
        $this->match->update(['metadata' => ['reel' => ['status' => 'rendering', 'started_at' => now()->toIso8601String(), 'attempts' => 1]]]);
        $reels = app(ReelService::class);

        $this->assertCount(0, $reels->pending(), 'still within the render timeout');
        $this->travel(21)->minutes();
        $this->assertCount(1, $reels->pending());
    }

    public function test_backfill_queues_an_older_round_and_the_scheduler_picks_it_up(): void
    {
        config(['reel.renderer' => 'github', 'reel.github.token' => 'token']);
        $this->match->update(['match_date' => now('UTC')->subDays(5)]);
        \App\Models\MatchMoment::where('match_id', $this->match->id)->delete();
        $reels = app(ReelService::class);
        $this->assertCount(0, $reels->pending(), 'too old for the automatic run');

        $day = now('Africa/Lagos')->subDays(5)->toDateString();
        $this->artisan('reel:queue', ['--from' => $day, '--to' => $day])->expectsOutput('1 reels queued; the scheduler renders one a minute.');

        $this->assertSame('queued', data_get($this->match->fresh()->metadata, 'reel.status'));
        $this->assertCount(1, $reels->pending());
        $this->assertSame(1, \App\Models\MatchMoment::where('match_id', $this->match->id)->where('type', 'full_time')->count());
    }

    public function test_admin_reel_desk_lists_matches_and_flags_missing_goals(): void
    {
        $this->match->update(['home_score' => 2]);
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.reels.index'))->assertOk()
            ->assertSee('Arsenal FC 2–0 Chelsea FC')->assertSee('Only 1 of 2 goals are in the data');
        $this->actingAs($admin)->post(route('admin.reels.queue', $this->match))->assertRedirect();
        $this->assertSame('queued', data_get($this->match->fresh()->metadata, 'reel.status'));
        $this->get(route('matches.reel-poster', $this->match))->assertNotFound();
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

    public function test_reel_ranges_use_an_extensionless_url(): void
    {
        $this->match->update(['metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]]);
        File::ensureDirectoryExists(storage_path('app/reels'));
        File::put(storage_path("app/reels/{$this->match->id}.mp4"), str_repeat('m', 100));

        $this->assertStringEndsWith("/matches/{$this->match->id}/reel", route('matches.reel', $this->match));
        $this->withHeader('Range', 'bytes=10-19')->get(route('matches.reel', $this->match))
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 10-19/100')->assertHeader('Content-Length', '10');
        $this->withoutHeader('Range')->get("/matches/{$this->match->id}/reel.mp4")->assertOk();

        // Pages link a static copy, which the host serves with ranges (PHP responses lose them).
        $url = app(ReelService::class)->url($this->match->fresh());
        $this->assertSame(asset("generated/reels/{$this->match->id}/{$this->match->id}.mp4").'?v='.now()->timestamp, $url);
        $this->assertFileEquals(storage_path("app/reels/{$this->match->id}.mp4"), public_path("generated/reels/{$this->match->id}/{$this->match->id}.mp4"));
        File::delete(storage_path("app/reels/{$this->match->id}.mp4"));
    }
}
