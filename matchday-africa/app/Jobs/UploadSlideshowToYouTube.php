<?php

namespace App\Jobs;

use App\Models\Slideshow;
use App\Services\YouTube\SlideshowUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Uploads one approved slideshow as a YouTube Short. youtube:uploads picks up anything this job
 * leaves pending (quota, retries, no queue worker), so a missing worker only delays it.
 */
class UploadSlideshowToYouTube implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $slideshowId) {}

    public function handle(SlideshowUploads $uploads): void
    {
        if ($show = Slideshow::find($this->slideshowId)) {
            $uploads->upload($show);
        }
    }
}
