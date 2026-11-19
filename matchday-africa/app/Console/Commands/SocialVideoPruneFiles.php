<?php

namespace App\Console\Commands;

use App\Models\SocialVideo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Frees disk space: deletes public/generated/videos/{id}/video.mp4 for long videos that have been taken
 * down (status off), or neither featured nor queued, for more than --days (30). The row, the poster and
 * the page stay; the page then shows the poster with the TikTok/YouTube link. Featured or queued videos
 * are never touched.
 */
class SocialVideoPruneFiles extends Command
{
    protected $signature = 'videos:prune-files {--days=30} {--dry-run}';

    protected $description = 'Delete the mp4 of long videos idle (off, or not featured and not queued) for 30+ days';

    public function handle(): int
    {
        if (!SocialVideo::ready() || !Schema::hasColumn('social_videos', 'idle_since')) {
            $this->line('Long videos are not migrated; nothing to do.');

            return self::SUCCESS;
        }
        $days = max(1, (int) $this->option('days'));
        $removed = 0;
        SocialVideo::idle()->whereNull('video_removed_at')->whereNotNull('idle_since')
            ->where('idle_since', '<=', now()->subDays($days))
            ->each(function (SocialVideo $video) use (&$removed) {
                if (!$video->isIdle()) {
                    return; // featured or queued: never deleted
                }
                $file = $video->publicVideoPath();
                if ($this->option('dry-run')) {
                    $this->line("Would delete {$file}");

                    return;
                }
                if (is_file($file) && !@unlink($file)) {
                    $this->warn("Could not delete {$file}");

                    return;
                }
                $video->forceFill(['video_removed_at' => now()])->save();
                $removed++;
                $this->line("Deleted the mp4 of video:{$video->id}");
            });
        $this->info("Removed {$removed} long-video file(s).");

        return self::SUCCESS;
    }
}
