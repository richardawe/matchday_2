<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerHeritage;
use App\Models\Slideshow;
use App\Models\Team;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Services\Slideshow\CommonsPhotos;
use App\Services\Slideshow\SlideshowDeck;
use App\Services\Slideshow\SlideshowPicker;
use App\Services\Slideshow\SlideshowRenderer;
use App\Services\Slideshow\Slideshows;
use App\Services\Slideshow\SlideshowVideo;
use App\Services\Slideshow\UnsafeLayout;
use App\Services\SocialQueue;
use App\Services\YouTube\SlideshowUploads;
use App\Support\AfricanScope;
use App\Support\XText;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Colour slideshows: who qualifies, priority, drawing, ffmpeg, the social queue, pages and YouTube. */
class SlideshowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-queue-token-0123456789abcdef';

    private League $pl;
    private League $ligue1;
    private League $afcon;
    private FootballMatch $epl;
    private Player $kudus;
    private array $ffmpegCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Saturday morning in London; dateTime columns are read back in the app's timezone.
        $this->travelTo(Carbon::parse('2026-11-14 07:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        config([
            'app.url' => 'https://matchday.africa',
            'slideshow.enabled' => true,
            'slideshow.commons.enabled' => false,
            'slideshow.video_mode' => 'filters',
            'slideshow.music.tracks.hot-amapiano-beats.path' => 'testing/slideshow-music.mp3',
            'social.queue_token' => self::TOKEN,
            'social.autopost_enabled' => false,
            'griot_challenge.enabled' => false,
        ]);
        File::ensureDirectoryExists(storage_path('app/testing'));
        file_put_contents(storage_path('app/testing/slideshow-music.mp3'), 'not really audio');

        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $this->ligue1 = League::create(['football_data_id' => 2015, 'short_code' => 'FL1', 'name' => 'Ligue 1']);
        $this->afcon = League::create(['football_data_id' => 9001, 'espn_slug' => 'caf.nations', 'name' => 'Africa Cup of Nations']);

        $this->epl = $this->fixture($this->pl, 'West Ham United FC', 'Chelsea FC', '2026-11-14 15:00:00');
        $this->kudus = Player::create(['team_id' => $this->epl->home_team_id, 'name' => 'Mohammed Kudus', 'nationality' => 'Ghana',
            'nationality_code' => 'GHA', 'position' => 'Midfielder', 'shirt_number' => 14, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        File::deleteDirectory(storage_path('app/slideshows'));
        parent::tearDown();
    }

    private function fixture(League $league, string $home, string $away, string $kickoff, string $status = 'SCHEDULED', ?array $score = null): FootballMatch
    {
        return FootballMatch::create([
            'league_id' => $league->id, 'home_team_id' => Team::firstOrCreate(['name' => $home])->id,
            'away_team_id' => Team::firstOrCreate(['name' => $away])->id, 'match_date' => $kickoff, 'status' => $status,
            'home_score' => $score[0] ?? null, 'away_score' => $score[1] ?? null,
        ]);
    }

    /** Fakes ffmpeg: pass one prints loudnorm's JSON, pass two writes a video-sized file. Records every call. */
    private function fakeFfmpeg(): void
    {
        $this->app->bind(SlideshowVideo::class, fn () => (new SlideshowVideo)->usingProcess(function (array $args) {
            if (array_intersect(['-version', '-filters', '-encoders', '-decoders', '-demuxers'], $args)) {
                return new Process([PHP_BINARY, '-r', '']);
            }
            $this->ffmpegCalls[] = $args;
            if (in_array('null', $args, true)) {
                $json = '{"input_i" : "-19.52", "input_tp" : "-3.10", "input_lra" : "5.40", "input_thresh" : "-29.80", "output_i" : "-14.0", "target_offset" : "0.12"}';

                return new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "[Parsed_loudnorm_0]\n".'.var_export($json, true).');']);
            }

            return new Process([PHP_BINARY, '-r', 'file_put_contents('.var_export(end($args), true).', str_repeat("x", 20000));']);
        }));
    }

    /** The first block of $type on a slide ('pill', 'teams', 'portrait', 'title', 'panel', 'image'). */
    private static function block(array $slide, string $type): ?array
    {
        foreach ($slide['blocks'] as $block) {
            if ($block[0] === $type) {
                return $block;
            }
        }

        return null;
    }

    /** Every text in a slide's panels, in order. */
    private static function texts(array $slide): array
    {
        $out = [];
        foreach ($slide['blocks'] as $block) {
            foreach ($block[0] === 'panel' ? $block['items'] : [] as $item) {
                if (in_array($item[0], ['text', 'badge'], true)) {
                    $out[] = $item[1];
                }
            }
        }

        return $out;
    }

    private function heritage(Player $player, string $status, string $code = 'NGA', string $country = 'Nigeria'): PlayerHeritage
    {
        return PlayerHeritage::create(['player_id' => $player->id, 'country' => $country, 'country_code' => $code,
            'source_url' => 'https://example.org/source', 'status' => $status, 'confirmed_at' => $status === 'confirmed' ? now() : null]);
    }

    /** A rendered, approved slideshow for the EPL match. */
    private function readySlideshow(bool $approve = true): Slideshow
    {
        $this->fakeFfmpeg();
        app(SlideshowPicker::class)->generate('preview');
        $show = Slideshow::where('key', "preview:{$this->epl->id}")->firstOrFail();
        $this->assertTrue(app(Slideshows::class)->render($show), (string) $show->fresh()->error);
        $show->refresh();
        if ($approve) {
            app(Slideshows::class)->approve($show);
        }

        return $show->fresh();
    }

    // ---------------------------------------------------------------- scope and priority

    public function test_an_epl_match_is_picked_ahead_of_same_day_ligue_1_and_afcon_matches(): void
    {
        $ligue1 = $this->fixture($this->ligue1, 'Olympique de Marseille', 'Lille OSC', '2026-11-14 14:00:00');
        Player::create(['team_id' => $ligue1->home_team_id, 'name' => 'Amine Gouiri', 'nationality_code' => 'ALG', 'is_active' => true]);
        Player::create(['team_id' => $ligue1->away_team_id, 'name' => 'Ngal\'ayel Mukau', 'nationality_code' => 'COD', 'is_active' => true]);
        $afcon = $this->fixture($this->afcon, 'Nigeria', 'Ghana', '2026-11-14 13:00:00');

        $rows = app(SlideshowPicker::class)->previews();

        $this->assertSame([$this->epl->id, $ligue1->id, $afcon->id], $rows->pluck('match.id')->all());
        $this->assertSame(['preview', 'preview', 'national'], $rows->pluck('type')->all());
        $this->assertSame(4, SlideshowPicker::band($this->pl));
        $this->assertSame(3, SlideshowPicker::band($this->ligue1));
        $this->assertSame(2, SlideshowPicker::band($this->afcon));

        // Every qualifying match gets a record; the EPL one renders first.
        $this->assertSame(3, app(SlideshowPicker::class)->generate('preview'));
        $this->assertSame("preview:{$this->epl->id}", app(Slideshows::class)->waiting()->first()->key);
    }

    public function test_a_confirmed_heritage_england_player_qualifies_and_is_not_called_an_international(): void
    {
        $match = $this->fixture($this->pl, 'Arsenal FC', 'Everton FC', '2026-11-14 17:30:00');
        $saka = Player::create(['team_id' => $match->home_team_id, 'name' => 'Bukayo Saka', 'nationality' => 'England', 'nationality_code' => 'ENG', 'is_active' => true]);
        $this->heritage($saka, 'confirmed');

        $this->assertTrue(AfricanScope::inScope($saka));
        $this->assertSame('of Nigerian descent', AfricanScope::label($saka));
        $this->assertContains($match->id, app(SlideshowPicker::class)->previews()->pluck('match.id')->all());

        $show = Slideshow::create(['key' => "preview:{$match->id}", 'type' => 'preview', 'match_id' => $match->id]);
        $deck = app(SlideshowDeck::class)->build($show->fresh());
        $this->assertSame('Bukayo Saka', self::block($deck['slides'][0], 'portrait')['name']);
        $this->assertSame('of Nigerian descent · Arsenal', self::block($deck['slides'][0], 'portrait')['line']);
        $copy = json_encode($deck['slides']).$deck['caption'].implode(' ', $deck['captions']);
        $this->assertStringContainsString('Bukayo Saka (of Nigerian descent)', $deck['caption']);
        $this->assertStringNotContainsStringIgnoringCase('international', $copy);
        $this->assertStringNotContainsString('(Nigeria)', $copy);
    }

    public function test_pending_or_rejected_heritage_does_not_qualify_or_reach_copy(): void
    {
        $match = $this->fixture($this->pl, 'Arsenal FC', 'Everton FC', '2026-11-14 17:30:00');
        $saka = Player::create(['team_id' => $match->home_team_id, 'name' => 'Bukayo Saka', 'nationality_code' => 'ENG', 'is_active' => true]);
        $this->heritage($saka, 'pending');

        $this->assertFalse(AfricanScope::inScope($saka->fresh()));
        $this->assertNull(AfricanScope::label($saka->fresh()));
        $this->assertNotContains($match->id, app(SlideshowPicker::class)->previews()->pluck('match.id')->all());

        PlayerHeritage::query()->update(['status' => 'rejected']);
        $this->assertNotContains($match->id, app(SlideshowPicker::class)->previews()->pluck('match.id')->all());
        // Confirmed without a source is not confirmed.
        PlayerHeritage::query()->update(['status' => 'confirmed', 'source_url' => '']);
        $this->assertFalse(AfricanScope::inScope($saka->fresh()));
    }

    public function test_a_national_team_match_uses_flags_and_national_colours(): void
    {
        $match = $this->fixture($this->afcon, 'Nigeria', 'Ivory Coast', '2026-11-14 16:00:00');
        $show = Slideshow::create(['key' => "national:{$match->id}", 'type' => 'national', 'match_id' => $match->id]);

        $deck = app(SlideshowDeck::class)->build($show);

        $teams = self::block($deck['slides'][0], 'teams');
        $this->assertStringEndsWith('flags/ng.png', $teams['home']['flag']);
        $this->assertStringEndsWith('flags/ci.png', $teams['away']['flag']);
        $this->assertSame(['home' => ['#008751', '#FFFFFF'], 'away' => ['#F77F00', '#009E60']], $deck['slides'][0]['colours']);
        $this->assertNull(self::block($deck['slides'][0], 'portrait')); // no line-up data: no player is claimed
        $this->assertStringContainsString('#AFCON', $deck['captions']['x']);
    }

    public function test_the_feature_flag_off_generates_and_renders_nothing(): void
    {
        config(['slideshow.enabled' => false]);

        $this->artisan('slideshow:generate', ['what' => 'all'])->assertSuccessful();
        $this->artisan('slideshow:render')->assertSuccessful();

        $this->assertSame(0, Slideshow::count());
    }

    // ---------------------------------------------------------------- drawing and video

    public function test_slides_keep_all_text_inside_the_safe_zone(): void
    {
        $renderer = new SlideshowRenderer;
        $ctx = ['home' => ['#7A263A', '#1BB1E7'], 'away' => ['#034694', '#DBA111']];
        $side = fn ($name) => ['name' => $name, 'flag' => null, 'colours' => ['#7A263A', '#1BB1E7']];
        $long = 'Pierre-Emerick Aubameyang';
        $slides = [
            ['colours' => $ctx, 'spacing' => 20, 'blocks' => [['pill', 'Saturday 14 November'], ['teams', 'home' => $side('Brighton & Hove Albion'), 'away' => $side('Wolverhampton Wanderers'), 'middle' => 'v'],
                ['portrait', 'file' => null, 'name' => 'Mohammed Kudus Of A Very Long Name Indeed', 'line' => 'of Equatorial Guinean descent · Wolverhampton Wanderers', 'credit' => 'Photo: Someone With A Long Name / Wikimedia Commons / CC BY-SA 4.0', 'colours' => ['#7A263A', '#1BB1E7']],
                ['panel', 'items' => [['badge', 'Kick-off'], ['text', '5:30pm in the UK (GMT)', 'face' => 'bold', 'size' => 48], ['text', '6:30pm Nigerian time (WAT)', 'face' => 'bold', 'size' => 48], ['text', 'Falmer Stadium, Brighton and Hove', 'face' => 'bold', 'size' => 42]], 'gap' => 14]]],
            ['colours' => $ctx, 'blocks' => [['pill', 'African players in this match'], ['title', 'Ones to watch this weekend in the league'],
                ['panel', 'items' => array_merge(...array_fill(0, 4, [['text', $long, 'face' => 'bold', 'size' => 50], ['text', 'of Equatorial Guinean descent · Marseille', 'face' => 'reg', 'size' => 36], ['text', '2 goals · 1 assist · 90 min · Rating 8.1', 'face' => 'med', 'size' => 40]])), 'gap' => 10]]],
            ['colours' => $ctx, 'blocks' => [['pill', 'Premier League'], ['title', 'Premier League table'], ['panel', 'items' => [['table', 'rows' => [
                ['pos' => '1', 'name' => 'Liverpool', 'value' => '28 pts', 'colour' => null], ['pos' => '14', 'name' => 'Brighton & Hove Albion', 'value' => '13 pts', 'colour' => '#0057B8'], ['pos' => '20', 'name' => 'Wolverhampton Wanderers', 'value' => '2 pts', 'colour' => '#FDB913']]]]]]],
            ['colours' => $ctx, 'blocks' => [['pill', 'Brighton v Wolves · Saturday'], ['panel', 'items' => [['text', 'Who wins: Brighton or Wolves? A question long enough to wrap onto several lines', 'face' => 'display', 'size' => 76], ['text', 'Call the score at matchday.africa before kick-off.', 'face' => 'med', 'size' => 46]]]]],
        ];

        $out = $renderer->render($slides, storage_path('app/testing/slides'));

        $this->assertCount(4, $out);
        foreach ($out as $slide) {
            $this->assertSame([1080, 1920], array_slice(getimagesize($slide['file']), 0, 2));
            [$l, $t, $r, $b] = $slide['bbox'];
            $this->assertGreaterThanOrEqual(140, $l);
            $this->assertLessThanOrEqual(941, $r);
            $this->assertGreaterThanOrEqual(150, $t);
            $this->assertLessThanOrEqual(1540, $b);
            $this->assertLessThanOrEqual(20, abs($l - (1080 - $r)));
            $this->assertLessThanOrEqual(25, abs(($t + $b) / 2 - 845));
        }
    }

    public function test_text_outside_the_safe_zone_fails_the_render(): void
    {
        $this->expectException(UnsafeLayout::class);

        // Content running into the right-hand 140 px (TikTok's buttons).
        (new SlideshowRenderer)->check(1, [[150, 600, 960, 1100]]);
    }

    public function test_off_centre_content_fails_the_render(): void
    {
        $this->expectException(UnsafeLayout::class);

        (new SlideshowRenderer)->check(1, [[140, 150, 940, 900]]);
    }

    public function test_ffmpeg_gets_two_loudnorm_passes_crossfades_and_a_capped_zoom(): void
    {
        $this->fakeFfmpeg();
        $video = app(SlideshowVideo::class);
        $slides = array_map(fn ($i) => ['file' => "/tmp/0{$i}.png", 'bbox' => [140, 300, 940, 1390]], range(1, 5));

        $made = $video->make($slides, storage_path('app/testing/slideshow-music.mp3'), 0, storage_path('app/testing/out.mp4'));

        $this->assertSame(16.4, $made['seconds']);
        $this->assertSame(array_fill(0, 5, 1.04), $made['zoom']);
        $this->assertSame('-19.52', $made['loudness']['input_i']);
        $this->assertCount(2, $this->ffmpegCalls);
        [$measure, $render] = array_map(fn ($a) => implode(' ', $a), $this->ffmpegCalls);
        $this->assertStringContainsString('loudnorm=I=-14:TP=-2:LRA=11:print_format=json', $measure);
        $this->assertStringContainsString("volume='if(lt(t,0.3),t/0.3,if(gt(t,14.9),max(0,(16.4-t)/1.5),1))':eval=frame", $measure);
        $this->assertStringContainsString('loudnorm=I=-14:TP=-2:LRA=11:measured_I=-19.52:measured_TP=-3.10:measured_LRA=5.40:measured_thresh=-29.80:offset=0.12:linear=true', $render);
        $this->assertStringContainsString('xfade=transition=fade:duration=0.4:offset=3.2', $render);
        $this->assertStringContainsString('offset=12.8', $render);
        $this->assertSame(5, substr_count($render, 'zoompan='));
        // Anchored on the safe column's right edge (x 940 at 2x), the content kept between y 150 and 1540.
        $this->assertStringContainsString(":x='1880*(1-1/zoom)'", $render);
        $this->assertStringContainsString(":y='min(2*300-2*150/zoom,max(2*1390-2*1540/zoom,(ih-ih/zoom)/2))'", $render);
        foreach (['-c:v libx264', '-pix_fmt yuv420p', '-c:a aac', '-movflags +faststart', '-framerate 30', '-t 16.4'] as $arg) {
            $this->assertStringContainsString($arg, $render);
        }
        $this->assertFileExists(storage_path('app/testing/out.mp4'));
    }

    public function test_the_frames_mode_needs_no_video_filters_and_draws_the_zoom_and_fades_itself(): void
    {
        config(['slideshow.video_mode' => 'frames']);
        $this->fakeFfmpeg();
        $renderer = new SlideshowRenderer;
        $slides = $renderer->render(array_map(fn ($i) => ['colours' => ['home' => ['#FFD23F', '#0B0B0B'], 'away' => ['#0B0B0B', '#FFD23F']],
            'blocks' => [['pill', "Slide {$i}"], ['panel', 'items' => [['text', "Slide {$i}?", 'face' => 'display', 'size' => 76]]]]], range(1, 5)), storage_path('app/testing/slides'));
        $video = app(SlideshowVideo::class);

        $zooms = array_fill(0, 5, 1.04);
        $this->assertSame(492, iterator_count($video->frames($slides, $zooms)));
        $first = imagecreatefromstring($video->frames($slides, $zooms)->current());
        $this->assertSame([1080, 1920], [imagesx($first), imagesy($first)]);

        // Text at the bottom of the safe zone: no zoom.
        $flat = array_map(fn ($s) => ['file' => $s['file'], 'bbox' => [140, 150, 940, 1540]], $slides);
        $made = $video->make($flat, storage_path('app/testing/slideshow-music.mp3'), 0, storage_path('app/testing/out.mp4'));
        $this->assertSame('frames', $made['mode']);
        $this->assertSame(array_fill(0, 5, 1.0), $made['zoom']);
        $render = implode(' ', end($this->ffmpegCalls));
        $this->assertStringContainsString('-f image2pipe -framerate 30 -c:v mjpeg -i -', $render);
        $this->assertStringNotContainsString('xfade', $render);
        $this->assertStringNotContainsString('zoompan', $render);
        $this->assertStringContainsString('linear=true', $render);
        $this->assertStringContainsString('-movflags +faststart', $render);
    }

    public function test_the_zoom_is_capped_by_the_content_height_and_grows_to_the_left(): void
    {
        $this->assertSame(1.04, SlideshowVideo::zoomFor([140, 400, 940, 1300]));
        // Content 1,360 px tall: (1540 - 150 - 2) / 1360 = 1.0205.
        $this->assertSame(1.0205, SlideshowVideo::zoomFor([140, 160, 940, 1520]));
        $this->assertSame(1.0, SlideshowVideo::zoomFor([140, 150, 940, 1540]));

        [$x, $y, $w] = SlideshowVideo::cropFor(1.04, [140, 400, 940, 1300]);
        // The safe column's right edge stays at 940 on screen: (940 - x) * zoom = 940.
        $this->assertEqualsWithDelta(940, (940 - $x) * 1.04, 0.01);
        $this->assertEqualsWithDelta(1080 / 1.04, $w, 0.01);
        // Bottom of the content after zoom stays above 1540.
        $this->assertLessThanOrEqual(1540.01, (1300 - $y) * 1.04);
    }

    // ---------------------------------------------------------------- render, queue, pages

    public function test_a_preview_renders_from_db_facts_and_waits_for_approval(): void
    {
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->fixture($this->pl, 'West Ham United FC', 'Fulham FC', '2026-11-07 15:00:00', 'FINISHED', [1, 0])->id,
            'team_id' => $this->epl->home_team_id, 'type' => 'goal', 'minute' => 30, 'player_name' => 'Mohammed Kudus']);

        $show = $this->readySlideshow(approve: false);

        $this->assertSame('ready', $show->status);
        $this->assertFalse($show->approved);
        $this->assertSame(13.2, $show->duration_seconds); // opener, players, form, question: no table in the data
        $this->assertFileExists(public_path("generated/slideshows/{$show->id}/slideshow.mp4"));
        $this->assertFileExists(public_path("generated/slideshows/{$show->id}/poster.jpg"));
        $opener = $show->slides[0];
        $this->assertSame('Saturday 14 November', self::block($opener, 'pill')[1]);
        $this->assertSame(['WEST HAM', 'Chelsea', 'v'], [mb_strtoupper(self::block($opener, 'teams')['home']['name']), self::block($opener, 'teams')['away']['name'], self::block($opener, 'teams')['middle']]);
        $this->assertSame('Mohammed Kudus', self::block($opener, 'portrait')['name']);
        $this->assertSame('Ghana · West Ham', self::block($opener, 'portrait')['line']);
        $this->assertSame(['Kick-off', '3:00pm in the UK (GMT)', '4:00pm Nigerian time (WAT)'], self::texts($opener));
        $this->assertSame(['home' => ['#7A263A', '#1BB1E7'], 'away' => ['#034694', '#DBA111']], $opener['colours']);
        $this->assertSame('African players in this match', self::block($show->slides[1], 'pill')[1]);
        $this->assertContains('1 goal, 0 assists in the league this season', self::texts($show->slides[1]));
        $this->assertSame('Form', self::block($show->slides[2], 'pill')[1]);
        $this->assertSame('Who wins: West Ham or Chelsea?', self::texts($show->slides[3])[0]);
        $this->assertSame('Hot Amapiano Beats', $show->music['title']);
        $this->assertSame('CC BY 4.0', $show->music['licence']);
        $this->assertSame("/matches/{$this->epl->id}", $show->landing_path);
        // Unapproved: not in the API, not on public pages, not on YouTube.
        $this->assertNull(app(SocialQueue::class)->find("slideshow:{$show->id}"));
        $this->get("/slideshows/{$show->id}")->assertNotFound();
    }

    public function test_the_social_queue_lists_only_approved_slideshows_with_https_links_utm_and_credits(): void
    {
        $show = $this->readySlideshow();

        $response = $this->getJson('/api/social-queue', ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();
        $item = collect($response->json('items') ?? $response->json('data') ?? $response->json())->firstWhere('id', "slideshow:{$show->id}");

        $this->assertNotNull($item);
        $this->assertSame("https://matchday.africa/generated/slideshows/{$show->id}/slideshow.mp4", $item['video_url']);
        $this->assertSame($item['video_url'], $item['reel_url']);
        $this->assertEquals($show->duration_seconds, $item['reel_duration_seconds']);
        $this->assertEquals(10.0, $item['reel_duration_seconds']); // No table or form in the data: those slides are dropped.
        $this->assertSame('Music: "Hot Amapiano Beats" by Alex Morgan (CC BY 4.0)', $item['music_credit']);
        $this->assertSame("https://matchday.africa/matches/{$this->epl->id}?utm_source=tiktok&utm_medium=social&utm_campaign=slideshow&utm_content=preview-{$show->id}", $item['landing_urls']['tiktok']);
        $this->assertStringContainsString('Source: https://freemusicarchive.org/music/alex-morgan/pop-afro-party-instrumentals/hot-amapiano-beats/', $item['music_credit_full']);
        foreach (['instagram', 'tiktok', 'youtube'] as $channel) {
            $this->assertStringContainsString($item['music_credit_full'], $item['captions'][$channel]);
        }
        $this->assertStringContainsString($item['music_credit'], $item['captions']['x']);
        foreach (['x', 'instagram', 'tiktok', 'youtube'] as $channel) {
            $this->assertStringContainsString("utm_source={$channel}&utm_medium=social&utm_campaign=slideshow&utm_content=preview-{$show->id}", $item['captions'][$channel]);
            $this->assertStringContainsString('Hot Amapiano Beats', $item['captions'][$channel]);
            $this->assertStringContainsString('#MatchdayAfrica', $item['captions'][$channel]);
            $this->assertDoesNotMatchRegularExpression('/quietly|most people|👇|🚨|→/u', $item['captions'][$channel]);
        }
        $this->assertLessThanOrEqual(XText::LIMIT, XText::length($item['captions']['x']));

        // The bot can mark it posted like any other item.
        $this->postJson("/api/social-queue/slideshow:{$show->id}/posted", ['channel' => 'tiktok'], ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();
        $this->assertTrue(app(SocialQueue::class)->find("slideshow:{$show->id}")['channels']['tiktok']['posted']);

        app(Slideshows::class)->unapprove($show);
        $this->assertNull(app(SocialQueue::class)->find("slideshow:{$show->id}"));
    }

    public function test_an_approved_slideshow_appears_on_its_page_the_reels_feed_the_match_page_and_the_player_page(): void
    {
        $show = $this->readySlideshow();

        $this->get("/slideshows/{$show->id}")->assertOk()
            ->assertSee("generated/slideshows/{$show->id}/slideshow.mp4", false)
            ->assertSee('"@type":"VideoObject"', false)
            ->assertSee('Hot Amapiano Beats')
            ->assertSee('Call the score')
            ->assertSee('Create my free account');
        $this->get('/reels')->assertOk()->assertSee("/slideshows/{$show->id}", false)->assertSee('Slideshow · Match preview');
        $this->get("/matches/{$this->epl->id}")->assertOk()->assertSee("/slideshows/{$show->id}", false);
        $this->get("/african-players/{$this->kudus->id}")->assertOk()->assertSee("/slideshows/{$show->id}", false);
    }

    public function test_admins_approve_and_regenerate_and_others_cannot(): void
    {
        $show = $this->readySlideshow(approve: false);

        $this->post("/admin/slideshows/{$show->id}/approve")->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))->post("/admin/slideshows/{$show->id}/approve")->assertForbidden();
        $this->assertFalse($show->fresh()->approved);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/slideshows')->assertOk()->assertSee('Approve')->assertSee('Hot Amapiano Beats');
        $this->actingAs($admin)->post("/admin/slideshows/{$show->id}/approve")->assertRedirect();
        $this->assertTrue($show->fresh()->approved);
        $this->assertSame($admin->id, $show->fresh()->approved_by);

        $this->actingAs($admin)->post("/admin/slideshows/{$show->id}/regenerate")->assertRedirect();
        $this->assertSame('pending', $show->fresh()->status);
        $this->assertFalse($show->fresh()->approved);
        $this->assertNull(app(SocialQueue::class)->find("slideshow:{$show->id}"));
    }

    // ---------------------------------------------------------------- YouTube

    public function test_an_approved_slideshow_uploads_as_its_own_short_without_touching_the_match_reel_short(): void
    {
        Queue::fake();
        config(['youtube.client_id' => 'client-123', 'youtube.client_secret' => 'secret-456', 'youtube.channel_id' => 'UCJ9Asmir1hBOHHQs9qSCkHg',
            'youtube.uploads.enabled' => true, 'youtube.uploads.daily_uploads' => 6, 'youtube.uploads.chunk_bytes' => 256 * 1024]);
        YoutubeAccount::create(['channel_id' => 'UCJ9Asmir1hBOHHQs9qSCkHg', 'channel_title' => 'Matchday Africa', 'refresh_token' => 'refresh-1',
            'access_token' => 'access-1', 'expires_at' => now()->addHour(), 'scopes' => 'https://www.googleapis.com/auth/youtube.upload']);
        $this->epl->forceFill(['youtube_video_id' => 'REELshort01', 'youtube_status' => 'uploaded'])->save();
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            if (str_contains($request->url(), 'uploadType=resumable')) {
                $sent[] = $request->data();

                return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=slide']);
            }

            return Http::response(['id' => 'SLIDEshort1', 'status' => ['privacyStatus' => 'public']], 200);
        });

        $show = $this->readySlideshow();
        $this->assertSame('pending', $show->youtube_status);
        Queue::assertPushed(\App\Jobs\UploadSlideshowToYouTube::class);

        $this->assertSame('uploaded', app(SlideshowUploads::class)->upload($show));

        $this->assertSame('SLIDEshort1', $show->fresh()->youtube_video_id);
        $this->assertSame('REELshort01', $this->epl->fresh()->youtube_video_id);
        $description = $sent[0]['snippet']['description'];
        $this->assertStringContainsString("https://matchday.africa/matches/{$this->epl->id}?utm_source=youtube&utm_medium=social&utm_campaign=slideshow&utm_content=preview-{$show->id}", $description);
        $this->assertStringContainsString('Music: "Hot Amapiano Beats" by Alex Morgan. Licensed under CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/). Source: https://freemusicarchive.org/music/alex-morgan/pop-afro-party-instrumentals/hot-amapiano-beats/. Trimmed, faded and loudness-normalised for this video.', $description);
        $this->assertStringEndsWith('#Shorts', $sent[0]['snippet']['title']);
        $this->assertLessThanOrEqual(100, mb_strlen($sent[0]['snippet']['title']));
        $this->assertSame('https://www.youtube.com/shorts/SLIDEshort1', app(SocialQueue::class)->find("slideshow:{$show->id}")['youtube_url']);
    }

    public function test_an_unapproved_slideshow_is_never_sent_to_youtube(): void
    {
        config(['youtube.uploads.enabled' => true]);
        $show = $this->readySlideshow(approve: false);

        $this->assertFalse(app(SlideshowUploads::class)->queue($show));
        $this->assertNull($show->fresh()->youtube_status);
        $this->assertSame('skipped', app(SlideshowUploads::class)->upload($show));
    }

    // ---------------------------------------------------------------- photos and other decks

    public function test_commons_photos_need_a_free_licence_and_carry_their_credit(): void
    {
        $this->assertTrue(CommonsPhotos::freeLicence('CC BY-SA 4.0'));
        $this->assertTrue(CommonsPhotos::freeLicence('CC BY 2.0'));
        $this->assertTrue(CommonsPhotos::freeLicence('CC0'));
        $this->assertTrue(CommonsPhotos::freeLicence('Public domain'));
        $this->assertFalse(CommonsPhotos::freeLicence('CC BY-NC 2.0'));
        $this->assertFalse(CommonsPhotos::freeLicence('CC BY-ND 4.0'));
        $this->assertFalse(CommonsPhotos::freeLicence('Fair use'));

        config(['slideshow.commons.enabled' => true]);
        $jpeg = (function () {
            $img = imagecreatetruecolor(1080, 1440);
            ob_start();
            imagejpeg($img);

            return ob_get_clean();
        })();
        Http::fake([
            'www.wikidata.org/*action=wbsearchentities*' => Http::response(['search' => [['id' => 'Q1', 'label' => 'Mohammed Kudus'], ['id' => 'Q2', 'label' => 'Kudus Town']]]),
            'www.wikidata.org/*action=wbgetentities*' => Http::response(['entities' => [
                'Q1' => ['id' => 'Q1', 'claims' => ['P106' => [['mainsnak' => ['datavalue' => ['value' => ['id' => 'Q937857']]]]], 'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Kudus 2024.jpg']]]]]],
            ]]),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => ['5' => ['title' => 'File:Kudus 2024.jpg', 'imageinfo' => [[
                'mime' => 'image/jpeg', 'width' => 3000, 'thumburl' => 'https://upload.wikimedia.org/thumb/kudus.jpg', 'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Kudus_2024.jpg',
                'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-SA 4.0'], 'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0'], 'Artist' => ['value' => '<a href="//x">Jane Photographer</a>']],
            ]]]]]]),
            'upload.wikimedia.org/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $photo = app(CommonsPhotos::class)->forPlayer($this->kudus);

        $this->assertSame('Jane Photographer / Wikimedia Commons / CC BY-SA 4.0', $photo['credit']);
        $this->assertSame('Q1', $photo['wikidata']);
        $this->assertSame(['x' => 216, 'y' => 86, 'w' => 648, 'h' => 648], $photo['crop']);
        $this->assertFileExists($photo['file']);
        $this->assertSame('ok', data_get($this->kudus->fresh()->metadata, 'commons_photo.status'));

        $show = $this->readySlideshow(approve: false);
        $this->assertSame('Photo: Jane Photographer / Wikimedia Commons / CC BY-SA 4.0', self::block($show->slides[0], 'portrait')['credit']);
        $this->assertSame("{$this->kudus->id}.jpg", self::block($show->slides[0], 'portrait')['file']); // stored without the server path
        $this->assertSame('CC BY-SA 4.0', $show->photo['licence']);
        $this->assertStringContainsString('Photo: Jane Photographer', $show->captions['instagram']);
        File::delete($photo['file']);
    }

    public function test_post_match_decks_feature_what_the_in_scope_player_did_and_rank_above_previews(): void
    {
        $this->travelTo(Carbon::parse('2026-11-14 19:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        $this->epl->update(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1]);
        MatchEvent::create(['football_data_id' => 11, 'match_id' => $this->epl->id, 'team_id' => $this->epl->home_team_id, 'type' => 'goal', 'minute' => 41, 'player_name' => 'Mohammed Kudus']);
        MatchEvent::create(['football_data_id' => 12, 'match_id' => $this->epl->id, 'team_id' => $this->epl->home_team_id, 'type' => 'goal', 'minute' => 70, 'player_name' => 'Jarrod Bowen', 'assist_player_name' => 'M. Kudus']);
        MatchEvent::create(['football_data_id' => 13, 'match_id' => $this->epl->id, 'team_id' => $this->epl->away_team_id, 'type' => 'goal', 'minute' => 88, 'player_name' => 'Cole Palmer']);

        $this->assertSame(1, app(SlideshowPicker::class)->generate('post_match'));
        $show = Slideshow::where('key', "post_match:{$this->epl->id}")->firstOrFail();
        $deck = app(SlideshowDeck::class)->build($show);

        $opener = $deck['slides'][0];
        $this->assertSame('Full time · Saturday 14 November', self::block($opener, 'pill')[1]);
        $this->assertSame('2-1', self::block($opener, 'teams')['middle']);
        $this->assertSame(['Full time', "41' Mohammed Kudus, West Ham", "70' Jarrod Bowen, West Ham", "88' Cole Palmer, Chelsea"], self::texts($opener));
        $this->assertContains('1 goal · 1 assist', self::texts($deck['slides'][1]));
        $this->assertSame('How Kudus did', self::block($deck['slides'][1], 'title')[1]);
        $this->assertGreaterThan(app(SlideshowPicker::class)->priority('preview', $this->epl, 1), $show->priority);
    }

    public function test_team_of_the_week_lists_last_weeks_premier_league_contributors(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 06:00:00', 'UTC')->setTimezone('Africa/Lagos')); // Monday
        $played = $this->fixture($this->pl, 'West Ham United FC', 'Fulham FC', '2026-11-14 15:00:00', 'FINISHED', [3, 0]);
        MatchEvent::create(['football_data_id' => 21, 'match_id' => $played->id, 'team_id' => $played->home_team_id, 'type' => 'goal', 'minute' => 10, 'player_name' => 'Mohammed Kudus']);
        MatchEvent::create(['football_data_id' => 22, 'match_id' => $played->id, 'team_id' => $played->home_team_id, 'type' => 'goal', 'minute' => 50, 'player_name' => 'Mohammed Kudus']);

        app(SlideshowPicker::class)->generate('daily');
        $show = Slideshow::where('type', 'totw')->firstOrFail();
        $deck = app(SlideshowDeck::class)->build($show);

        $this->assertSame('totw:2026-11-09', $show->key);
        $this->assertSame('Mohammed Kudus', self::block($deck['slides'][0], 'portrait')['name']);
        $this->assertSame(['Premier League', '2 goals', 'West Ham 3-0 Fulham'], self::texts($deck['slides'][0]));
        $this->assertContains('2 goals v Fulham', self::texts($deck['slides'][1]));
        $this->assertStringContainsString('Team of the Week, Premier League 9 Nov–15 Nov: Mohammed Kudus (2 goals)', $deck['caption']);
    }

    public function test_a_milestone_deck_is_made_only_when_the_goal_reaches_the_mark(): void
    {
        $this->travelTo(Carbon::parse('2026-11-14 19:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        foreach (range(1, 4) as $i) {
            $m = $this->fixture($this->pl, 'West Ham United FC', "Team {$i} FC", sprintf('2026-10-%02d 15:00:00', $i * 5), 'FINISHED', [1, 0]);
            MatchEvent::create(['football_data_id' => 100 + $i, 'match_id' => $m->id, 'team_id' => $m->home_team_id, 'type' => 'goal', 'minute' => 20, 'player_name' => 'Mohammed Kudus']);
        }
        $this->epl->update(['status' => 'FINISHED', 'home_score' => 1, 'away_score' => 0]);
        MatchEvent::create(['football_data_id' => 200, 'match_id' => $this->epl->id, 'team_id' => $this->epl->home_team_id, 'type' => 'goal', 'minute' => 60, 'player_name' => 'Mohammed Kudus']);

        app(SlideshowPicker::class)->generate('post_match');

        $show = Slideshow::where('key', "milestone:{$this->kudus->id}:5")->firstOrFail();
        $deck = app(SlideshowDeck::class)->build($show);
        $this->assertContains('5 Premier League goals this season', self::texts($deck['slides'][0]));
        $this->assertStringContainsString('Mohammed Kudus (Ghana) has 5 Premier League goals this season', $deck['caption']);
    }

    public function test_admins_upload_the_music_and_pin_a_commons_photo(): void
    {
        // A test-only track key, so a real track in storage/app/music is never touched.
        config(['slideshow.music.track' => 'test-track', 'slideshow.music.tracks.test-track' => config('slideshow.music.tracks.hot-amapiano-beats')
            + ['path' => 'music/test-track.mp3'], 'slideshow.music.tracks.test-track.path' => 'music/test-track.mp3']);
        $this->fakeFfmpeg();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/slideshows')->assertOk()->assertSee('No file yet');
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post('/admin/slideshows/music', ['track' => \Illuminate\Http\UploadedFile::fake()->create('beat.mp3', 200, 'audio/mpeg')])->assertForbidden();

        $this->actingAs($admin)->post('/admin/slideshows/music', [
            'track' => \Illuminate\Http\UploadedFile::fake()->create('Hot Amapiano Beats.mp3', 200, 'audio/mpeg'),
            'source_url' => 'https://example.org/hot-amapiano-beats',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(storage_path('app/music/test-track.mp3'), Slideshows::musicFile());
        $this->assertFileExists(Slideshows::musicFile());
        $this->assertSame('https://example.org/hot-amapiano-beats', Slideshows::music()['source_url']);
        $this->assertFileDoesNotExist(public_path('music/test-track.mp3'));
        $this->actingAs($admin)->get('/admin/slideshows')->assertOk()->assertSee('File found')->assertSee('https://example.org/hot-amapiano-beats');

        // Pinning a photo from a Commons link: the licence is still checked.
        config(['slideshow.commons.enabled' => true]);
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => ['5' => ['title' => 'File:Kudus.jpg', 'imageinfo' => [[
            'mime' => 'image/jpeg', 'width' => 2000, 'thumburl' => 'https://upload.wikimedia.org/k.jpg',
            'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-NC 2.0'], 'Artist' => ['value' => 'Someone']],
        ]]]]]])]);
        $this->actingAs($admin)->post('/admin/slideshows/photo', ['player_id' => $this->kudus->id, 'file' => 'https://commons.wikimedia.org/wiki/File:Kudus.jpg'])
            ->assertSessionHas('error');
        $this->assertSame('none', data_get($this->kudus->fresh()->metadata, 'commons_photo.status'));
        $this->assertSame('File:Kudus.jpg', data_get($this->kudus->fresh()->metadata, 'commons_photo.pinned'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'titles=File%3AKudus.jpg'));
        File::delete([storage_path('app/music/test-track.mp3'), storage_path('app/music/test-track.json')]);
    }

    public function test_the_music_is_fetched_from_the_free_music_archive_and_checked(): void
    {
        config(['slideshow.music.track' => 'test-track', 'slideshow.music.tracks.test-track' => [
            'title' => 'Hot Amapiano Beats', 'artist' => 'Alex Morgan', 'licence' => 'CC BY 4.0', 'path' => 'music/test-track.mp3',
            'download_url' => 'https://files.freemusicarchive.org/storage-freemusicarchive-org/tracks/test.mp3', 'sha256' => hash('sha256', 'the real track'),
        ]]);
        File::delete([storage_path('app/music/test-track.mp3'), storage_path('app/music/test-track.json')]);
        Http::fake(['files.freemusicarchive.org/*' => Http::sequence()->push('tampered bytes')->push('the real track')]);

        [$ok, $message] = Slideshows::fetchMusic();
        $this->assertFalse($ok);
        $this->assertStringContainsString('does not match', $message);
        $this->assertFileDoesNotExist(storage_path('app/music/test-track.mp3'));

        $this->artisan('slideshow:music')->assertSuccessful();
        $this->assertSame('the real track', file_get_contents(storage_path('app/music/test-track.mp3')));
        // Already there and right: no second download.
        $this->artisan('slideshow:music')->expectsOutputToContain('Music in place')->assertSuccessful();
        Http::assertSentCount(2);
        File::delete(storage_path('app/music/test-track.mp3'));
    }
}
