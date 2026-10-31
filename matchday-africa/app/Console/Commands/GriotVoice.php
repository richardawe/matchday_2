<?php

namespace App\Console\Commands;

use App\Models\GriotPost;
use App\Services\Griot\GriotTeller;
use App\Services\Griot\GriotTimeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Speaks new timeline posts in the Griot's voice (radio/griot-voice.mjs: Kokoro with a blended voice,
 * lowered and warmed, after a talking-drum call). Clips are static files under public/generated/griot,
 * which the host serves with byte ranges for phones. A failure leaves the post silent, never missing.
 */
class GriotVoice extends Command
{
    protected $signature = 'griot:voice {--audition : Write sample clips to storage/app/griot/audition}';

    protected $description = 'Record the Griot speaking new live timeline posts';

    public function handle(GriotTimeline $timeline, GriotTeller $teller): int
    {
        if ($this->option('audition')) {
            return $this->run_(['--audition', storage_path('app/griot/audition')]) ? self::SUCCESS : self::FAILURE;
        }
        if (!$timeline->ready() || !config('griot.voice.enabled')) {
            return self::SUCCESS;
        }
        $posts = GriotPost::with(['match.homeTeam', 'match.awayTeam'])
            ->whereNull('audio_path')->where('updated_at', '>=', now()->subHours(3))
            // With the writer on, give it two minutes to word the post before the Griot speaks it.
            ->when(config('griot.writer.enabled'), fn ($q) => $q->where(fn ($q) => $q->whereNotNull('written')->orWhere('updated_at', '<=', now()->subMinutes(2))))
            ->orderBy('seq')->limit((int) config('griot.voice.per_run', 6))->get();
        if ($posts->isEmpty()) {
            return self::SUCCESS;
        }
        $jobs = $posts->map(function (GriotPost $post) use ($teller) {
            $text = $teller->speech($post, 'en');

            return ['id' => $post->id, 'kind' => $post->kind, 'text' => $text,
                'out' => public_path("generated/griot/{$post->match_id}/{$post->id}-".substr(sha1($text), 0, 8).'.mp3')];
        })->values()->all();

        $file = storage_path('app/griot/jobs-'.getmypid().'.json');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode($jobs));
        try {
            $done = $this->run_(['--jobs', $file]);
        } finally {
            File::delete($file);
        }
        $public = realpath(public_path('generated/griot'));
        foreach ($done ?: [] as $clip) {
            $post = $posts->firstWhere('id', $clip['id'] ?? null);
            $path = realpath((string) ($clip['out'] ?? ''));
            if ($post && $path && $public && str_starts_with($path, $public.DIRECTORY_SEPARATOR)) {
                $post->update(['audio_path' => ltrim(str_replace(realpath(public_path()), '', $path), DIRECTORY_SEPARATOR)]);
            }
        }
        $this->info('Clips recorded: '.count($done ?: []));

        return self::SUCCESS;
    }

    private function run_(array $args): ?array
    {
        $process = new Process([config('griot.voice.node'), base_path('radio/griot-voice.mjs'), ...$args], base_path('radio'), [
            'RADIO_TTS_CACHE' => config('radio.cache'),
            'GRIOT_FFMPEG' => config('griot.voice.ffmpeg'),
            'GRIOT_VOICE_BLEND' => config('griot.voice.blend'),
            'GRIOT_VOICE_SPEED' => (string) config('griot.voice.speed'),
        ], null, 300);
        try {
            $process->mustRun();
            $result = json_decode(collect(explode("\n", trim($process->getOutput())))->last() ?? '', true);

            return is_array($result) ? $result : null;
        } catch (Throwable $e) {
            Log::warning('Griot voice failed', ['error' => mb_substr($process->getErrorOutput() ?: $e->getMessage(), 0, 500)]);
            $this->error('Griot voice failed: '.mb_substr($process->getErrorOutput() ?: $e->getMessage(), 0, 500));

            return null;
        }
    }
}
