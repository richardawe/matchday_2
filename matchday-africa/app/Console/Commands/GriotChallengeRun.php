<?php

namespace App\Console\Commands;

use App\Services\Griot\GriotChallengeService;
use Illuminate\Console\Command;

/** Plans, reminds and scores Beat the Griot (see GriotChallengeService). Quiet while switched off. */
class GriotChallengeRun extends Command
{
    protected $signature = 'griot:challenge';

    protected $description = 'Plan, remind and score the Beat the Griot round';

    public function handle(GriotChallengeService $service): int
    {
        if (!GriotChallengeService::ready()) {
            return self::SUCCESS;
        }
        $result = $service->run();
        $this->line(sprintf('planned: %s, reminded: %d, scored: %s', $result['planned'] ?? '-', $result['reminded'], $result['scored'] ?? '-'));

        return self::SUCCESS;
    }
}
