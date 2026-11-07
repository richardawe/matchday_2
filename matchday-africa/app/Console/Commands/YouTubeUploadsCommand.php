<?php

namespace App\Console\Commands;

use App\Services\YouTube\ReelUploads;
use Illuminate\Console\Command;

class YouTubeUploadsCommand extends Command
{
    protected $signature = 'youtube:uploads';

    protected $description = 'Upload pending reels to YouTube as Shorts, within the daily quota (YOUTUBE_UPLOADS)';

    public function handle(ReelUploads $uploads): int
    {
        if (!$uploads->enabled()) {
            $this->line('YouTube uploads are off, not migrated, or no channel that may upload is connected.');

            return self::SUCCESS;
        }
        [$done, $waiting] = $uploads->run();
        $this->info("YouTube: {$done} uploaded, {$waiting} waiting, {$uploads->left()} uploads left today.");

        return self::SUCCESS;
    }
}
