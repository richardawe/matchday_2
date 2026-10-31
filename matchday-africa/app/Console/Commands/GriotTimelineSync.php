<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Espn\EspnClient;
use App\Services\Espn\EspnCommentaryMapper;
use App\Services\Griot\GriotTimeline;
use Illuminate\Console\Command;

/**
 * Retells live and just-finished matches on the Griot timeline. The ESPN details pass already
 * does this as plays arrive; this catches matches it missed and rebuilds one match on request.
 */
class GriotTimelineSync extends Command
{
    protected $signature = 'griot:timeline {--match= : Rebuild one match (our match ID)} {--fetch : With --match, fetch its ESPN commentary first}';

    protected $description = 'Build the live Griot timeline posts from stored match plays';

    public function handle(GriotTimeline $timeline): int
    {
        if (!$timeline->ready()) {
            $this->warn('GRIOT_TIMELINE is off or the migration has not run; nothing to do.');

            return self::SUCCESS;
        }
        if ($id = $this->option('match')) {
            $match = FootballMatch::with(['league', 'homeTeam', 'awayTeam'])->findOrFail($id);
            if ($this->option('fetch')) {
                $summary = $match->espn_id && $match->league?->espn_slug ? app(EspnClient::class)->summary($match->league->espn_slug, (int) $match->espn_id) : null;
                if (!$summary) {
                    $this->error('No ESPN summary for this match (needs espn_id and the league\'s ESPN slug).');

                    return self::FAILURE;
                }
                $this->line('Plays stored: '.$timeline->savePlays($match, app(EspnCommentaryMapper::class)->plays($summary)));
            }
            $timeline->build($match);
            foreach ($timeline->posts($match)->reverse() as $post) {
                $this->line(sprintf('%-6s %-11s %s', $post->clock ?? '', $post->kind, json_encode($post->facts, JSON_UNESCAPED_UNICODE)));
            }

            return self::SUCCESS;
        }
        $this->info('New posts: '.$timeline->syncRecent());

        return self::SUCCESS;
    }
}
