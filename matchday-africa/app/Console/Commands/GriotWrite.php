<?php

namespace App\Console\Commands;

use App\Models\GriotPost;
use App\Services\Griot\GriotTimeline;
use App\Services\Griot\GriotWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Words new timeline posts with the small writer model (radio/griot-writer.mjs). Every caption is
 * fact-checked (GriotWriter::check); a rejected one is recorded as such and the templates speak.
 */
class GriotWrite extends Command
{
    protected $signature = 'griot:write';

    protected $description = 'Word new live timeline posts with the Griot writer model';

    public function handle(GriotTimeline $timeline, GriotWriter $writer): int
    {
        $model = (string) config('griot.writer.model');
        if (!$timeline->ready() || !config('griot.writer.enabled') || !is_dir($model)) {
            return self::SUCCESS;
        }
        $posts = GriotPost::with(['match.homeTeam', 'match.awayTeam'])->whereNull('written')
            ->where('updated_at', '>=', now()->subHours(3))->orderBy('seq')->limit((int) config('griot.writer.per_run', 6))->get();
        if ($posts->isEmpty()) {
            return self::SUCCESS;
        }
        $inputs = $posts->mapWithKeys(fn (GriotPost $post) => [$post->id => $writer->input($post, $post->match)]);
        $file = storage_path('app/griot/write-'.getmypid().'.json');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode($inputs->map(fn ($input, $id) => ['id' => $id, 'prompt' => $writer->prompt($input)])->values()));
        $process = new Process([config('griot.voice.node'), base_path('radio/griot-writer.mjs'), '--jobs', $file, '--model', $model], base_path('radio'),
            ['RADIO_TTS_CACHE' => config('radio.cache')], null, 300);
        try {
            $process->mustRun();
            $replies = json_decode(collect(explode("\n", trim($process->getOutput())))->last() ?? '', true) ?: [];
        } catch (Throwable $e) {
            Log::warning('Griot writer failed', ['error' => mb_substr($process->getErrorOutput() ?: $e->getMessage(), 0, 500)]);

            return self::SUCCESS;
        } finally {
            File::delete($file);
        }
        $accepted = 0;
        foreach ($replies as $reply) {
            $post = $posts->firstWhere('id', $reply['id'] ?? null);
            if (!$post) {
                continue;
            }
            $input = $inputs[$post->id];
            $caption = $writer->accept($input, (string) ($reply['text'] ?? ''));
            $accepted += $caption ? 1 : 0;
            // A rejected caption is remembered so it is not retried; the templates speak instead.
            $post->update(['written' => $caption ?? ['rejected' => $writer->check($input, json_decode((string) ($reply['text'] ?? ''), true))]]);
        }
        $this->info("Captions accepted: {$accepted}/".count($replies));

        return self::SUCCESS;
    }
}
