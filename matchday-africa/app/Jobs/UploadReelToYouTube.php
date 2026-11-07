<?php

namespace App\Jobs;

use App\Models\FootballMatch;
use App\Services\YouTube\ReelUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Uploads one match's reel to YouTube as a Short. Sent when the reel becomes ready. Retries (three,
 * with backoff) and the daily quota are handled by ReelUploads through youtube:uploads, which also
 * picks up anything this job leaves pending, so a missing queue worker only delays an upload.
 */
class UploadReelToYouTube implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $matchId) {}

    public function handle(ReelUploads $uploads): void
    {
        if ($match = FootballMatch::find($this->matchId)) {
            $uploads->upload($match);
        }
    }
}
