<?php

namespace App\Services\Slideshow;

use App\Design\Film;
use Illuminate\Support\Facades\File;

/**
 * Slideshow stills: each slide is a component cut (App\Design\Components) drawn at rest by the
 * shared frame engine, for the poster and the record. The video itself is drawn frame by frame by
 * the same engine (SlideshowVideo::make). The safe zone, contrast, copy and image rules are
 * enforced by the engine (UnsafeLayout / DesignViolation).
 */
class SlideshowRenderer
{
    public function __construct(private ?Film $film = null) {}

    /**
     * Draws every slide into $dir as 01.png, 02.png … Returns [['file' => png, 'cut' => slide], …].
     *
     * @throws UnsafeLayout when the content leaves the safe area or breaks a design rule
     */
    public function render(array $slides, string $dir): array
    {
        $film = $this->film ?? app(Film::class);
        File::ensureDirectoryExists($dir);
        $film->assertNoRepeats($slides);
        $out = [];
        foreach (array_values($slides) as $i => $slide) {
            $img = $film->stillImage($slide + ['start' => 0, 'seconds' => 4]);
            $file = sprintf('%s/%02d.png', $dir, $i + 1);
            imagepng($img, $file, 6);
            imagedestroy($img);
            $out[] = ['file' => $file, 'cut' => $slide];
        }

        return $out;
    }
}
