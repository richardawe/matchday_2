<?php

namespace App\Console\Commands;

use App\Design\Film;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Draws one reference PNG per component (docs/design/<component>.png) from
 * resources/design/samples.json, each at rest through the shared frame engine.
 */
class DesignReferences extends Command
{
    protected $signature = 'design:references {--out= : Folder for the PNGs (default: docs/design at the repository root)} {--only= : One component}';

    protected $description = 'Render the design language reference image of every component';

    public function handle(Film $film): int
    {
        $samples = json_decode((string) file_get_contents(resource_path('design/samples.json')), true);
        $out = $this->option('out') ?: dirname(base_path()).'/docs/design';
        File::ensureDirectoryExists($out);
        foreach ($samples['cards'] as $card) {
            if ($this->option('only') && $this->option('only') !== $card['component']) {
                continue;
            }
            $card = $this->resolve($card, $samples);
            $img = $film->stillImage($card + ['start' => 0, 'seconds' => 4]);
            $file = "{$out}/{$card['component']}.png";
            $small = imagescale($img, 540, 960, IMG_BICUBIC);
            imagepng($small, $file, 9);
            imagedestroy($img);
            imagedestroy($small);
            $this->line("Wrote {$file}");
        }

        return self::SUCCESS;
    }

    /** Side sets by name and repository-relative file paths made absolute. */
    private function resolve(array $card, array $samples): array
    {
        if (isset($card['sides']) && is_string($card['sides'])) {
            $card['sides'] = $samples[$card['sides']];
        }
        array_walk_recursive($card, function (&$v, $k) {
            if (is_string($v) && in_array($k, ['flag', 'art', 'file'], true) && !str_starts_with($v, '/')) {
                $v = base_path($v);
            }
        });

        return $card;
    }
}
