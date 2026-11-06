<?php

namespace App\Console\Commands;

use App\Services\PlayerPhotos;
use Illuminate\Console\Command;

class SyncPlayerPhotos extends Command
{
    protected $signature = 'players:photos {--teams=8 : Squads to refresh this run}';

    protected $description = 'Save player photos from API-Football squads (oldest squads first, one call per team)';

    public function handle(PlayerPhotos $photos): int
    {
        if (!$photos->configured()) {
            $this->warn('API_FOOTBALL_KEY is not configured; player photos skipped.');

            return self::SUCCESS;
        }
        $result = $photos->sync((int) $this->option('teams'));
        $this->info("Player photos: {$result['teams']} squads, {$result['photos']} photos, {$result['added']} players added, {$result['calls']} API calls.");
        if ($result['error']) {
            $this->warn($result['error']);
        }

        return self::SUCCESS;
    }
}
