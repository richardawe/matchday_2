<?php

namespace App\Console\Commands;

use App\Services\YouTube\ReelUploads;
use App\Services\YouTube\SlideshowUploads;
use Illuminate\Console\Command;

class YouTubeUploadsCommand extends Command
{
    protected $signature = 'youtube:uploads';

    protected $description = 'Upload pending reels, then approved slideshows, to YouTube as Shorts, within the daily quota (YOUTUBE_UPLOADS)';

    public function handle(ReelUploads $uploads, SlideshowUploads $slideshows): int
    {
        if (!$uploads->enabled()) {
            $this->line('YouTube uploads are off, not migrated, or no channel that may upload is connected.');

            return self::SUCCESS;
        }
        [$done, $waiting] = $uploads->run();
        $this->info("YouTube: {$done} uploaded, {$waiting} waiting, {$uploads->left()} uploads left today.");
        if ($slideshows->enabled()) {
            [$done, $waiting] = $slideshows->run();
            $this->info("Slideshows: {$done} uploaded, {$waiting} waiting, {$slideshows->left()} slideshow uploads left today.");
        }

        return self::SUCCESS;
    }
}
