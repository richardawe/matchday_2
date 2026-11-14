<?php

namespace App\Console\Commands;

use App\Models\RadioBulletin;
use App\Services\RadioVideo;
use Illuminate\Console\Command;

/** Renders the vertical video of one published radio edition (newest first), or checks ffmpeg. */
class RadioVideoCommand extends Command
{
    protected $signature = 'radio:video {bulletin? : Render this edition now (retries a failed one)} {--check : Only report what ffmpeg lacks}';

    protected $description = 'Render the 1080x1920 video of a published Matchday Radio edition for the social queue';

    public function handle(RadioVideo $video): int
    {
        if ($this->option('check')) {
            $missing = $video->missing();
            $this->line($missing ? 'Radio video cannot render. Missing: '.implode(', ', $missing) : 'Radio video: ffmpeg has everything it needs.');

            return $missing ? self::FAILURE : self::SUCCESS;
        }
        if (!RadioVideo::ready()) {
            $this->line('Radio video is off (RADIO_VIDEO_ENABLED) or not migrated.');

            return self::SUCCESS;
        }
        if ($id = $this->argument('bulletin')) {
            $bulletin = RadioBulletin::published()->find($id);
            if (!$bulletin) {
                $this->error("Edition {$id} is not published.");

                return self::FAILURE;
            }
            $bulletin->update(['video_attempts' => 0]);
        } else {
            $bulletin = $video->next();
        }
        if (!$bulletin) {
            $this->line('No edition needs a video.');

            return self::SUCCESS;
        }
        if ($missing = $video->missing()) {
            $bulletin->update(['video_status' => 'failed', 'video_error' => 'ffmpeg lacks: '.implode(', ', $missing)]);
            $this->error('Radio video cannot render. Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }
        $ok = $video->render($bulletin);
        $bulletin->refresh();
        $ok ? $this->info("Edition {$bulletin->id}: video ready ({$bulletin->video_seconds} s).")
            : $this->error("Edition {$bulletin->id}: video failed: {$bulletin->video_error}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
