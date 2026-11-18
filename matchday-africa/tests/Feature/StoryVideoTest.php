<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\RadioBulletin;
use App\Models\Standing;
use App\Models\StoryVideo;
use App\Models\Team;
use App\Services\SocialQueue;
use App\Services\Story\StoryFrames;
use App\Services\Story\StoryPlanner;
use App\Services\Story\StoryText;
use App\Services\Story\StoryVideos;
use App\Support\XText;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StoryVideoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'story-test-token-0123456789-abcdefghijklmnop';

    private League $pl;

    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 17:10:00', 'UTC')->setTimezone('Africa/Lagos'));
        config(['app.url' => 'https://matchday.africa', 'story.enabled' => true, 'story.music.paths' => [],
            'slideshow.commons.enabled' => false, 'social.queue_token' => self::TOKEN, 'social.autopost_enabled' => false,
            'radio.enabled' => true, 'radio.video.enabled' => false]);
        Http::preventStrayRequests();
        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        foreach (StoryVideo::pluck('id') as $id) {
            File::deleteDirectory(public_path("generated/stories/{$id}"));
            File::deleteDirectory(storage_path("app/stories/{$id}"));
        }
        parent::tearDown();
    }

    public function test_a_finished_match_renders_a_61_to_75_second_story_into_the_social_queue(): void
    {
        $this->needsFfmpeg();
        config(['story.fps' => 6]); // the full 30 fps render is covered by the timing test below; this keeps the suite quick
        $match = $this->richMatch();
        $this->photo($this->iwobi);

        $this->artisan('story:render')->assertSuccessful();

        $story = StoryVideo::where('match_id', $match->id)->firstOrFail();
        $this->assertSame('ready', $story->status, (string) $story->error);
        $file = $story->publicVideoPath();
        $this->assertFileExists($file);
        $this->assertFileExists($story->publicCoverPath());
        $seconds = SocialQueue::mp4Seconds($file);
        $this->assertGreaterThanOrEqual(61.0, $seconds);
        $this->assertLessThanOrEqual(75.0, $seconds);
        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name,width,height,pix_fmt', '-of', 'json', $file]);
        $probe->mustRun();
        $streams = collect(json_decode($probe->getOutput(), true)['streams']);
        $video = $streams->firstWhere('codec_name', 'h264');
        $this->assertSame([1080, 1920, 'yuv420p'], [$video['width'], $video['height'], $video['pix_fmt']]);
        $this->assertNotNull($streams->firstWhere('codec_name', 'aac'));

        // The public URL is a static file: 200 video/mp4.
        $response = $this->get("/generated/stories/{$story->id}/story.mp4");
        $response->assertOk();
        $response->baseResponse->prepare(\Illuminate\Http\Request::create("/generated/stories/{$story->id}/story.mp4"));
        $this->assertSame('video/mp4', $response->baseResponse->headers->get('Content-Type'));

        // The photo credit and the hook are stored; the featured player is the African one.
        $this->assertSame('Alex Iwobi', data_get($story->facts, 'featured_player'));
        $this->assertSame('Jane Doe / Wikimedia Commons / CC BY-SA 4.0', $story->photo['credit']);
        $this->assertSame("Alex Iwobi broke Arsenal hearts at 89'", $story->hookLine());

        // The social queue item.
        $item = collect($this->withToken(self::TOKEN)->getJson('/api/social-queue')->assertOk()->json('items'))->firstWhere('id', "story:{$story->id}");
        $this->assertSame('match_story', $item['type']);
        $this->assertSame('ready', $item['status']);
        $this->assertSame($story->hookLine(), $item['hook']);
        $this->assertEqualsWithDelta($seconds, $item['duration_seconds'], 0.2);
        $this->assertSame("https://matchday.africa/generated/stories/{$story->id}/story.mp4", $item['video_url']);
        $this->assertStringEndsWith('/cover.jpg', $item['cover_url']);
        $this->assertSame('Jane Doe / Wikimedia Commons / CC BY-SA 4.0', $item['photo_credit']);
        $this->assertSame(['tiktok', 'instagram', 'x', 'youtube'], array_keys($item['captions']));
        foreach (['tiktok', 'instagram', 'x', 'youtube'] as $channel) {
            $this->assertStringContainsString("utm_source={$channel}&utm_medium=social&utm_campaign=story&utm_content=match-{$match->id}", $item['captions'][$channel]);
            $this->assertStringContainsString('#MatchdayAfrica', $item['captions'][$channel]);
        }
        $this->assertLessThanOrEqual(280, XText::length($item['captions']['x']));
        $this->assertStringContainsString('Photo: Jane Doe', $item['captions']['instagram']);
        // Hook first, then the result, the question, the link and 2-4 hashtags; no music credit.
        $tiktok = explode("\n\n", $item['captions']['tiktok']);
        $this->assertSame("Alex Iwobi broke Arsenal hearts at 89'", $tiktok[0]);
        $this->assertStringStartsWith('Full time: Arsenal 2-2 Chelsea', $tiktok[1]);
        $this->assertSame($story->facts['question'], $tiktok[2]);
        $this->assertStringStartsWith('https://', $tiktok[3]);
        $this->assertMatchesRegularExpression('/^(#\w+ ?){2,4}$/u', $tiktok[4]);
        $this->assertStringContainsString('#MatchdayAfrica', $tiktok[4]);
        foreach ($item['captions'] as $caption) {
            $this->assertStringNotContainsString('Music:', $caption);
            $this->assertStringNotContainsStringIgnoringCase('original beat', $caption);
        }
        $this->assertSame('Arsenal', $item['match']['home']);

        // The bot marks it posted like any other item.
        $this->withToken(self::TOKEN)->postJson("/api/social-queue/story:{$story->id}/posted", ['channel' => 'tiktok', 'external_post_id' => 't1'])
            ->assertOk()->assertJsonPath('channels.tiktok.posted', true);
    }

    public function test_a_full_30_fps_story_draws_fast_enough_for_the_ten_minute_window(): void
    {
        $match = $this->richMatch();
        $plan = app(StoryPlanner::class)->plan($match);
        $frames = app(StoryFrames::class);
        $started = microtime(true);
        $n = 0;
        // Ten seconds of the real timeline at 30 fps.
        $beats = [];
        $at = 0.0;
        foreach ($plan['beats'] as $beat) {
            if ($at >= 10) {
                break;
            }
            $beats[] = ['start' => $at] + $beat;
            $at += $beat['seconds'];
        }
        foreach ($frames->frames($beats, 30) as $jpeg) {
            $n++;
        }
        $perFrame = (microtime(true) - $started) / max(1, $n);
        // 75 s at 30 fps must draw well inside the window, leaving room for x264 and other matches.
        $this->assertLessThan(0.12, $perFrame, sprintf('%.0f ms a frame', $perFrame * 1000));
    }

    public function test_every_story_lasts_61_to_75_seconds_with_cuts_of_3_to_5_seconds(): void
    {
        // A goalless match with no history, stats or table: padded with verified recaps.
        $quiet = $this->match('Burnley', 'Brentford', [0, 0]);
        // A nine-goal match with red cards: shrunk, optional beats dropped.
        $wild = $this->match('Spurs', 'Leeds', [6, 3]);
        foreach ([[19, 'home'], [22, 'away'], [30, 'home'], [35, 'home'], [44, 'away'], [51, 'home'], [60, 'home'], [77, 'away'], [88, 'home']] as $i => [$min, $side]) {
            $this->event($wild, 'goal', $side, $min, 'Player '.$i);
        }
        $this->event($wild, 'red_card', 'away', 70, 'Defender');
        $this->event($wild, 'red_card', 'home', 81, 'Midfielder');
        $this->standings($wild, 4, 18);

        // A goalless match with no history, stats or table has too few distinct cards: the render fails
        // with the reason rather than repeat one.
        try {
            app(StoryPlanner::class)->plan($quiet);
            $this->fail('A story with too few distinct cards was planned.');
        } catch (\App\Services\Story\StoryTooShort $e) {
            $this->assertStringContainsString('cards are never repeated', $e->getMessage());
        }
        foreach ([$wild, $this->richMatch()] as $match) {
            $plan = app(StoryPlanner::class)->plan($match);
            $total = array_sum(array_column($plan['beats'], 'seconds'));
            // A card is never repeated: a match with almost no data ends when its distinct cards run out.
            $this->assertGreaterThanOrEqual(61, $total, "match {$match->id}");
            app(StoryFrames::class)->assertNoRepeats($plan['beats']);
            $this->assertLessThanOrEqual(75, $total, "match {$match->id}");
            $this->assertSame('hook', $plan['beats'][0]['type']);
            $this->assertEquals(3.0, $plan['beats'][0]['seconds']);
            $this->assertSame('close', end($plan['beats'])['type']);
            foreach ($plan['beats'] as $beat) {
                $this->assertGreaterThanOrEqual(3.0, $beat['seconds'], $beat['type']);
                $this->assertLessThanOrEqual(5.0, $beat['seconds'], $beat['type']);
            }
            // Every goal and red card has its own beat.
            $events = MatchEvent::where('match_id', $match->id)->count();
            $this->assertSame($events, collect($plan['beats'])->whereIn('type', ['goal', 'red'])->count());
            // And every cut lays out inside the TikTok safe zone (scene() throws otherwise).
            foreach ($plan['beats'] as $beat) {
                [$bg, $items] = app(StoryFrames::class)->scene($beat);
                imagedestroy($bg);
                foreach ($items as $item) {
                    if ($item['text'] !== '') {
                        $this->assertGreaterThanOrEqual(150, $item['top']);
                        $this->assertLessThanOrEqual(1540, $item['bottom']);
                        $this->assertLessThanOrEqual(940, $item['x'] + $item['width']);
                    }
                }
            }
        }
        $this->assertSame('Ninety minutes, zero goals. The nets stayed dry.', app(StoryPlanner::class)->banter($quiet, StoryText::for('en'), app(StoryPlanner::class)->sides($quiet)));
        $this->assertSame('Leeds down to ten, then this happened', app(StoryPlanner::class)->plan($wild)['hook']);
    }

    public function test_hooks_come_from_the_data(): void
    {
        $planner = app(StoryPlanner::class);
        $hook = fn (FootballMatch $m) => $planner->hookFor($m);

        // Late winner (80'+): the losing side's hearts.
        $winner = $this->match('Brighton', 'Villa', [2, 1]);
        $this->event($winner, 'goal', 'home', 10, 'Danny Welbeck');
        $this->event($winner, 'goal', 'away', 60, 'Ollie Watkins');
        $this->event($winner, 'goal', 'home', 90, 'Kaoru Mitoma', extra: 4);
        $this->assertSame("Kaoru Mitoma broke Villa hearts at 90+4'", $hook($winner));
        // Late equaliser: the side that was leading.
        $level = $this->match('Fulham', 'Spurs', [1, 1]);
        $this->event($level, 'goal', 'away', 20, 'Son Heung-min');
        $this->event($level, 'goal', 'home', 88, 'Alex Iwobi');
        $this->assertSame("Alex Iwobi broke Spurs hearts at 88'", $hook($level));
        // Upset: the side four or more places lower wins.
        $upset = $this->match('Burnley', 'Liverpool', [2, 0]);
        $this->event($upset, 'goal', 'home', 30, 'Lyle Foster');
        $this->event($upset, 'goal', 'home', 60, 'Zian Flemming');
        $this->standings($upset, 19, 1);
        $this->assertSame('Nobody saw Burnley doing this', $hook($upset));
        // Red card.
        $red = $this->match('Everton', 'Fulham', [2, 0]);
        $this->event($red, 'goal', 'home', 30, 'Iliman Ndiaye');
        $this->event($red, 'goal', 'home', 40, 'Beto');
        $this->event($red, 'red_card', 'away', 50, 'Calvin Bassey');
        $this->assertSame('Fulham down to ten, then this happened', $hook($red));
        // Brace or hat-trick.
        $brace = $this->match('Arsenal', 'Wolves', [2, 0]);
        $this->event($brace, 'goal', 'home', 12, 'Bukayo Saka');
        $this->event($brace, 'goal', 'home', 70, 'Bukayo Saka');
        $this->assertSame('Bukayo Saka could not stop scoring', $hook($brace));
        // 1-0.
        $one = $this->match('Niger', 'Morocco', [0, 1]);
        $this->event($one, 'goal', 'away', 22, 'Abdellah Ouazane');
        $this->assertSame('One moment from Abdellah Ouazane settled it', $hook($one));
        // 0-0: the side with more shots.
        $none = $this->match('Burnley', 'Brentford', [0, 0]);
        $none->update(['home_shots' => 4, 'away_shots' => 11]);
        $this->assertSame('Brentford had chances. Where was the finish?', $hook($none->fresh()));
        // Anything else: a question about the result.
        $win = $this->match('Leeds', 'Spurs', [3, 1]);
        foreach ([[10, 'home'], [20, 'away'], [50, 'home'], [60, 'home']] as $i => [$min, $side]) {
            $this->event($win, 'goal', $side, $min, "Scorer {$i}");
        }
        $this->assertSame('Who saw Leeds 3-1 coming?', $hook($win));

        // Sentence case, at most 8 words; a long name falls back to the surname.
        $long = $this->match('Brighton', 'Wolverhampton Wanderers Football Club', [2, 1]);
        $this->event($long, 'goal', 'home', 10, 'Danny Welbeck');
        $this->event($long, 'goal', 'away', 60, 'Ollie Watkins');
        $this->event($long, 'goal', 'home', 85, 'Kaoru Mitoma Junior');
        foreach ([$winner, $level, $upset, $red, $brace, $one, $none, $win, $long] as $m) {
            $line = $hook($m->fresh());
            $this->assertLessThanOrEqual(8, StoryPlanner::words($line), $line);
            $this->assertSame([], \App\Design\CopyRules::violations($line), $line);
        }

        // An editor's hook and banter replace the generated ones on screen, not in the record.
        $plan = $planner->plan($this->richMatch(), 'en', 'Saka on fire', 'Wolves, call a taxi');
        $this->assertSame('Saka on fire', $plan['beats'][0]['line']);
        $this->assertSame('Wolves, call a taxi', collect($plan['beats'])->firstWhere('type', 'banter')['line']);
        $this->assertSame("Alex Iwobi broke Arsenal hearts at 89'", $plan['hook']);
    }

    public function test_the_story_opens_on_the_winning_scorer_with_a_licensed_photo_found_by_name(): void
    {
        config(['slideshow.commons.enabled' => true]);
        File::deleteDirectory(storage_path('app/player-media'));
        $img = imagecreatetruecolor(1080, 1440);
        imagefill($img, 0, 0, imagecolorallocate($img, 150, 90, 60));
        ob_start();
        imagejpeg($img);
        $jpeg = ob_get_clean();
        Http::fake([
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => [['p' => ['value' => 'http://www.wikidata.org/entity/Q1'],
                'img' => ['value' => 'http://commons.wikimedia.org/wiki/Special:FilePath/Abdellah%20Ouazane%202026.jpg']]]]]),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => ['1' => ['title' => 'File:Abdellah Ouazane 2026.jpg', 'imageinfo' => [[
                'mime' => 'image/jpeg', 'width' => 1440, 'thumburl' => 'https://upload.wikimedia.org/a.jpg', 'url' => 'https://upload.wikimedia.org/a.jpg',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Abdellah_Ouazane_2026.jpg',
                'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-SA 4.0'], 'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0'],
                    'Artist' => ['value' => '<a href="//x">Sam Photographer</a>']]]]]]]]),
            'upload.wikimedia.org/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $match = $this->match('Niger', 'Morocco', [0, 1]);
        $this->event($match, 'goal', 'away', 22, 'Abdellah Ouazane');
        $this->event($match, 'yellow_card', 'home', 90, 'Aboubacar Camara');
        // No player rows for national teams: the scorer is found by name.
        [, , $hook] = app(StoryPlanner::class)->opening($match);
        $this->assertSame('One moment from Abdellah Ouazane settled it', $hook['line']);
        $this->assertSame('Abdellah Ouazane', $hook['player']['name']);
        $this->assertSame('Sam Photographer / Wikimedia Commons / CC BY-SA 4.0', $hook['photo']['credit']);
        $stored = json_decode((string) file_get_contents(storage_path('app/player-media/abdellah-ouazane.json')), true);
        $this->assertSame(['ok', 'CC BY-SA 4.0', 'Sam Photographer'], [$stored['status'], $stored['licence'], $stored['artist']]);
        // Cached: the second plan asks nobody.
        $sent = count(Http::recorded());
        app(StoryPlanner::class)->opening($match);
        $this->assertSame($sent, count(Http::recorded()));

        // The opening card: the photo fills the whole 1080x1920 frame with a Ken Burns push and a dark gradient
        // under the text; the hook is at least 90 px, on screen from frame 0 and in the lower part of the frame:
        // below the top 45% and below the face. Its lines are balanced (no single word on the last line).
        $scene = app(StoryFrames::class)->build($hook);
        $photo = collect($scene->items)->firstWhere('kind', 'photo');
        $this->assertNotNull($photo);
        $this->assertSame([0, 0, 1080, 1920], $photo['box']);
        $this->assertSame('kenburns', $photo['motion']);
        $this->assertNotEmpty($photo['shade']);
        $face = collect($scene->photos)->firstWhere('own', false)['face'];
        $this->assertNotNull($face);
        $this->assertLessThan(0.45 * 1920, $face[0]);
        $words = collect($scene->items)->where('kind', 'text')->where('style', 'hook');
        $this->assertNotEmpty($words);
        foreach ($words as $w) {
            $this->assertGreaterThanOrEqual(90, $w['size']);
            $this->assertTrue($w['visible']);
            $this->assertGreaterThanOrEqual(0.45 * 1920, $w['top']);
            $this->assertGreaterThan($face[1], $w['top']);
            $this->assertGreaterThanOrEqual($photo['shade'][1], $w['top'] + 20, 'the hook sits on the dark part of the gradient');
            $this->assertStringContainsString(' ', $w['text'], 'no one-word line');
        }
        foreach (collect($scene->items)->where('kind', 'text') as $item) {
            $this->assertGreaterThanOrEqual(\App\Design\Tokens::safe()['top'], $item['top'], $item['text']);
        }
        $this->assertSame('Photo: Sam Photographer / Wikimedia Commons / CC BY-SA 4.0', $scene->credit);
        $scene->destroy();
        File::deleteDirectory(storage_path('app/player-media'));
    }

    public function test_without_a_licensed_photo_the_hook_shows_the_name_and_both_flags_never_a_letter(): void
    {
        $match = $this->match('Niger', 'Morocco', [0, 1]);
        $this->event($match, 'goal', 'away', 22, 'Abdellah Ouazane');
        $hook = ['type' => 'hook', 'line' => 'One moment from Abdellah Ouazane settled it', 'score' => '0-1', 'start' => 0, 'seconds' => 3, 'lang' => 'en',
            'player' => ['name' => 'Abdellah Ouazane', 'label' => 'Morocco international', 'club' => null, 'flag' => null, 'side' => 'away'], 'photo' => null,
            'sides' => app(StoryPlanner::class)->sides($match)];
        $scene = app(StoryFrames::class)->build($hook);
        $this->assertNull(collect($scene->items)->firstWhere('kind', 'photo'));
        $texts = collect($scene->items)->where('kind', 'text')->pluck('text');
        $this->assertFalse($texts->contains(fn ($t) => mb_strlen($t) === 1), 'no single letters');
        $name = collect($scene->items)->first(fn ($i) => ($i['kind'] ?? '') === 'text' && $i['text'] === 'Abdellah Ouazane');
        $this->assertNotNull($name);
        $this->assertGreaterThanOrEqual(96, $name['size']); // big: the hook style
        $scene->destroy();
    }

    public function test_the_lead_player_is_the_winning_scorer_then_the_top_scorer_and_follows_the_hook(): void
    {
        $planner = app(StoryPlanner::class);
        $lead = fn (FootballMatch $m) => $planner->leadPlayer($m, $planner->incidents($m), $planner->sides($m));
        // 3-1: the goal that made it 2-1 won it.
        $m = $this->match('Arsenal', 'Chelsea', [3, 1]);
        $this->event($m, 'goal', 'home', 10, 'Bukayo Saka');
        $this->event($m, 'goal', 'away', 20, 'Cole Palmer');
        $this->event($m, 'goal', 'home', 30, 'Kai Havertz');
        $this->event($m, 'goal', 'home', 80, 'Bukayo Saka');
        $this->assertSame(['Kai Havertz', 'winning_goal'], [$lead($m)['name'], $lead($m)['reason']]);
        // ...but the generated hook names Saka's brace, so the story opens on Saka.
        [$line, $opening] = $planner->opening($m->fresh(['homeTeam', 'awayTeam', 'league']));
        $this->assertSame('Bukayo Saka could not stop scoring', $line);
        $this->assertSame('Bukayo Saka', $opening['name']);
        // A draw: the last scorer.
        $d = $this->match('Fulham', 'Spurs', [1, 1]);
        $this->event($d, 'goal', 'home', 10, 'Raul Jimenez');
        $this->event($d, 'goal', 'away', 50, 'Son Heung-min');
        $this->assertSame('Son Heung-min', $lead($d)['name']);
        // The winning goal was an own goal: the top scorer.
        $o = $this->match('Everton', 'Wolves', [2, 1]);
        $this->event($o, 'goal', 'home', 10, 'Beto');
        $this->event($o, 'goal', 'away', 20, 'Matheus Cunha');
        MatchEvent::create(['football_data_id' => 990001, 'match_id' => $o->id, 'team_id' => $o->home_team_id, 'type' => 'goal', 'minute' => 70,
            'player_name' => 'Toti', 'is_own_goal' => true]);
        $this->assertSame(['Beto', 'top_scorer'], [$lead($o)['name'], $lead($o)['reason']]);
        // No goals: the best-rated African player in the line-up (an in-scope player who played).
        $none = $this->match('Burnley', 'Fulham', [0, 0]);
        $this->iwobiAt($none->awayTeam);
        $this->assertNull($lead($none)); // no line-up or rating data: nobody is claimed
    }

    public function test_without_a_photo_for_the_scorer_the_next_player_with_a_photo_leads(): void
    {
        $planner = app(StoryPlanner::class);
        $match = $this->match('Niger', 'Morocco', [0, 1]);
        $this->event($match, 'goal', 'away', 22, 'Abdellah Ouazane');
        $this->event($match, 'yellow_card', 'away', 40, 'Achraf Hakimi');
        Player::create(['team_id' => $match->away_team_id, 'name' => 'Abdellah Ouazane', 'nationality' => 'Morocco', 'nationality_code' => 'MAR', 'is_active' => true]);
        $hakimi = Player::create(['team_id' => $match->away_team_id, 'name' => 'Achraf Hakimi', 'nationality' => 'Morocco', 'nationality_code' => 'MAR', 'is_active' => true]);
        // Nobody has a photo: the scorer leads (the hook then shows the name and flags).
        $lead = $planner->leadPlayer($match, $planner->incidents($match), $planner->sides($match));
        $this->assertSame(['Abdellah Ouazane', null], [$lead['name'], $lead['photo']]);
        // Hakimi has a licensed photo: he leads, and the scorer is recorded as the one passed over.
        $this->photo($hakimi);
        $lead = $planner->leadPlayer($match, $planner->incidents($match), $planner->sides($match));
        $this->assertSame('Achraf Hakimi', $lead['name']);
        $this->assertSame('Abdellah Ouazane', $lead['fallback']);
        $this->assertSame('CC BY-SA 4.0', $lead['photo']['licence']);
        // The cover shows Hakimi, so the hook must not name Ouazane: it falls back to a team-level line.
        [$line, $opening, $beat] = $planner->opening($match->fresh(['homeTeam', 'awayTeam', 'league']));
        $this->assertSame('Achraf Hakimi', $opening['name']);
        $this->assertSame('Who saw Morocco 1-0 coming?', $line);
        $this->assertSame($line, $beat['line']);
        // A pictured player who scored: the hook may name them, with that fact only.
        $this->event($match, 'goal', 'away', 70, 'Achraf Hakimi');
        $match->update(['away_score' => 2]);
        [$line, $opening] = $planner->opening($match->fresh(['homeTeam', 'awayTeam', 'league']));
        $this->assertSame('Achraf Hakimi', $opening['name']);
        $this->assertStringNotContainsString('Ouazane', $line);
    }

    public function test_hook_lines_are_balanced_without_a_single_word_last_line(): void
    {
        $text = 'Who saw Morocco 1-0 coming in the rain tonight?';
        $lines = \App\Design\Canvas::balanced($text, 'hook', 860, 1000, 4);
        $this->assertGreaterThan(1, count($lines));
        $this->assertSame($text, implode(' ', array_column($lines, 0)));
        foreach ($lines as [$line]) {
            $this->assertStringContainsString(' ', $line);
        }
        $file = \App\Design\Tokens::type('hook')['file'];
        $widths = array_map(fn ($l) => \App\Design\Canvas::measure($l[0], $file, $l[1])[0], $lines);
        $this->assertLessThan(0.5 * max($widths), max($widths) - min($widths), 'even line lengths');
        $this->assertSame(['portrait-2024.jpg', 'Player v Club 2025.jpg', 'Player club crest shirt.jpg'],
            \App\Services\Slideshow\CommonsPhotos::preferFewerCrests(['Player club crest shirt.jpg', 'Player v Club 2025.jpg', 'portrait-2024.jpg']));
        // Downloads use Wikimedia's standard thumbnail sizes: an original under 1280 px becomes the 960 px thumbnail.
        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/thumb/7/77/A_B_%28cropped%29.jpg/960px-A_B_%28cropped%29.jpg',
            \App\Services\Slideshow\CommonsPhotos::standardThumb('https://upload.wikimedia.org/wikipedia/commons/7/77/A_B_%28cropped%29.jpg', 1275,
                'https://upload.wikimedia.org/wikipedia/commons/7/77/A_B_%28cropped%29.jpg?utm_content=thumbnail_unscaled'));
        $big = 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/77/A.jpg/1280px-A.jpg';
        $this->assertSame($big, \App\Services\Slideshow\CommonsPhotos::standardThumb('https://upload.wikimedia.org/wikipedia/commons/7/77/A.jpg', 4000, $big));
    }

    public function test_substitutions_make_a_changes_card_and_kick_off_shows_once(): void
    {
        $match = $this->match('Niger', 'Morocco', [0, 1]);
        $match->forceFill(['venue_name' => 'Stade Prince Moulay Abdallah'])->save();
        $this->event($match, 'goal', 'away', 22, 'Abdellah Ouazane');
        MatchEvent::create(['football_data_id' => 990100, 'match_id' => $match->id, 'team_id' => $match->away_team_id, 'type' => 'substitution',
            'minute' => 61, 'player_name' => 'Youssef En-Nesyri', 'related_player_name' => 'Yassir Zabiri']);
        $planner = app(StoryPlanner::class);
        $t = \App\Services\Story\StoryText::for('en');
        $sides = $planner->sides($match->fresh());
        $call = fn (string $method, ...$args) => (new \ReflectionMethod($planner, $method))->invoke($planner, ...$args);
        $this->assertSame(["61' Yassir Zabiri on for Youssef En-Nesyri (Morocco)"], $call('changes', $match, $t, $sides));
        // The build-up card names the venue and kick-off; the padding never repeats them.
        $this->assertStringContainsString('Moulay Abdallah', json_encode($call('buildup', $match, $t, $sides, ['home' => null, 'away' => null])['lines']));
        $goals = $planner->incidents($match)->where('type', 'goal')->values();
        $pads = json_encode($call('padding', $match, $t, $sides, $goals));
        $this->assertStringNotContainsString('Moulay Abdallah', $pads);
        $this->assertStringNotContainsString('Kick-off', $pads);
    }

    public function test_a_story_never_shows_a_card_twice(): void
    {
        foreach ([$this->richMatch()] as $match) {
            $plan = app(StoryPlanner::class)->plan($match);
            app(StoryFrames::class)->assertNoRepeats($plan['beats']);
            $types = collect($plan['beats'])->pluck('type');
            $this->assertLessThanOrEqual(1, $types->filter(fn ($t) => $t === 'recap')->count());
            $this->assertLessThanOrEqual(1, $types->filter(fn ($t) => $t === 'facts')->count());
        }
    }

    public function test_french_puts_the_on_screen_text_and_captions_in_french(): void
    {
        $match = $this->richMatch();
        $plan = app(StoryPlanner::class)->plan($match, 'fr');
        $this->assertSame("Alex Iwobi a brisé Arsenal à la 89'", $plan['hook']);
        $this->assertSame('But', StoryText::for('fr')->t('goal'));
        $this->assertSame('Abonnez-vous pour la suite', end($plan['beats'])['follow']);
        $this->assertSame('L\'enjeu', $plan['beats'][1]['title']);

        $story = StoryVideo::create(['kind' => 'match_story', 'match_id' => $match->id, 'lang' => 'fr', 'status' => 'ready',
            'generated_hook' => $plan['hook'], 'facts' => $plan['facts'] + ['question' => $plan['question']]]);
        $captions = app(StoryVideos::class)->captions($story->fresh('match'));
        $this->assertStringContainsString('Fin du match : Arsenal 2-2 Chelsea (Premier League).', $captions['instagram']);
        $this->assertStringContainsString('utm_campaign=story', $captions['x']);
    }

    public function test_finished_matches_are_queued_after_full_time_and_only_while_switched_on(): void
    {
        $match = $this->richMatch();
        $other = League::create(['football_data_id' => 2015, 'short_code' => 'FL1', 'name' => 'Ligue 1']);
        $french = $this->match('Lyon', 'Lens', [1, 1], league: $other);
        $live = $this->match('Bournemouth', 'Wolves', [0, 0]);
        $live->update(['status' => 'IN_PLAY']);

        config(['story.enabled' => false]);
        $this->artisan('story:render')->expectsOutputToContain('off')->assertSuccessful();
        $this->assertSame(0, StoryVideo::count());

        config(['story.enabled' => true]);
        $added = app(StoryVideos::class)->queueFinished();
        $this->assertSame(1, $added);
        $story = StoryVideo::firstOrFail();
        $this->assertSame([$match->id, 'pending', 'auto', 'en'], [$story->match_id, $story->status, $story->source, $story->lang]);
        $this->assertSame(0, StoryVideo::where('match_id', $french->id)->count());
        // Queued once only.
        $this->assertSame(0, app(StoryVideos::class)->queueFinished());

        // A story drawn before a late VAR change is drawn again, once.
        $story->update(['status' => 'ready', 'rendered_at' => now(), 'fingerprint' => 'old']);
        $this->assertSame(1, app(StoryVideos::class)->queueFinished());
        $this->assertSame(['pending', 1], [$story->fresh()->status, $story->fresh()->refreshes]);
        $story->update(['status' => 'ready', 'rendered_at' => now(), 'fingerprint' => 'old again']);
        $this->assertSame(0, app(StoryVideos::class)->queueFinished());
    }

    public function test_renders_run_in_parallel_up_to_the_concurrency_and_failures_retry(): void
    {
        config(['story.concurrency' => 2]);
        $a = app(StoryVideos::class)->request('match_story', $this->match('A', 'B', [1, 0])->id);
        $b = app(StoryVideos::class)->request('match_story', $this->match('C', 'D', [1, 0])->id);
        $c = app(StoryVideos::class)->request('match_story', $this->match('E', 'F', [1, 0])->id);
        $this->assertSame($a->id, app(StoryVideos::class)->claimNext()->id);
        $this->assertSame($b->id, app(StoryVideos::class)->claimNext()->id);
        $this->assertNull(app(StoryVideos::class)->claimNext());
        $a->update(['status' => 'failed', 'error' => 'x', 'updated_at' => now()->subMinutes(5)]);
        $this->assertSame($c->id, app(StoryVideos::class)->claimNext()->id);
        StoryVideo::whereKey($b->id)->update(['status' => 'failed', 'updated_at' => now()->subMinutes(5)]);
        StoryVideo::whereKey($c->id)->update(['status' => 'ready']);
        $this->assertContains(app(StoryVideos::class)->claimNext()->id, [$a->id, $b->id]);
    }

    public function test_a_radio_edition_gets_a_radio_video_with_its_own_audio_and_replaces_the_radio_queue_item(): void
    {
        $this->needsFfmpeg();
        config(['story.fps' => 5]);
        $player = $this->iwobiAt(Team::firstOrCreate(['name' => 'Fulham']));
        $this->photo($player);
        $b = $this->edition(64, "Alex Iwobi scored again for Fulham on Saturday, his fourth goal of the season.");

        $story = app(StoryVideos::class)->request('radio_video', $b->id, 'en', [], 'api');
        $this->assertTrue(app(StoryVideos::class)->render($story), (string) $story->fresh()->error);

        $story->refresh();
        $this->assertSame('ready', $story->status, (string) $story->error);
        $this->assertEqualsWithDelta(64.0, SocialQueue::mp4Seconds($story->publicVideoPath()), 0.6);
        $this->assertSame($player->id, $story->player_id);
        $this->assertSame('Jane Doe / Wikimedia Commons / CC BY-SA 4.0', $story->photo['credit']);
        $this->assertNull($story->music);
        $items = collect($this->withToken(self::TOKEN)->getJson('/api/social-queue')->json('items'));
        $item = $items->firstWhere('id', "story:{$story->id}");
        $this->assertSame('radio_video', $item['type']);
        $this->assertStringContainsString('utm_campaign=story', $item['captions']['tiktok']);
        $this->assertStringContainsString((string) config('radio.disclosure'), $item['captions']['instagram']);
        $this->assertNull($items->firstWhere('id', "radio:{$b->id}"));
    }

    public function test_radio_captions_are_drawn_word_by_word_and_cover_the_whole_edition(): void
    {
        $b = $this->edition(64, 'Short news.', real: false);
        $scenes = app(\App\Services\RadioVideo::class)->scenes($b, 64.0);
        $beat = ['type' => 'radio', 'start' => 0, 'seconds' => 4.0, 'caption' => 'Arsenal beat Chelsea two one at the Emirates',
            'title' => 'Arsenal 2-1 Chelsea', 'edition' => 'Saturday midday edition', 'photo' => null, 'art' => null, 'lang' => 'en', 'sides' => null];
        [$bg, $items] = app(StoryFrames::class)->scene($beat);
        imagedestroy($bg);
        $words = collect($items)->filter(fn ($i) => in_array($i['text'], ['Arsenal', 'beat', 'Chelsea', 'Emirates'], true));
        $this->assertCount(4, $words);
        $this->assertTrue($words->pluck('delay')->sort()->values()->all() === $words->pluck('delay')->values()->all());
        $this->assertEqualsWithDelta(64.0, end($scenes)['end'], 0.001);
    }

    public function test_silence_is_a_valid_wav_and_music_is_looped_and_normalised(): void
    {
        $file = storage_path('app/testing/silence.wav');
        File::ensureDirectoryExists(dirname($file));
        StoryVideos::writeSilence($file, 2.0);
        $this->assertSame('RIFF', substr((string) file_get_contents($file), 0, 4));
        $this->assertSame(44 + 3 * 24000 * 2, filesize($file));
        File::delete($file);

        $args = app(StoryVideos::class)->args('/music.mp3', true, 68.0, '/out.mp4');
        $this->assertSame('-stream_loop', $args[array_search('/music.mp3', $args, true) - 3]);
        $this->assertStringContainsString('loudnorm=I=-14', $args[array_search('-af', $args, true) + 1]);
        $this->assertSame('68', $args[array_search('-t', $args, true) + 1]);
        $this->assertNotContains('-stream_loop', app(StoryVideos::class)->args('/radio.mp3', false, 68.0, '/out.mp4'));
    }

    // ------------------------------------------------------------------ fixtures

    private Player $iwobi;

    /** Arsenal 2-2 Chelsea: goals with assists, a red card, a late equaliser by an African player, stats, table, history, next fixtures. */
    private function richMatch(): FootballMatch
    {
        $match = $this->match('Arsenal', 'Chelsea', [2, 2]);
        $match->update(['home_possession' => 58, 'away_possession' => 42, 'home_shots' => 14, 'away_shots' => 9,
            'home_shots_on_target' => 6, 'away_shots_on_target' => 3, 'home_corners' => 7, 'away_corners' => 2,
            'venue_name' => 'Emirates Stadium', 'referee_name' => 'Michael Oliver', 'attendance' => 60112, 'home_score_ht' => 1, 'away_score_ht' => 0]);
        $this->iwobi = $this->iwobiAt($match->awayTeam);
        $this->event($match, 'goal', 'home', 23, 'Bukayo Saka', 'Martin Ødegaard');
        $this->event($match, 'red_card', 'away', 55, 'Moisés Caicedo');
        $this->event($match, 'goal', 'away', 61, 'Cole Palmer');
        $this->event($match, 'goal', 'home', 77, 'Kai Havertz');
        $this->event($match, 'goal', 'away', 89, 'Alex Iwobi', 'Cole Palmer');
        $this->standings($match, 2, 7);
        $old = $this->match('Chelsea', 'Arsenal', [0, 1], when: '2026-03-01 15:00:00');
        $old->update(['status' => 'FINISHED']);
        FootballMatch::create(['league_id' => $this->pl->id, 'home_team_id' => $match->home_team_id, 'away_team_id' => Team::firstOrCreate(['name' => 'Spurs'])->id,
            'match_date' => '2026-11-23 16:30:00', 'status' => 'SCHEDULED']);

        return $match->fresh(['homeTeam', 'awayTeam', 'league']);
    }

    private function iwobiAt(Team $team): Player
    {
        return Player::create(['team_id' => $team->id, 'name' => 'Alex Iwobi', 'nationality' => 'Nigeria', 'nationality_code' => 'NGA', 'is_active' => true, 'position' => 'Midfielder']);
    }

    private function match(string $home, string $away, array $score, ?League $league = null, string $when = '2026-11-15 15:00:00'): FootballMatch
    {
        return FootballMatch::create([
            'league_id' => ($league ?? $this->pl)->id, 'home_team_id' => Team::firstOrCreate(['name' => $home])->id,
            'away_team_id' => Team::firstOrCreate(['name' => $away])->id, 'match_date' => $when, 'status' => 'FINISHED',
            'home_score' => $score[0], 'away_score' => $score[1], 'last_api_update' => now()->subMinutes(5),
        ]);
    }

    private function event(FootballMatch $match, string $type, string $side, int $minute, string $player, ?string $assist = null, ?int $extra = null): void
    {
        MatchEvent::create(['football_data_id' => 900000 + MatchEvent::count(), 'match_id' => $match->id, 'team_id' => $side === 'home' ? $match->home_team_id : $match->away_team_id,
            'type' => $type, 'minute' => $minute, 'extra_minute' => $extra, 'player_name' => $player, 'assist_player_name' => $assist]);
    }

    private function standings(FootballMatch $match, int $home, int $away): void
    {
        foreach ([[$match->home_team_id, $home], [$match->away_team_id, $away]] as [$team, $pos]) {
            Standing::create(['league_id' => $match->league_id, 'team_id' => $team, 'position' => $pos, 'points' => 40 - $pos,
                'matches_played' => 12, 'season_year' => 2026, 'season' => '2026/27', 'is_current' => true, 'league_football_data_id' => 2021,
                'team_football_data_id' => $team, 'wins' => 5, 'draws' => 3, 'losses' => 4, 'goals_for' => 20, 'goals_against' => 15,
                'goal_difference' => 5, 'calculation_date' => '2026-11-10']);
        }
    }

    /** A licensed Commons photo already in the media library (as CommonsPhotos stores it). */
    private function photo(Player $player): void
    {
        $path = "slideshow-photos/{$player->id}.jpg";
        File::ensureDirectoryExists(storage_path('app/slideshow-photos'));
        $img = imagecreatetruecolor(900, 1200);
        imagefill($img, 0, 0, imagecolorallocate($img, 90, 120, 160));
        imagejpeg($img, storage_path("app/{$path}"));
        imagedestroy($img);
        $this->dirs[] = storage_path("app/{$path}");
        $player->forceFill(['metadata' => ['commons_photo' => ['status' => 'ok', 'path' => $path, 'crop' => ['x' => 180, 'y' => 72, 'w' => 540, 'h' => 540],
            'title' => 'File:Iwobi.jpg', 'source_url' => 'https://commons.wikimedia.org/wiki/File:Iwobi.jpg', 'licence' => 'CC BY-SA 4.0',
            'licence_url' => 'https://creativecommons.org/licenses/by-sa/4.0', 'artist' => 'Jane Doe', 'credit' => 'Jane Doe / Wikimedia Commons / CC BY-SA 4.0']]])->saveQuietly();
    }

    private function edition(int $seconds, string $lead, bool $real = true): RadioBulletin
    {
        $facts = [['key' => 'news:1', 'kind' => 'news', 'headline' => 'Iwobi scores again', 'text' => $lead, 'source' => 'BBC Sport', 'url' => 'https://example.test/1']];
        $segments = [['fact_keys' => ['news:1'], 'text' => $lead]];
        $b = RadioBulletin::create(['slot' => '2026-11-15-18', 'title' => 'The evening update', 'status' => 'published', 'stage' => 'audio',
            'recorded_at' => now(), 'published_at' => now()->subMinutes(5), 'facts' => $facts, 'segments' => $segments,
            'script' => "Welcome to Matchday Radio.\n\n{$lead}\n\nThat's Matchday Radio.", 'audio_cues' => [['paragraph' => 1, 'start' => 4.0, 'end' => $seconds - 5.0]]]);
        $relative = 'radio/'.$b->id.'/'.Str::uuid().'.mp3';
        $file = storage_path('app/'.$relative);
        File::ensureDirectoryExists(dirname($file));
        $this->dirs[] = dirname($file);
        $this->dirs[] = public_path("generated/radio/{$b->id}");
        if ($real) {
            (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', "sine=frequency=220:duration={$seconds}",
                '-ac', '1', '-b:a', '24k', $file]))->mustRun();
        } else {
            File::put($file, str_repeat('x', 2048));
        }
        $b->update(['audio_path' => $relative, 'audio_bytes' => filesize($file), 'duration_seconds' => $seconds, 'voice' => 'griot']);

        return $b->fresh();
    }

    private function needsFfmpeg(): void
    {
        $missing = app(StoryVideos::class)->missing();
        if ($missing) {
            $this->markTestSkipped('ffmpeg for story videos is not available here: '.implode(', ', $missing));
        }
    }
}
