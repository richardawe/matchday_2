<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\YouTube\ReelUploads;
use Illuminate\Console\Command;

class YouTubeBackfillCommand extends Command
{
    protected $signature = 'youtube:backfill {--days=7 : Matches played in the last this many days}';

    protected $description = 'Queue the ready reels of recent matches that are not on YouTube yet, then upload what the quota allows';

    public function handle(ReelUploads $uploads): int
    {
        if (!$uploads->enabled()) {
            $this->error('YouTube uploads are off (YOUTUBE_UPLOADS), not migrated, or no channel that may upload is connected (/admin/youtube).');

            return self::FAILURE;
        }
        $days = max(1, (int) $this->option('days'));
        $queued = FootballMatch::whereNull('youtube_video_id')
            ->where('match_date', '>=', now('UTC')->subDays($days))
            ->where('metadata->reel->status', 'ready')
            ->where(fn ($q) => $q->whereNull('youtube_status')->orWhere('youtube_status', '!=', 'uploading'))
            ->orderByDesc('match_date')->get()
            ->filter(fn ($match) => $uploads->queue($match))->count();
        $this->info("Queued {$queued} reels from the last {$days} days.");
        [$done, $waiting] = $uploads->run();
        $this->info("Uploaded {$done} now; {$waiting} wait for the next quota day (youtube:uploads runs every 5 minutes).");

        return self::SUCCESS;
    }
}
