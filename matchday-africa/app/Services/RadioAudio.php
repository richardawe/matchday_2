<?php

namespace App\Services;

use App\Models\RadioBulletin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Provider boundary: a future speech service can replace this without changing publishing. */
class RadioAudio
{
    public function render(RadioBulletin $bulletin): array
    {
        $dir = storage_path('app/radio/'.$bulletin->id);
        File::ensureDirectoryExists($dir);
        $id = (string) Str::uuid();
        $props = $dir.'/'.$id.'.json';
        $path = 'radio/'.$bulletin->id.'/'.$id.'.mp3';
        $intro = strstr($bulletin->script, "\n\n", true);
        $outro = substr($bulletin->script, strrpos($bulletin->script, "\n\n") + 2);
        $paragraphs = array_merge([$intro], collect($bulletin->segments)->pluck('text')->all(), [$outro]);
        File::put($props, json_encode(['text' => $bulletin->script, 'paragraphs' => $paragraphs,
            'voice' => config('radio.voice')], JSON_THROW_ON_ERROR));
        try {
            $process = new Process([config('radio.node'), base_path('radio/render.mjs'), '--props', $props,
                '--out', storage_path('app/'.$path)], base_path('radio'), [
                    'RADIO_TTS_CACHE' => config('radio.cache'), 'RADIO_FFMPEG' => config('radio.ffmpeg'),
                ], null, config('radio.timeout'));
            $process->mustRun();
            $lines = preg_split('/\r?\n/', trim($process->getOutput()));
            $result = json_decode(end($lines), true);
            if (! is_array($result) || ($result['seconds'] ?? 0) < 5 || $result['seconds'] > 310
                || ! is_file(storage_path('app/'.$path)) || filesize(storage_path('app/'.$path)) < 1000) {
                throw new RuntimeException('Audio failed duration or file checks.');
            }

            $cues = $result['cues'] ?? [];
            if (count($cues) !== count($paragraphs)) {
                throw new RuntimeException('Audio story timing is missing.');
            }
            $previous = 0;
            foreach ($cues as $index => $cue) {
                if (($cue['paragraph'] ?? null) !== $index || ! is_numeric($cue['start'] ?? null)
                    || ! is_numeric($cue['end'] ?? null) || $cue['start'] < $previous
                    || $cue['end'] <= $cue['start'] || $cue['end'] > $result['seconds'] + 0.01) {
                    throw new RuntimeException('Audio story timing is invalid.');
                }
                $previous = $cue['end'];
            }

            return ['audio_cues' => array_values(array_slice($cues, 1, count($paragraphs) - 2)), 'audio_path' => $path, 'audio_bytes' => filesize(storage_path('app/'.$path)),
                'duration_seconds' => (int) ceil($result['seconds']), 'voice' => config('radio.voice')];
        } finally {
            File::delete($props);
        }
    }
}
