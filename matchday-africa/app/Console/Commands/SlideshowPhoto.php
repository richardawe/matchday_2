<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\Slideshow\CommonsPhotos;
use Illuminate\Console\Command;

class SlideshowPhoto extends Command
{
    protected $signature = 'slideshow:photo {player : Player id} {file? : A Commons file name to use (e.g. "File:Name.jpg"); empty looks it up through Wikidata}';

    protected $description = "Find or pin a player's freely licensed Wikimedia Commons photo for slideshows (licence checked with the Commons API)";

    public function handle(CommonsPhotos $photos): int
    {
        $player = Player::findOrFail($this->argument('player'));
        $photo = $photos->lookup($player, $this->argument('file') ?: null);
        if (!$photo) {
            $this->warn('No usable photo: '.data_get($player->fresh()->metadata, 'commons_photo.reason', 'lookup failed (see the log)'));

            return self::FAILURE;
        }
        $this->info("{$player->name}: {$photo['title']} ({$photo['licence']}), credit \"{$photo['credit']}\"");

        return self::SUCCESS;
    }
}
