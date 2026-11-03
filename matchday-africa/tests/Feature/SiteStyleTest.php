<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Models\League;
use App\Models\Team;
use App\Support\ArmyMoods;
use App\Support\SiteStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The cartoon look: off by default, previewable per browser, switchable for everyone. */
class SiteStyleTest extends TestCase
{
    use RefreshDatabase;

    public function test_classic_is_the_default_and_loads_no_cartoon_styles(): void
    {
        $page = $this->get('/blogs')->assertOk();

        $this->assertStringNotContainsString('md-cartoon', $page->getContent());
        $this->assertStringNotContainsString('cartoon', implode(' ', $this->stylesheets($page->getContent())));
    }

    public function test_a_preview_link_turns_it_on_for_that_browser_only(): void
    {
        $page = $this->get('/blogs?style=cartoon')->assertOk()->assertPlainCookie(SiteStyle::COOKIE, 'cartoon');
        $this->assertStringContainsString('md-cartoon', $page->getContent());
        $this->assertNotEmpty(array_filter($this->stylesheets($page->getContent()), fn ($href) => str_contains($href, 'cartoon')));

        // The cookie keeps it on for later pages; ?style=classic switches back.
        $this->withUnencryptedCookie(SiteStyle::COOKIE, 'cartoon')->get('/blogs')->assertSee('md-cartoon', false);
        $this->withUnencryptedCookie(SiteStyle::COOKIE, 'cartoon')->get('/blogs?style=classic')->assertDontSee('md-cartoon', false)->assertPlainCookie(SiteStyle::COOKIE, 'classic');
        // Anything else is ignored (a fresh browser: the test client keeps cookies between requests).
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->get('/blogs?style=neon')->assertDontSee('md-cartoon', false)->assertCookieMissing(SiteStyle::COOKIE);
    }

    public function test_site_style_switches_it_on_for_everyone(): void
    {
        config(['site.style' => 'cartoon']);

        $this->get('/blogs')->assertSee('md-cartoon', false);
        $this->get('/login')->assertSee('md-cartoon', false);
    }

    public function test_war_loads_its_app_in_both_looks_and_marks_the_cartoon_one(): void
    {
        $classic = $this->get('/war')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<script[^>]+src="[^"]*(main|war)[^"]*"/', $classic, 'the game script loads');
        $this->assertStringNotContainsString('war-cartoon', $classic);

        $cartoon = $this->get('/war?style=cartoon')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<script[^>]+src="[^"]*(main|war)[^"]*"/', $cartoon);
        $this->assertStringContainsString('class="war-cartoon"', $cartoon);
    }

    public function test_army_moods_follow_the_match_and_only_show_in_the_cartoon_look(): void
    {
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $match = FootballMatch::create(['league_id' => $league->id, 'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id,
            'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id, 'match_date' => now('UTC'), 'status' => 'IN_PLAY', 'home_score' => 1, 'away_score' => 0]);

        $this->assertSame(['home' => null, 'away' => null], ArmyMoods::forMatch($match), 'classic: no images');

        $this->get('/?style=cartoon');
        request()->attributes->set('site_style', 'cartoon');
        $moods = ArmyMoods::forMatch($match);
        $this->assertStringEndsWith('war/army/moods/roman-legion-victory.webp', $moods['home']['url']);
        $this->assertStringEndsWith('war/army/moods/ottoman-janissary-dejected.webp', $moods['away']['url']);
        $this->assertSame("Chelsea's Ottoman Janissaries, dejected", $moods['away']['alt']);

        $match->update(['status' => 'FINISHED']);
        $this->assertStringEndsWith('ottoman-janissary-defeated.webp', ArmyMoods::forMatch($match->fresh())['away']['url']);

        $post = new GriotPost(['kind' => 'woodwork', 'side' => 'away', 'home_score' => 1, 'away_score' => 0]);
        $this->assertStringEndsWith('ottoman-janissary-dejected.webp', ArmyMoods::forPost($post, $match)[0]['url']);
        $this->assertCount(2, ArmyMoods::forPost(new GriotPost(['kind' => 'kickoff', 'home_score' => 0, 'away_score' => 0]), $match));
    }

    public function test_every_army_has_its_mood_thumbnails(): void
    {
        foreach (config('armies.clubs') as $club) {
            if ($club['art']) {
                foreach (ArmyMoods::MOODS as $mood) {
                    $this->assertFileExists(public_path("war/army/moods/{$club['art']}-{$mood}.webp"));
                }
            }
        }
    }

    /** @return array<int, string> */
    private function stylesheets(string $html): array
    {
        preg_match_all('/<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"/', $html, $m);

        return $m[1];
    }
}
