<?php

namespace App\Console\Commands;

use App\Services\YouTube\YouTubeBroadcasts;
use Illuminate\Console\Command;

class YouTubeBroadcastsCommand extends Command
{
    protected $signature = 'youtube:broadcasts';

    protected $description = 'Schedule a YouTube broadcast per matchday (YOUTUBE_AUTO) and end finished ones';

    public function handle(YouTubeBroadcasts $broadcasts): int
    {
        $result = $broadcasts->run();
        $this->info("YouTube: {$result['created']} scheduled, {$result['ended']} ended.");
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
