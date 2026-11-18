<?php

namespace Tests\Feature;

use App\Models\AgentAuditLog;
use App\Models\StoryVideo;
use App\Services\Agent\AgentAccess;
use App\Services\Agent\AgentMedia;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $mp4;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://matchday.africa']);
        [$agent] = app(AgentAccess::class)->upsert('agent@matchday.africa', 'Matchday agent', 'correct-horse-battery');
        [, $this->token] = app(AgentAccess::class)->issueToken($agent);
        $this->mp4 = storage_path('app/testing-agent-media/clip.mp4');
        File::ensureDirectoryExists(dirname($this->mp4));
        $ffmpeg = (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=black:s=320x568:d=1',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $this->mp4]));
        $ffmpeg->run();
        if (!$ffmpeg->isSuccessful()) {
            $this->markTestSkipped('ffmpeg with libx264 is needed to make a test clip.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->mp4));
        File::deleteDirectory(public_path('generated/agent'));
        File::deleteDirectory(storage_path('app/agent-media'));
        parent::tearDown();
    }

    private function upload(array $fields = [], ?UploadedFile $file = null): \Illuminate\Testing\TestResponse
    {
        $file ??= new UploadedFile($this->mp4, 'story-45.mp4', 'video/mp4', null, true);

        return $this->withToken($this->token)->post('/api/agent/media', $fields + ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_the_agent_uploads_an_mp4_and_gets_a_public_static_url_that_expires_after_seven_days(): void
    {
        $story = StoryVideo::create(['kind' => 'match_story', 'lang' => 'en', 'status' => 'ready']);
        $r = $this->upload(['render_id' => "story:{$story->id}"])->assertCreated()
            ->assertJson(['content_type' => 'video/mp4', 'render_id' => "story:{$story->id}", 'bytes' => filesize($this->mp4)]);
        $id = $r->json('id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertSame("https://matchday.africa/generated/agent/{$id}.mp4", $r->json('url'));
        // A static file in the web root (the host serves it as video/mp4 with byte ranges), identical to the upload.
        $this->assertFileEquals($this->mp4, public_path("generated/agent/{$id}.mp4"));
        $this->assertFileExists(storage_path("app/agent-media/{$id}.json"));
        $this->assertEquals(7, Carbon::parse($r->json('created_at'))->diffInDays(Carbon::parse($r->json('expires_at'))));
        // Logged, with the render id.
        $log = AgentAuditLog::where('route', 'api.agent.media.store')->firstOrFail();
        $this->assertSame([201, "story:{$story->id}"], [$log->status, $log->params['values']['render_id']]);

        // Kept for seven days, then deleted with its record; other files in the folder are never touched.
        $other = public_path('generated/agent/keep-me.txt');
        file_put_contents($other, 'x');
        touch(public_path("generated/agent/{$id}.mp4"), now()->subDays(6)->getTimestamp());
        $this->artisan('agent:media-prune')->assertSuccessful();
        $this->assertFileExists(public_path("generated/agent/{$id}.mp4"));
        touch(public_path("generated/agent/{$id}.mp4"), now()->subDays(7)->subMinute()->getTimestamp());
        touch($other, now()->subDays(30)->getTimestamp());
        $this->artisan('agent:media-prune')->expectsOutputToContain('Deleted 1')->assertSuccessful();
        $this->assertFileDoesNotExist(public_path("generated/agent/{$id}.mp4"));
        $this->assertFileDoesNotExist(storage_path("app/agent-media/{$id}.json"));
        $this->assertFileExists($other);
    }

    public function test_only_mp4_up_to_200_mb_with_a_known_render_id_and_a_token(): void
    {
        $this->upload([], UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))->assertStatus(422)->assertJsonValidationErrors('file');
        // Named .mp4 but not an MP4 container.
        $fake = storage_path('app/testing-agent-media/fake.mp4');
        file_put_contents($fake, str_repeat('not a video ', 100));
        $this->upload([], new UploadedFile($fake, 'fake.mp4', 'video/mp4', null, true))->assertStatus(422);
        $this->upload([], UploadedFile::fake()->create('big.mp4', AgentMedia::MAX_KB + 1, 'video/mp4'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload(['render_id' => 'story:999'])->assertStatus(422)->assertJsonValidationErrors('render_id');
        $this->upload(['render_id' => 'reel:1'])->assertStatus(422)->assertJsonValidationErrors('render_id');
        $this->upload()->assertCreated(); // render id is optional
        $this->withoutToken()->post('/api/agent/media', ['file' => new UploadedFile($this->mp4, 'a.mp4', 'video/mp4', null, true)], ['Accept' => 'application/json'])
            ->assertUnauthorized();
        $this->assertCount(1, glob(public_path('generated/agent/*.mp4')));
    }
}
