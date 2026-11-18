<?php

namespace App\Console\Commands;

use App\Services\Slideshow\CommonsPhotos;
use Illuminate\Console\Command;

/**
 * php artisan story:photo "Abdellah Ouazane"                     (look the name up in the player media library)
 * php artisan story:photo "Abdellah Ouazane" "File:Some photo.jpg" (pin a Commons file; the licence is still checked)
 */
class StoryPhoto extends Command
{
    protected $signature = 'story:photo {name : The player as named in the match events} {file? : A Commons file to pin}';

    protected $description = 'Find or pin a free-licence photo for a player by name (player media library)';

    public function handle(CommonsPhotos $photos): int
    {
        $photo = $this->argument('file')
            ? $photos->pinName((string) $this->argument('name'), (string) $this->argument('file'))
            : $photos->forName((string) $this->argument('name'));
        if (!$photo) {
            $this->warn('No free-licence photo (CC0, PD, CC BY, CC BY-SA, 700 px or wider) for '.$this->argument('name').'.');

            return self::FAILURE;
        }
        $this->info("{$photo['title']}\n{$photo['credit']}\n{$photo['source_url']}");

        return self::SUCCESS;
    }
}
