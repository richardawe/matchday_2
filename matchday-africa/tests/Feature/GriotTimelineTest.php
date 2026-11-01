<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Models\League;
use App\Models\MatchPlay;
use App\Models\Team;
use App\Services\Espn\EspnCommentaryMapper;
use App\Services\Griot\GriotTeller;
use App\Services\Griot\GriotTimeline;
use App\Services\Griot\MomentPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GriotTimelineTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        config(['griot.enabled' => true, 'griot.picker_model' => storage_path('framework/testing/no-picker.json')]);
        $this->travelTo('2026-10-25 16:30:00');
        $league = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->match = FootballMatch::create([
            'league_id' => $league->id,
            'home_team_id' => Team::create(['name' => 'Liverpool FC'])->id,
            'away_team_id' => Team::create(['name' => 'Brighton & Hove Albion FC'])->id,
            'match_date' => now('UTC')->subHours(2), 'status' => 'FINISHED', 'home_score' => 2, 'away_score' => 0,
        ]);
    }

    private function plays(string $fixture): array
    {
        return app(EspnCommentaryMapper::class)->plays(json_decode(file_get_contents(base_path("tests/Fixtures/griot/{$fixture}.json")), true));
    }

    public function test_espn_commentary_becomes_standard_plays(): void
    {
        $plays = collect($this->plays('summary-liverpool-brighton'));

        $ownGoal = $plays->firstWhere('type', 'own_goal');
        $this->assertSame(['home', 'Pascal Gross', 17], [$ownGoal['side'], $ownGoal['player'], $ownGoal['minute']]);
        $isak = $plays->firstWhere('type', 'goal');
        $this->assertSame(['home', 'Alexander Isak', 'Bradley Barcola', 'six_yard', 'left'], [$isak['side'], $isak['player'], $isak['player2'], $isak['detail']['zone'], $isak['detail']['body']]);
        $this->assertEqualsWithDelta(94.8, $isak['x'], 0.01);
        $post = $plays->firstWhere('type', 'woodwork');
        $this->assertSame(['away', 'Yasin Ayari', 'outside'], [$post['side'], $post['player'], $post['detail']['zone']]);
        $injury = $plays->first(fn ($p) => $p['type'] === 'substitution' && !empty($p['detail']['injury']));
        $this->assertSame(['Costinha', 'Ferdi Kadioglu'], [$injury['player'], $injury['player2']]);
        // A foul's two lines share one play id and count once; the whistles are kept.
        $this->assertSame($plays->pluck('provider_key')->unique()->count(), $plays->count());
        $this->assertSame(2, $plays->where('type', 'period_start')->count());
        $this->assertSame([3, 4], $plays->where('type', 'added_time')->pluck('detail.minutes')->values()->all());
        $added = $plays->first(fn ($p) => $p['type'] === 'shot_saved' && $p['player'] === 'Charalambos Kostoulas');
        $this->assertSame([45, 1, 1], [$added['minute'], $added['extra_minute'], $added['period']]);
    }

    public function test_the_match_is_told_as_a_timeline_and_reruns_change_nothing(): void
    {
        $timeline = app(GriotTimeline::class);
        $timeline->savePlays($this->match, $this->plays('summary-liverpool-brighton'));
        $created = $timeline->build($this->match);

        $posts = GriotPost::where('match_id', $this->match->id)->orderBy('seq')->get();
        $this->assertSame($created, $posts->count());
        $this->assertSame('kickoff', $posts->first()->kind);
        $this->assertSame('full_time', $posts->last()->kind);
        $goals = $posts->where('kind', 'goal')->values();
        $this->assertSame([[1, 0], [2, 0]], $goals->map(fn ($g) => [$g->home_score, $g->away_score])->all());
        $this->assertTrue($goals[0]->facts['own_goal']);
        $this->assertSame(['opener', 'extend'], $goals->pluck('facts.angle')->all());
        foreach (['woodwork', 'injury', 'half_time', 'big_chance', 'pressure'] as $kind) {
            $this->assertTrue($posts->contains('kind', $kind), "missing {$kind}");
        }
        $this->assertSame([9, 8], [$posts->firstWhere('kind', 'half_time')->facts['home_shots'], $posts->firstWhere('kind', 'half_time')->facts['away_shots']]);
        $this->assertSame([13, 17, 4, 1], array_values(array_intersect_key($posts->last()->facts, array_flip(['home_shots', 'away_shots', 'home_on_target', 'away_on_target']))));
        // Brighton's four shots in first-half added time are one pressure spell, not four posts.
        $spell = $posts->first(fn ($p) => $p->kind === 'pressure' && $p->side === 'away' && $p->period === 1 && $p->minute === 45);
        $this->assertSame(4, $spell->facts['shots']);

        $this->assertSame(0, $timeline->build($this->match->fresh()));
        $this->assertSame($posts->count(), GriotPost::count());
    }

    public function test_full_time_waits_for_the_final_whistle(): void
    {
        $this->match->update(['status' => 'IN_PLAY']);
        $live = array_values(array_filter($this->plays('summary-liverpool-brighton'), fn ($p) => $p['minute'] < 60));
        app(GriotTimeline::class)->savePlays($this->match, $live);
        app(GriotTimeline::class)->build($this->match);

        $this->assertFalse(GriotPost::where('kind', 'full_time')->exists());
        $this->assertTrue(GriotPost::where('kind', 'half_time')->exists());
    }

    public function test_var_overturned_goal_is_told_and_never_scored(): void
    {
        app(GriotTimeline::class)->savePlays($this->match, $this->plays('summary-palace-newcastle'));
        app(GriotTimeline::class)->build($this->match);

        $var = GriotPost::where('kind', 'var')->first();
        $this->assertSame('Joe Willock', $var->facts['player']);
        $this->assertSame([0, 1], [$var->home_score, $var->away_score]);
        // Osula's goal before it and Barnes's after it count; Willock's never does.
        $this->assertSame([[0, 1], [0, 2]], GriotPost::where('kind', 'goal')->orderBy('seq')->get()->map(fn ($g) => [$g->home_score, $g->away_score])->all());
        $this->assertSame('The elders say no', app(GriotTeller::class)->tell($var, 'en')[0]);
    }

    public function test_the_griot_tells_posts_from_their_facts_in_four_voices(): void
    {
        app(GriotTimeline::class)->savePlays($this->match, $this->plays('summary-liverpool-brighton'));
        app(GriotTimeline::class)->build($this->match);
        $teller = app(GriotTeller::class);

        $isak = GriotPost::where('kind', 'goal')->where('home_score', 2)->first();
        [$headline, $line] = $teller->tell($isak, 'en');
        $this->assertSame('The Norse Vikings press on', $headline);
        $this->assertStringContainsString('Alexander Isak makes it 2–0.', $line);
        $this->assertStringContainsString('Bradley Barcola laid it on for him.', $line);
        $this->assertSame('E don pass be that!', $teller->tell($isak, 'pcm')[0]);

        $post = GriotPost::where('kind', 'woodwork')->first();
        $this->assertStringContainsString('Yasin Ayari', implode(' ', $teller->tell($post, 'en')));
        $this->assertStringContainsString('Yasin Ayari', implode(' ', $teller->tell($post, 'sw')));
        $this->assertStringContainsString('2 0', $teller->speech($isak));

        // Every caption in every voice fills all its blanks.
        foreach (GriotPost::all() as $p) {
            foreach (array_keys(\App\Services\Griot::VOICES) as $voice) {
                $this->assertStringNotContainsString('{', implode(' ', $teller->tell($p, $voice)), "{$p->kind} in {$voice}");
            }
        }
    }

    public function test_written_captions_replace_the_templates_in_english(): void
    {
        app(GriotTimeline::class)->savePlays($this->match, $this->plays('summary-liverpool-brighton'));
        app(GriotTimeline::class)->build($this->match);
        $post = GriotPost::where('kind', 'woodwork')->first();
        $post->update(['written' => ['headline' => 'Ayari rattles the post', 'line' => 'From distance, and the frame still shakes.']]);

        $this->assertSame(['Ayari rattles the post', 'From distance, and the frame still shakes.'], app(GriotTeller::class)->tell($post, 'en'));
        $this->assertNotSame('Ayari rattles the post', app(GriotTeller::class)->tell($post, 'pcm')[0]);
    }

    public function test_a_learned_picker_filters_optional_moments_but_keeps_goals_and_whistles(): void
    {
        $plays = $this->plays('summary-liverpool-brighton');
        $silent = new MomentPicker(['features' => MomentPicker::FEATURES, 'bias' => -20, 'weights' => array_fill(0, count(MomentPicker::FEATURES), 0)]);
        $kinds = array_column($silent->pick($plays, true), 'kind');
        $this->assertSame(['kickoff', 'goal', 'goal', 'half_time', 'full_time'], $kinds);
        $this->assertSame('model:1', $silent->name());

        $eager = new MomentPicker(['features' => MomentPicker::FEATURES, 'bias' => 20, 'weights' => array_fill(0, count(MomentPicker::FEATURES), 0)]);
        $this->assertGreaterThan(count((new MomentPicker())->pick($plays, true)), count($eager->pick($plays, true)));
    }

    public function test_picker_matches_the_training_code_on_the_shared_fixture(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/griot/picker-parity.json')), true);
        $candidates = (new MomentPicker())->candidates($this->plays('summary-liverpool-brighton'), true);
        $mine = array_values(array_map(fn ($c) => ['key' => $c['key'], 'strict' => $c['strict'], 'features' => array_map(fn ($v) => round($v, 3), $c['features'])],
            array_filter($candidates, fn ($c) => $c['features'])));

        $this->assertSame($fixture['candidates'], $mine);
    }

    public function test_the_match_page_shows_the_timeline_only_when_switched_on(): void
    {
        app(GriotTimeline::class)->savePlays($this->match, $this->plays('summary-liverpool-brighton'));
        app(GriotTimeline::class)->build($this->match);

        $this->get(route('matches.show', $this->match))->assertOk()
            ->assertSee("The Griot's timeline", false)->assertSee('Every shot')->assertSee('The Norse Vikings press on');
        $this->getJson(route('matches.live', $this->match))->assertOk()->assertJsonPath('timeline', fn ($html) => str_contains($html, 'md-tl-feed'));

        config(['griot.enabled' => false]);
        $this->get(route('matches.show', $this->match))->assertOk()->assertDontSee("The Griot's timeline", false);
        $this->assertSame(0, app(GriotTimeline::class)->syncRecent());
        $this->assertSame(MatchPlay::count() > 0, true);
    }

    public function test_voice_clips_are_recorded_into_public_generated_and_linked(): void
    {
        app(GriotTimeline::class)->savePlays($this->match, $this->plays('summary-liverpool-brighton'));
        app(GriotTimeline::class)->build($this->match);
        // A stand-in for Node: writes each job's MP3 and reports it, as radio/griot-voice.mjs does.
        $fake = storage_path('framework/testing/fake-node.sh');
        @mkdir(dirname($fake), 0777, true);
        file_put_contents($fake, "#!/bin/sh\nexec php -r '\$j=json_decode(file_get_contents(\$argv[3]),true);\$o=[];foreach(\$j as \$x){@mkdir(dirname(\$x[\"out\"]),0777,true);file_put_contents(\$x[\"out\"],\"ID3\");\$o[]=[\"id\"=>\$x[\"id\"],\"out\"=>\$x[\"out\"],\"seconds\"=>4];}echo json_encode(\$o);' -- \"\$@\"\n");
        chmod($fake, 0755);
        config(['griot.voice.enabled' => true, 'griot.voice.node' => $fake, 'griot.voice.per_run' => 3]);

        $this->artisan('griot:voice')->assertSuccessful();

        $voiced = GriotPost::whereNotNull('audio_path')->orderBy('seq')->get();
        $this->assertCount(3, $voiced);
        $this->assertSame('kickoff', $voiced->first()->kind);
        $this->assertStringStartsWith("generated/griot/{$this->match->id}/", $voiced->first()->audio_path);
        $this->assertNotNull($voiced->first()->audioUrl());
        $this->get(route('matches.show', $this->match))->assertSee($voiced->first()->audioUrl(), false);
        \Illuminate\Support\Facades\File::deleteDirectory(public_path("generated/griot/{$this->match->id}"));
    }

    public function test_writer_input_and_fact_check_match_the_training_code(): void
    {
        $writer = app(\App\Services\Griot\GriotWriter::class);
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/griot/writer-parity.json')), true) as $case) {
            $this->assertSame($case['prompt'], $writer->prompt($case['input']));
            foreach ($case['checks'] as $check) {
                $this->assertSame($check['problems'], $writer->check($case['input'], $check['output']));
            }
        }
    }

    public function test_writer_captions_are_fact_checked_before_use(): void
    {
        app(GriotTimeline::class)->savePlays($this->match, $this->plays('summary-liverpool-brighton'));
        app(GriotTimeline::class)->build($this->match);
        $writer = app(\App\Services\Griot\GriotWriter::class);
        $isak = GriotPost::where('kind', 'goal')->where('home_score', 2)->first();
        $input = $writer->input($isak, $this->match);

        $this->assertSame(['kind' => 'goal', 'army' => 'Norse Vikings', 'opp_army' => 'Byzantine Cataphracts', 'home' => 'Liverpool',
            'away' => 'Brighton & Hove Albion', 'clock' => '21′', 'score' => '2–0'], array_diff_key($input, ['facts' => 1]));
        $this->assertSame('Alexander Isak', $input['facts']['player']);
        $this->assertNotNull($writer->accept($input, '{"headline":"Isak doubles it","line":"Alexander Isak from six yards. Bradley Barcola gave it. 2–0."}'));
        $this->assertNull($writer->accept($input, '{"headline":"Isak doubles it","line":"Mohamed Salah makes it 3–0."}'));
        $this->assertNull($writer->accept($input, 'not json'));
    }

    public function test_a_failed_recording_backs_off_instead_of_retrying_every_minute(): void
    {
        app(GriotTimeline::class)->savePlays($this->match, $this->plays('summary-liverpool-brighton'));
        app(GriotTimeline::class)->build($this->match);
        config(['griot.voice.enabled' => true, 'griot.voice.node' => '/bin/false']);

        $this->artisan('griot:voice')->assertSuccessful();
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('griot-voice:failed'));
        $this->assertSame(0, GriotPost::whereNotNull('audio_path')->count());
    }
}
