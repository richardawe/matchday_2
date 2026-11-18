<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentMedia;
use Illuminate\Console\Command;

/** Deletes the agent's uploaded videos (public/generated/agent) seven days after upload. */
class AgentMediaPrune extends Command
{
    protected $signature = 'agent:media-prune';

    protected $description = 'Delete agent media uploads older than 7 days';

    public function handle(AgentMedia $media): int
    {
        $this->info('Deleted '.$media->prune().' agent upload(s) older than '.AgentMedia::KEEP_DAYS.' days.');

        return self::SUCCESS;
    }
}
