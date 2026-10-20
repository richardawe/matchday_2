<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RadioCheck extends Command
{
    protected $signature = 'radio:check {--sample : Generate a short Kokoro audition in private storage}';

    protected $description = 'Check Node, Kokoro and FFmpeg without publishing a bulletin';

    public function handle(): int
    {
        $args = [config('radio.node'), base_path('radio/render.mjs'), '--check'];
        if ($this->option('sample')) {
            \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/radio'));
            $args = [config('radio.node'), base_path('radio/render.mjs'), '--sample', '--out', storage_path('app/radio/audition.mp3')];
        }
        $process = new Process($args, base_path('radio'), ['RADIO_TTS_CACHE' => config('radio.cache'),
            'RADIO_VOICE' => config('radio.voice'), 'RADIO_FFMPEG' => config('radio.ffmpeg')], null, config('radio.timeout'));
        $process->run(fn ($type, $output) => $this->output->write($output));

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
