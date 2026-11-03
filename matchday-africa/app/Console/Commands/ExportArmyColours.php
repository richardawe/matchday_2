<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Writes resources/armies/colours.json (art => sky colour) for scripts/build-armies.mjs. */
class ExportArmyColours extends Command
{
    protected $signature = 'armies:colours';
    protected $description = 'Export each army\'s sky colour for the mood thumbnails (node scripts/build-armies.mjs)';

    public function handle(): int
    {
        $out = [];
        foreach (config('armies.clubs') as $club) {
            if ($club['art']) {
                // The first kit colour that reads as a sky; near-white and near-black kits fall back to slate.
                $usable = array_values(array_filter($club['colours'], fn ($hex) => ($l = $this->luma($hex)) > 34 && $l < 225));
                $out[$club['art']] = $usable[0] ?? '#4a5260';
            }
        }
        file_put_contents(resource_path('armies/colours.json'), json_encode($out, JSON_PRETTY_PRINT)."\n");
        $this->info(count($out).' armies -> resources/armies/colours.json');

        return self::SUCCESS;
    }

    private function luma(string $hex): float
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
