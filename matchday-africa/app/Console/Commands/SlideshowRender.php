<?php

namespace App\Console\Commands;

use App\Models\Slideshow;
use App\Services\Slideshow\Slideshows;
use App\Services\Slideshow\SlideshowVideo;
use Illuminate\Console\Command;

class SlideshowRender extends Command
{
    protected $signature = 'slideshow:render {--id= : Render this slideshow now} {--limit= : How many waiting slideshows to render} {--check : Check ffmpeg and the music file only}';

    protected $description = 'Render waiting colour slideshows (highest priority first) into 1080x1920 mp4s';

    public function handle(Slideshows $slideshows, SlideshowVideo $video): int
    {
        if ($this->option('check')) {
            $music = Slideshows::musicFile();
            $missing = $video->missing($music);
            $this->line('ffmpeg: '.$video->ffmpeg().($missing ? ' is missing '.implode(', ', $missing) : ' has everything needed').' (video mode: '.$video->mode().')');
            $sha = (string) config('slideshow.music.tracks.'.config('slideshow.music.track').'.sha256');
            $this->line('Music: '.$music.(!is_file($music) ? ' (MISSING: run php artisan slideshow:music, or upload it at /admin/slideshows)'
                : ($sha === '' ? ' (found)' : (hash_file('sha256', $music) === $sha ? ' (found, checked)' : ' (found, but not the file the credit record names)'))));

            return $missing || !is_file($music) ? self::FAILURE : self::SUCCESS;
        }
        if (!Slideshows::enabled()) {
            $this->line('Slideshows are off (SLIDESHOW_ENABLED) or not migrated.');

            return self::SUCCESS;
        }
        if ($id = $this->option('id')) {
            $show = Slideshow::findOrFail($id);
            $show->forceFill(['status' => 'pending', 'attempts' => 0])->save();
            $ok = $slideshows->render($show);
            $show->refresh();
            $this->line($ok ? "Slideshow {$show->id} is ready: {$show->videoUrl()}" : "Slideshow {$show->id} failed: {$show->error}");

            return $ok ? self::SUCCESS : self::FAILURE;
        }
        [$ready, $failed] = $slideshows->renderWaiting($this->option('limit') !== null ? (int) $this->option('limit') : null);
        $this->info("Slideshows: {$ready} rendered, {$failed} failed.");

        return self::SUCCESS;
    }
}
