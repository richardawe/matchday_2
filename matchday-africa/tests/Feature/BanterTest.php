<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\BanterService;
use App\Services\MatchStoryService;
use App\Services\ReelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BanterTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-19 18:00:00');
        config(['reel.banter.enabled' => true, 'reel.banter.ai' => false, 'reel.banter.langs' => ['en', 'pcm'],
            'services.openrouter.api_key' => 'key', 'services.openrouter.model' => 'test/model', 'services.openrouter.fallback_models' => []]);
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now('UTC')->subMinutes(110), 'status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1,
        ]);
        foreach ([[1, 'home', 41, 'Bukayo Saka'], [2, 'away', 55, 'Cole Palmer'], [3, 'home', 88, 'Gabriel Martinelli']] as [$id, $side, $minute, $player]) {
            MatchEvent::create(['football_data_id' => $id, 'match_id' => $this->match->id, 'team_id' => $side === 'home' ? $this->match->home_team_id : $this->match->away_team_id,
                'type' => 'goal', 'minute' => $minute, 'player_name' => $player]);
        }
        app(MatchStoryService::class)->syncRecent();
    }

    public function test_every_written_line_obeys_the_rules_for_every_army(): void
    {
        $banter = app(BanterService::class);
        $facts = $banter->factsFor($this->match);
        $armies = array_column(config('armies.clubs'), 'army');
        foreach (config('banter') as $situation => $langs) {
            if ($situation === 'blocklist') {
                continue;
            }
            foreach ($langs as $lang => $lines) {
                foreach ($lines as $line) {
                    foreach ([['Roman Legion', 'Ottoman Janissaries'], [$armies[array_rand($armies)], 'Zulu Impi'], ['White Rose Yorkist Army', 'Wolf Warband']] as [$army, $foe]) {
                        $filled = strtr($line, ['{army}' => $army, '{foe}' => $foe, '{score}' => '2–1']);
                        $names = [...$facts['names'], $army, $foe, ...preg_split('/[\s\-’\']+/u', "{$army} {$foe}")];
                        $this->assertTrue($banter->check($filled, ['names' => $names] + $facts), "{$situation}.{$lang}: \"{$filled}\"");
                        $this->assertLessThanOrEqual(BanterService::MAX_LENGTH, mb_strlen($filled), "{$situation}.{$lang} too long: \"{$filled}\"");
                    }
                }
            }
        }
    }

    public function test_the_checks_keep_out_names_wrong_numbers_and_blocked_words(): void
    {
        $banter = app(BanterService::class);
        $facts = $banter->factsFor($this->match);

        $this->assertTrue($banter->check('Ottoman Janissaries, check the scoreboard. 2–1.', $facts));
        $this->assertTrue($banter->check('Na wa o. The Roman Legion dey here!', $facts));
        $this->assertFalse($banter->check('Saka cooked the Janissaries today.', $facts), 'player names, even first');
        $this->assertFalse($banter->check('Even Palmer could not save them.', $facts), 'player names');
        $this->assertFalse($banter->check('That was a 5–0 lesson.', $facts), 'invented score');
        $this->assertFalse($banter->check('God abeg, make una rest.', $facts), 'religion');
        $this->assertFalse($banter->check('Blame the referee, Janissaries.', $facts), 'referees');
        $this->assertFalse($banter->check(str_repeat('Too long. ', 8), $facts), 'length');
    }

    public function test_four_bubbles_one_language_per_army_written_when_ai_is_off(): void
    {
        $bubbles = app(BanterService::class)->compose($this->match);

        $this->assertSame(['title', 'first', 'reply', 'final'], array_column($bubbles, 'slot'));
        $this->assertSame(['home', 'home', 'away', 'home'], array_column($bubbles, 'side'));
        $this->assertSame(['written'], array_values(array_unique(array_column($bubbles, 'source'))));
        $byArmy = collect($bubbles)->groupBy('side')->map(fn ($b) => $b->pluck('lang')->unique()->values()->all());
        $this->assertCount(1, $byArmy['home']);
        $this->assertNotSame($byArmy['home'], $byArmy['away']);
        // The reply sits on the last big moment (the 88th-minute winner), the first on the 41st.
        $beats = \App\Models\MatchMoment::where('match_id', $this->match->id)->where('type', 'goal')->orderBy('minute')->pluck('id');
        $this->assertSame([$beats->first(), $beats->last()], [$bubbles[1]['beat'], $bubbles[2]['beat']]);
    }

    public function test_ai_lines_fill_two_bubbles_and_a_line_that_breaks_the_rules_is_replaced(): void
    {
        config(['reel.banter.ai' => true]);
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => '```json
{"first": "Roman Legion strike first. Keep up!", "final": "Even Palmer could not save you."}
```']]]])]);

        $bubbles = collect(app(BanterService::class)->compose($this->match))->keyBy('slot');

        $this->assertSame(['ai', 'Roman Legion strike first. Keep up!'], [$bubbles['first']['source'], $bubbles['first']['text']]);
        $this->assertSame('written', $bubbles['final']['source'], 'a line naming a player falls back to the bank');
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Never mention real people'));
    }

    public function test_the_ai_being_down_never_holds_a_reel_up(): void
    {
        config(['reel.banter.ai' => true]);
        Http::fake(['openrouter.ai/*' => Http::response('down', 500)]);

        $bubbles = app(BanterService::class)->compose($this->match);

        $this->assertCount(4, $bubbles);
        $this->assertSame(['written'], array_values(array_unique(array_column($bubbles, 'source'))));
    }

    public function test_reels_carry_their_bubbles_and_keep_them_across_re_renders(): void
    {
        config(['reel.renderer' => 'github', 'reel.github.token' => 'token', 'reel.voiceover.enabled' => true]);
        Http::fake(['api.github.com/*' => Http::response('', 204)]);
        $reels = app(ReelService::class);

        $reels->make($this->match);
        $first = data_get($this->match->fresh()->metadata, 'reel.banter');
        $reels->make($this->match);

        $this->assertCount(4, $first);
        $this->assertSame($first, data_get($this->match->fresh()->metadata, 'reel.banter'));
        $props = $reels->props($this->match->fresh());
        $this->assertSame($first, $props['bubbles']);
        $this->assertSame(['home' => 'am_fenrir', 'away' => 'bm_george'], $props['voiceover']);
        $this->assertNotNull($props['beats'][0]['id']);
    }

    public function test_the_reel_desk_edits_lines_within_the_rules(): void
    {
        $reels = app(ReelService::class);
        $reels->setBanter($this->match, app(BanterService::class)->compose($this->match));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.reels.index'))->assertOk()->assertSee('WRITTEN')->assertSee('Draw new lines');
        $this->actingAs($admin)->put(route('admin.reels.banter', $this->match), ['lines' => [0 => 'Even Saka cannot help you.']])
            ->assertSessionHas('error');
        $this->actingAs($admin)->put(route('admin.reels.banter', $this->match), ['lines' => [0 => 'Roman Legion, form up!']])
            ->assertSessionHas('success');

        $reel = data_get($this->match->fresh()->metadata, 'reel');
        $this->assertSame(['Roman Legion, form up!', 'edited'], [$reel['banter'][0]['text'], $reel['banter'][0]['source']]);
        $this->assertSame('queued', $reel['status']);
    }
}
