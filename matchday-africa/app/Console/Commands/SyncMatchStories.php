<?php
namespace App\Console\Commands;
use App\Services\MatchStoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncMatchStories extends Command {
    protected $signature='story:sync';
    protected $description='Create the Griot\'s chapter cards for live and just-finished Premier League matches';
    public function handle(MatchStoryService $stories): int {
        // Quiet until `php artisan migrate` has created the table on the server.
        if(!Schema::hasTable('match_moments')){ $this->warn('match_moments table missing; run migrations.'); return self::SUCCESS; }
        $this->info($stories->syncRecent().' new chapters');
        return self::SUCCESS;
    }
}
