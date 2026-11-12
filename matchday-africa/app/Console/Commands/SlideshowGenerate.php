<?php

namespace App\Console\Commands;

use App\Services\Slideshow\SlideshowPicker;
use App\Services\Slideshow\Slideshows;
use Illuminate\Console\Command;

class SlideshowGenerate extends Command
{
    protected $signature = 'slideshow:generate {what=daily : daily (previews, plus Team of the Week on Mondays), post_match, totw or all}';

    protected $description = 'Create colour slideshow records for matches with African players or players of confirmed African descent (SLIDESHOW_ENABLED)';

    public function handle(SlideshowPicker $picker): int
    {
        if (!Slideshows::enabled()) {
            $this->line('Slideshows are off (SLIDESHOW_ENABLED) or not migrated.');

            return self::SUCCESS;
        }
        $what = (string) $this->argument('what');
        if (!in_array($what, ['daily', 'preview', 'post_match', 'totw', 'all'], true)) {
            $this->error('Choose daily, preview, post_match, totw or all.');

            return self::INVALID;
        }
        $made = $picker->generate($what);
        $this->info("Slideshows: {$made} new.");

        return self::SUCCESS;
    }
}
