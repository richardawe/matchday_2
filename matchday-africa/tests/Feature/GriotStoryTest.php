<?php
namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\MatchMoment;
use App\Models\Team;
use App\Services\Griot;
use App\Services\MatchStoryService;
use App\Support\Armies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GriotStoryTest extends TestCase
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
            'match_date' => now('UTC')->subMinutes(100), 'status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1,
        ]);
        $event = fn ($team, $type, $minute, $player) => MatchEvent::create(['football_data_id' => random_int(1, PHP_INT_MAX), 'match_id' => $this->match->id,
            'team_id' => $team, 'type' => $type, 'minute' => $minute, 'player_name' => $player]);
        $event($this->match->home_team_id, 'goal', 41, 'Bukayo Saka');
        $event($this->match->away_team_id, 'goal', 55, 'Cole Palmer');
        $event($this->match->away_team_id, 'red_card', 63, 'Moisés Caicedo');
        $event($this->match->home_team_id, 'goal', 88, 'Gabriel Martinelli');
    }

    public function test_clubs_map_to_crest_free_armies_whatever_the_provider_calls_them(): void
    {
        $this->assertSame('Roman Legion', Armies::for('Arsenal FC')['army']);
        $this->assertSame('Barbary Corsairs', Armies::for('AFC Bournemouth')['army']);
        $this->assertSame('Wolf Warband', Armies::for('Wolves')['army']);
        $this->assertFileExists(Armies::for('Wolverhampton Wanderers FC')['cutout_path']);
        // Some feeds add "AFC": Hull City AFC and Sunderland AFC are keyed without it.
        $this->assertSame('hull city', Armies::key('Hull City AFC'));
        $this->assertSame('Northern Rebel Clans', Armies::for('Sunderland AFC')['army']);
        $this->assertNull(Armies::for('West Ham United FC')['cutout_path']);
        $this->assertStringContainsString('rights-safe', config('armies.source'));
        $this->assertFileExists(Armies::for('Chelsea FC')['cutout_path']);
    }

    public function test_match_becomes_chapters_with_the_right_angles_and_running_score(): void
    {
        $created = app(MatchStoryService::class)->syncRecent();

        $chapters = app(MatchStoryService::class)->chapters($this->match);
        $this->assertSame(6, $created);
        $this->assertSame(['kickoff', 'goal', 'goal', 'red_card', 'goal', 'full_time'], $chapters->pluck('type')->all());
        $this->assertSame(['opener', 'equaliser', 'late_winner'], $chapters->where('type', 'goal')->pluck('angle')->values()->all());
        $this->assertSame([1, 1], [$chapters[2]->home_score, $chapters[2]->away_score]);
        $this->assertSame('home', $chapters->last()->side);

        // Running it again changes nothing; a goal ruled out afterwards disappears.
        $this->assertSame(0, app(MatchStoryService::class)->syncRecent());
        MatchEvent::where('player_name', 'Cole Palmer')->delete();
        app(MatchStoryService::class)->sync($this->match->fresh());
        $this->assertSame(0, MatchMoment::where('player_name', 'Cole Palmer')->count());
    }

    public function test_griot_tells_each_chapter_in_four_voices(): void
    {
        app(MatchStoryService::class)->syncRecent();
        $opener = MatchMoment::where('angle', 'opener')->first();

        $this->assertSame(['First blood', 'Bukayo Saka strikes for the Roman Legion.'], app(Griot::class)->tell($opener, 'en'));
        $this->assertSame('Goal don enter!', app(Griot::class)->tell($opener, 'pcm')[0]);
        $this->assertSame('Bao la kwanza!', app(Griot::class)->tell($opener, 'sw')[0]);
        $this->assertSame('Eish, what a strike!', app(Griot::class)->tell($opener, 'za')[0]);
        $this->assertSame('GOAL · 41′', app(Griot::class)->tag($opener));
        $final = MatchMoment::where('type', 'full_time')->first();
        $this->assertSame(['The Roman Legion prevail', 'Full time: Arsenal 2–1 Chelsea.'], app(Griot::class)->tell($final, 'en'));
    }

    public function test_cards_render_as_png_in_every_size_and_show_on_the_match_page(): void
    {
        app(MatchStoryService::class)->syncRecent();
        $goal = MatchMoment::where('angle', 'opener')->first();

        foreach (['story' => [1080, 1920], 'feed' => [1080, 1350], 'og' => [1200, 630]] as $format => [$w, $h]) {
            $response = $this->get($goal->cardUrl('pcm', $format))->assertOk()->assertHeader('Content-Type', 'image/png');
            $size = getimagesizefromstring(file_get_contents($response->baseResponse->getFile()->getPathname()));
            $this->assertSame([$w, $h], [$size[0], $size[1]]);
        }

        $this->get(route('matches.show', ['match' => $this->match, 'voice' => 'sw']))->assertOk()
            ->assertSee('THE STORY SO FAR')->assertSee('Bao la kwanza!')->assertSee('wa.me/?text=', false)
            ->assertCookie('griot_voice', 'sw');
        $this->get(route('matches.show', ['match' => $this->match, 'moment' => $goal->id]))
            ->assertSee(route('moments.card', ['moment' => $goal->id, 'voice' => 'en', 'format' => 'og']), false);
    }

    public function test_goal_and_red_card_cards_show_the_players_photo_when_we_have_one(): void
    {
        app(MatchStoryService::class)->syncRecent();
        $goal = MatchMoment::where('type', 'goal')->where('player_name', 'Bukayo Saka')->first();
        $renderer = app(\App\Services\StoryCardRenderer::class);
        $without = $renderer->path($goal, 'en', 'feed');

        // A solid red "photo" for Saka, saved where players:photos saves them.
        $saka = \App\Models\Player::create(['team_id' => $this->match->home_team_id, 'name' => 'Bukayo Saka']);
        $file = public_path("generated/players/{$saka->id}.png");
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($file));
        $png = imagecreatetruecolor(150, 150);
        imagefill($png, 0, 0, imagecolorallocate($png, 220, 20, 20));
        imagepng($png, $file);
        $saka->update(['photo_url' => url("generated/players/{$saka->id}.png")]);

        try {
            $with = $renderer->path($goal->fresh(), 'en', 'feed');
            $this->assertNotSame($without, $with); // a photo that arrives later redraws the card
            $card = imagecreatefrompng($with);
            [$r, $g, $b] = array_values(imagecolorsforindex($card, imagecolorat($card, (int) (1080 * 0.8), (int) (1350 * 0.36))));
            $this->assertGreaterThan(180, $r);
            $this->assertLessThan(60, $g);

            // Chapters about other players, and kick-off, have no badge.
            $palmer = MatchMoment::where('player_name', 'Cole Palmer')->first();
            $this->assertStringNotContainsString('-p', basename($renderer->path($palmer, 'en', 'feed')));
        } finally {
            \Illuminate\Support\Facades\File::delete($file);
        }
    }

    public function test_cards_follow_the_cartoon_look(): void
    {
        config(['site.style' => 'cartoon']);
        app(MatchStoryService::class)->syncRecent();
        $goal = MatchMoment::where('type', 'goal')->first();
        $renderer = app(\App\Services\StoryCardRenderer::class);

        $this->assertStringContainsString('look=cartoon', $goal->cardUrl('en', 'feed'));
        $this->assertStringEndsWith('-cartoon.png', $renderer->path($goal, 'en', 'feed'));
        foreach (['story' => [1080, 1920], 'feed' => [1080, 1350], 'og' => [1200, 630]] as $format => [$w, $h]) {
            $card = imagecreatefrompng($renderer->path($goal, 'en', $format));
            $this->assertSame([$w, $h], [imagesx($card), imagesy($card)]);
            // Sun-yellow background in the corner (the dark look is near-black).
            [$r, $g, $b] = array_values(imagecolorsforindex($card, imagecolorat($card, $w - 3, $h - 3)));
            $this->assertGreaterThan(200, $r);
            $this->assertGreaterThan(150, $g);
        }
        $this->get($goal->cardUrl('en', 'feed'))->assertOk()->assertHeader('Content-Type', 'image/png');

        config(['site.style' => 'classic']);
        $this->assertStringNotContainsString('look=', $goal->cardUrl('en', 'feed'));
    }
}
