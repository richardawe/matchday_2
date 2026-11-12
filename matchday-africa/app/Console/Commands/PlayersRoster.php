<?php

namespace App\Console\Commands;

use App\Services\PlayerPages;
use Illuminate\Console\Command;

class PlayersRoster extends Command
{
    protected $signature = 'players:roster';

    protected $description = 'Rebuild the weekly African players roster pages (Premier League first, then the other covered leagues)';

    public function handle(PlayerPages $pages): int
    {
        foreach ($pages->leagues() as $league) {
            $roster = $pages->roster($league, fresh: true);
            $this->line("{$league->name}: {$roster['count']} players at ".count($roster['clubs']).' clubs');
        }

        return self::SUCCESS;
    }
}
