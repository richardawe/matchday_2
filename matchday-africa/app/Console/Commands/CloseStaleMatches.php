<?php

namespace App\Console\Commands;

use App\Services\StaleMatchCloser;
use App\Services\SyncRunService;
use Illuminate\Console\Command;

class CloseStaleMatches extends Command
{
    protected $signature = 'matches:close-stale {--dry-run : Only count the stale matches}';

    protected $description = 'Mark matches finished when they are still "live" 3 hours after kick-off with no update';

    public function handle(StaleMatchCloser $closer, SyncRunService $runs): int
    {
        if ($this->option('dry-run')) {
            $this->info($closer->count().' stale live matches would be closed.');

            return self::SUCCESS;
        }
        $result = $runs->run('matches:close-stale', function () use ($closer) {
            $closed = $closer->close();

            return ['success' => $closed, 'message' => $closed.' stale live matches closed.'];
        });
        $this->info($result['message']);

        return self::SUCCESS;
    }
}
