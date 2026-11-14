<?php

namespace Tests\Feature;

use App\Models\RadioBulletin;
use App\Services\RadioVideo;
use App\Services\SocialQueue;
use App\Support\XText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RadioVideoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'radio-test-token-0123456789-abcdefghijklmnop';

    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-14 12:30:00');
        config(['app.url' => 'https://matchday.africa', 'radio.enabled' => true, 'radio.video.enabled' => true,
            'social.queue_token' => self::TOKEN, 'social.autopost_enabled' => false]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    public function test_a_published_edition_over_a_minute_renders_a_full_length_vertical_video(): void
    {
        $this->needsFfmpeg();
        $b = $this->edition(seconds: 62);

        $this->artisan('radio:video')->assertSuccessful();

        $b->refresh();
        $this->assertSame('ready', $b->video_status, (string) $b->video_error);
        $file = public_path("generated/radio/{$b->id}/radio.mp4");
        $this->assertFileExists($file);
        $this->assertFileExists(public_path("generated/radio/{$b->id}/poster.jpg"));
        $this->assertEqualsWithDelta(62.0, SocialQueue::mp4Seconds($file), 0.6);
        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name,width,height,pix_fmt,r_frame_rate', '-of', 'json', $file]);
        $probe->mustRun();
        $streams = collect(json_decode($probe->getOutput(), true)['streams']);
        $video = $streams->firstWhere('codec_name', 'h264');
        $this->assertSame([1080, 1920, 'yuv420p', '30/1'], [$video['width'], $video['height'], $video['pix_fmt'], $video['r_frame_rate']]);
        $this->assertNotNull($streams->firstWhere('codec_name', 'aac'));
        // The audio and the publish state are untouched.
        $this->assertSame('published', $b->status);
        $this->assertNotNull($b->audioFile());
    }

    public function test_editions_of_a_minute_or_less_are_not_rendered(): void
    {
        $b = $this->edition(seconds: 60, real: false);

        $this->artisan('radio:video')->assertSuccessful();

        $this->assertSame('too_short', $b->fresh()->video_status);
        $this->assertFileDoesNotExist(public_path("generated/radio/{$b->id}/radio.mp4"));
    }

    public function test_a_failed_render_is_recorded_and_retried_without_touching_the_audio(): void
    {
        $this->needsFfmpeg();
        $b = $this->edition(seconds: 90, real: false); // not a real mp3: ffmpeg cannot decode it

        $this->artisan('radio:video')->assertFailed();

        $b->refresh();
        $this->assertSame('failed', $b->video_status);
        $this->assertSame(1, $b->video_attempts);
        $this->assertNotEmpty($b->video_error);
        $this->assertSame('published', $b->status);
        $this->assertNotNull($b->audioFile());
        // It is picked again until max_attempts, then left for an editor.
        $this->assertSame($b->id, app(RadioVideo::class)->next()?->id);
        $b->update(['video_attempts' => 3]);
        $this->assertNull(app(RadioVideo::class)->next());
    }

    public function test_the_worker_stays_quiet_while_the_flag_is_off(): void
    {
        config(['radio.video.enabled' => false]);
        $b = $this->edition(seconds: 90, real: false);

        $this->artisan('radio:video')->expectsOutputToContain('off')->assertSuccessful();
        $this->assertNull($b->fresh()->video_status);
    }

    public function test_scenes_cover_the_audio_with_short_captions_in_order(): void
    {
        $b = $this->edition(seconds: 100, real: false);

        $scenes = app(RadioVideo::class)->scenes($b, 100.0);

        $this->assertSame(0.0, $scenes[0]['start']);
        $this->assertEqualsWithDelta(100.0, end($scenes)['end'], 0.001);
        foreach ($scenes as $i => $scene) {
            if ($i > 0) {
                $this->assertEqualsWithDelta($scenes[$i - 1]['end'], $scene['start'], 0.001);
            }
            $this->assertLessThanOrEqual(84, mb_strlen((string) $scene['caption']));
        }
        $this->assertSame('Arsenal 2–1 Chelsea', collect($scenes)->firstWhere('start', '>=', 8.0)['slide']['title']);
        $this->assertStringContainsString('war/army/', collect($scenes)->firstWhere('start', '>=', 8.0)['slide']['image']);
        $this->assertSame(implode(' ', collect($scenes)->pluck('caption')->filter()->all()),
            preg_replace('/\s+/', ' ', $b->script));
    }

    public function test_the_export_lists_published_editions_for_the_bot_only(): void
    {
        $published = $this->edition(seconds: 90, real: false, slot: '2026-11-14-12');
        $this->markVideoReady($published);
        $draft = $this->edition(seconds: 90, real: false, slot: '2026-11-14-18', status: 'draft');
        $held = $this->edition(seconds: 90, real: false, slot: '2026-11-14-06', status: 'held');

        $this->getJson('/api/radio-export')->assertUnauthorized();
        $this->getJson('/api/radio-export', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->get("/api/radio-export/{$published->id}/audio.mp3")->assertUnauthorized();
        $this->get("/api/radio-export/{$published->id}/video.mp4")->assertUnauthorized();

        $json = $this->getJson('/api/radio-export?date=2026-11-14', $this->auth())->assertOk()->json();
        $this->assertSame([$published->id], array_column($json['editions'], 'id'));
        $edition = $json['editions'][0];
        $this->assertSame("radio:{$published->id}", $edition['queue_id']);
        $this->assertSame("https://matchday.africa/generated/radio/{$published->id}/radio.mp4", $edition['mp4_url']);
        $this->assertStringStartsWith("https://matchday.africa/generated/radio/{$published->id}/", $edition['mp3_url']);
        $this->assertStringContainsString('utm_campaign=radio', $edition['landing_urls']['tiktok']);
        $this->assertSame(config('radio.disclosure'), $edition['disclosure']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($json));

        $this->get("/api/radio-export/{$published->id}/audio.mp3", $this->auth())->assertOk()
            ->assertHeader('content-type', 'audio/mpeg')->assertDownload("matchday-radio-{$published->slot}.mp3");
        $this->get("/api/radio-export/{$published->id}/video.mp4", $this->auth())->assertOk()
            ->assertHeader('content-type', 'video/mp4');
        foreach ([$draft, $held] as $hidden) {
            $this->get("/api/radio-export/{$hidden->id}/audio.mp3", $this->auth())->assertNotFound();
            $this->get("/api/radio-export/{$hidden->id}/video.mp4", $this->auth())->assertNotFound();
        }
        $this->getJson('/api/radio-export?date=14-11-2026', $this->auth())->assertStatus(422);
        $this->getJson('/api/radio-export?date=2026-11-13', $this->auth())->assertOk()->assertJsonPath('editions', []);
    }

    public function test_a_ready_video_joins_the_social_queue_and_leaves_when_withdrawn(): void
    {
        $b = $this->edition(seconds: 109, real: false);
        $this->getJson('/api/social-queue', $this->auth())->assertOk()->assertJsonMissing(['id' => "radio:{$b->id}"]);
        $this->markVideoReady($b);

        $items = collect($this->getJson('/api/social-queue', $this->auth())->assertOk()->json('items'));
        $item = $items->firstWhere('id', "radio:{$b->id}");
        $this->assertNotNull($item);
        $this->assertSame('radio', $item['type']);
        $this->assertSame("https://matchday.africa/generated/radio/{$b->id}/radio.mp4", $item['video_url']);
        $this->assertSame($item['video_url'], $item['reel_url']);
        $this->assertStringContainsString("/listen/{$b->id}?utm_source=x&utm_medium=social&utm_campaign=radio", $item['captions']['x']);
        $this->assertLessThanOrEqual(XText::LIMIT, XText::length($item['captions']['x']));
        $this->assertStringContainsString('Kokoro', $item['captions']['x']);
        $this->assertStringContainsString(config('radio.disclosure'), $item['captions']['tiktok']);
        $this->assertStringNotContainsString('Amapiano', json_encode($item));
        $this->assertFalse($item['channels']['tiktok']['posted']);

        $this->postJson("/api/social-queue/radio:{$b->id}/posted", ['channel' => 'tiktok', 'external_post_id' => '777'], $this->auth())
            ->assertOk()->assertJsonPath('channels.tiktok.posted', true);

        $b->update(['status' => 'held']);
        $this->assertNull(collect($this->getJson('/api/social-queue', $this->auth())->json('items'))->firstWhere('id', "radio:{$b->id}"));
    }

    public function test_the_admin_desks_show_the_video(): void
    {
        $b = $this->edition(seconds: 109, real: false);
        $this->markVideoReady($b);
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.radio.index'))->assertOk()->assertSee('ready (109 s)', false)->assertSee('radio.mp4', false);
        $this->actingAs($admin)->get(route('admin.social-queue.index'))->assertOk()->assertSee("radio:{$b->id}")->assertSee('Matchday Radio')->assertSee('<video', false);

        $b->update(['video_status' => 'failed', 'video_error' => 'ffmpeg lacks: decoder mp3', 'video_attempts' => 3]);
        $this->actingAs($admin)->get(route('admin.radio.index'))->assertSee('ffmpeg lacks: decoder mp3')->assertSee('Retry video');
        $this->actingAs($admin)->post(route('admin.radio.action', $b), ['action' => 'video'])->assertRedirect();
        $this->assertSame([null, 0], [$b->fresh()->video_status, $b->fresh()->video_attempts]);
    }

    public function test_the_podcast_feed_keeps_its_items_and_gains_a_cover(): void
    {
        $b = $this->edition(seconds: 109, real: false);
        $this->markVideoReady($b);

        $xml = $this->get('/listen/feed.xml')->assertOk()->getContent();
        $feed = simplexml_load_string($xml);
        $this->assertNotFalse($feed);
        $this->assertSame(route('radio.show', $b), (string) $feed->channel->item[0]->guid);
        $this->assertSame($b->audioUrl(), (string) $feed->channel->item[0]->enclosure['url']);
        $this->assertSame('audio/mpeg', (string) $feed->channel->item[0]->enclosure['type']);
        $this->assertStringContainsString('<itunes:image href="'.asset('images/radio/cover.png').'" />', $xml);
        $this->assertStringContainsString('<itunes:owner>', $xml);
        $this->assertStringNotContainsString('radio.mp4', $xml);
        [$w, $h] = getimagesize(public_path('images/radio/cover.png'));
        $this->assertSame([1400, 1400], [$w, $h]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json'];
    }

    private function needsFfmpeg(): void
    {
        $missing = app(RadioVideo::class)->missing();
        if ($missing) {
            $this->markTestSkipped('ffmpeg for radio video is not available here: '.implode(', ', $missing));
        }
    }

    private function markVideoReady(RadioBulletin $b): void
    {
        File::ensureDirectoryExists(dirname($b->publicVideoPath()));
        File::put($b->publicVideoPath(), str_repeat('v', 4096));
        File::put($b->publicPosterPath(), 'jpg');
        $b->update(['video_status' => 'ready', 'video_seconds' => (float) $b->duration_seconds, 'video_bytes' => 4096, 'video_rendered_at' => now()]);
    }

    /** A published edition with two story segments, an intro and an outro; $real makes a decodable mp3 of that length. */
    private function edition(int $seconds, bool $real = true, string $slot = '2026-11-14-12', string $status = 'published'): RadioBulletin
    {
        $facts = [
            ['key' => 'match:1', 'kind' => 'result', 'headline' => 'Arsenal 2–1 Chelsea', 'text' => 'Arsenal beat Chelsea 2 to 1 at the Emirates.', 'source' => 'Matchday Africa', 'url' => 'https://example.test/1'],
            ['key' => 'news:2', 'kind' => 'news', 'headline' => 'Osimhen back in training', 'text' => 'Victor Osimhen trained with the squad on Friday.', 'source' => 'BBC Sport', 'url' => 'https://example.test/2'],
        ];
        $segments = [
            ['fact_keys' => ['match:1'], 'text' => 'Arsenal beat Chelsea two one at the Emirates this afternoon, with goals either side of half time and a late Chelsea reply that came too late.'],
            ['fact_keys' => ['news:2'], 'text' => 'BBC Sport reports that Victor Osimhen trained with the squad on Friday. He could return for the next match.'],
        ];
        $script = "Welcome to Matchday Radio, the midday edition.\n\n".$segments[0]['text']."\n\n".$segments[1]['text']."\n\nThat's Matchday Radio. Back at six.";
        $b = RadioBulletin::create(['slot' => $slot, 'title' => 'The midday update', 'status' => $status, 'stage' => 'audio',
            'recorded_at' => now(), 'published_at' => $status === 'published' ? now()->subMinutes(5) : null,
            'facts' => $facts, 'segments' => $segments, 'script' => $script,
            'audio_cues' => [['paragraph' => 1, 'start' => 8.0, 'end' => round($seconds * 0.55, 2)], ['paragraph' => 2, 'start' => round($seconds * 0.55, 2) + 0.4, 'end' => $seconds - 7.0]]]);
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
}
