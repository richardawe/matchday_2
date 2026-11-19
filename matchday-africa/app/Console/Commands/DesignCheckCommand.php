<?php

namespace App\Console\Commands;

use App\Design\DesignCheck;
use App\Models\Slideshow;
use App\Models\StoryVideo;
use Illuminate\Console\Command;

/**
 * php artisan design:check --story=12          (a rendered match story or radio video)
 * php artisan design:check --slideshow=7
 * php artisan design:check path/to/video.mp4 [--manifest=design.json]
 *
 * Writes a contact sheet (one frame every 5 s) and lists every design-language problem; exits 1
 * when there is any.
 */
class DesignCheckCommand extends Command
{
    protected $signature = 'design:check {video? : An mp4 file} {--story= : StoryVideo id} {--slideshow= : Slideshow id}
        {--manifest= : The render\'s design.json} {--sheet= : Where to write the contact sheet PNG} {--every=5 : Seconds between frames} {--json : Print the report as JSON}';

    protected $description = 'Check a video against the design language and draw its contact sheet';

    public function handle(DesignCheck $check): int
    {
        [$video, $manifest] = $this->target();
        if (!$video) {
            $this->error('Give a video file, --story=ID or --slideshow=ID.');

            return self::FAILURE;
        }
        $data = $manifest && is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        if ($manifest && !is_array($data)) {
            $this->warn("No readable manifest at {$manifest}: text, fonts and credits are not checked.");
        }
        $sheet = $this->option('sheet') ?: preg_replace('/\.mp4$/', '', $video).'-contact-sheet.png';
        $report = $check->run($video, is_array($data) ? $data : null, $sheet, max(1.0, (float) $this->option('every')));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf('%s: %.1f s, %d frames checked%s', basename($video), $report['seconds'], count($report['frames']),
                $report['manifest'] ? '' : ' (no manifest: pixel checks only)'));
            foreach ($report['frames'] as $f) {
                $this->line(sprintf('  %6.1fs  %-13s %3d%% empty  %s', $f['t'], $f['component'] ?? '-', round($f['empty'] * 100), $f['problems'] ? 'FAIL' : 'ok'));
            }
            foreach ($report['problems'] as $p) {
                $this->error("  {$p}");
            }
            $this->line('Contact sheet: '.$report['sheet']);
            $report['ok'] ? $this->info('PASS') : $this->error('FAIL');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** [video file, manifest file]. */
    private function target(): array
    {
        if ($id = $this->option('story')) {
            $story = StoryVideo::findOrFail($id);

            return [$story->publicVideoPath(), $this->option('manifest') ?: storage_path("app/stories/{$story->id}/design.json")];
        }
        if ($id = $this->option('slideshow')) {
            $show = Slideshow::findOrFail($id);

            return [$show->videoFile(), $this->option('manifest') ?: $show->workDir().'/design.json'];
        }
        $video = $this->argument('video');

        return [$video ? (realpath($video) ?: $video) : null, $this->option('manifest')];
    }
}
