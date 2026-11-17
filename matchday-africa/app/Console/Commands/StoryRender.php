<?php

namespace App\Console\Commands;

use App\Models\StoryVideo;
use App\Services\Story\StoryVideos;
use Illuminate\Console\Command;

/**
 * Match-story and radio videos: queues finished matches and published radio editions, then renders
 * the oldest waiting job (several minute-runs may render at once, up to STORY_CONCURRENCY).
 */
class StoryRender extends Command
{
    protected $signature = 'story:render
        {--match= : Queue and render this finished match now}
        {--radio= : Queue and render this published radio edition now}
        {--lang=en : Language for --match/--radio (en or fr)}
        {--check : Only report what ffmpeg lacks and whether the music file is in place}';

    protected $description = 'Render 61-75 s match-story videos after full time and radio videos for the social queue';

    public function handle(StoryVideos $stories): int
    {
        if ($this->option('check')) {
            $missing = $stories->missing();
            $this->line($missing ? 'Story videos cannot render. Missing: '.implode(', ', $missing) : 'Story videos: ffmpeg has everything it needs.');
            $this->line(StoryVideos::musicFile() ? 'Music: '.StoryVideos::musicFile() : 'Music: not found (stories render silent). Add matchday_original_beat_v1.mp3 to resources/audio/.');

            return $missing ? self::FAILURE : self::SUCCESS;
        }
        if (!StoryVideos::enabled()) {
            $this->line('Story videos are off (STORY_VIDEOS) or not migrated.');

            return self::SUCCESS;
        }
        if ($missing = $stories->missing()) {
            $this->error('Story videos cannot render. Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }
        if ($this->option('match') || $this->option('radio')) {
            $story = $stories->request($this->option('radio') ? 'radio_video' : 'match_story',
                (int) ($this->option('radio') ?: $this->option('match')), (string) $this->option('lang'), [], 'admin');
        } else {
            $queued = $stories->queueFinished();
            if ($queued) {
                $this->line("Queued {$queued} story video(s).");
            }
            $story = $stories->claimNext();
        }
        if (!$story) {
            $this->line('No story video to render now.');

            return self::SUCCESS;
        }
        $ok = $stories->render($story);
        $story = StoryVideo::find($story->id);
        $ok ? $this->info("Story {$story->id} ({$story->kind}): ready, {$story->duration_seconds} s.")
            : $this->error("Story {$story->id} ({$story->kind}): failed: {$story->error}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
