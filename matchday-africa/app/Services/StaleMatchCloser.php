<?php

namespace App\Services;

use App\Models\FootballMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Matches left "live" because the feed stopped sending updates. A match is closed as FINISHED
 * once kick-off was more than 3 hours ago and no update has arrived for 30 minutes, or once
 * kick-off was more than 6 hours ago whatever the feed says. The last known score is kept and
 * the match is flagged (metadata.status_inferred_at) so prediction scoring and reels wait
 * until a feed confirms the result (FootballMatch::statusInferred()).
 */
class StaleMatchCloser
{
    public const AFTER_HOURS = 3;
    public const QUIET_MINUTES = 30;
    public const HARD_HOURS = 6;

    public function query(): Builder
    {
        // match_date holds UTC wall-clock values; last_api_update is written in app time.
        $utc = now('UTC');

        return FootballMatch::whereIn('status', FootballMatch::LIVE_STATUSES)
            ->where('match_date', '<', $utc->copy()->subHours(self::AFTER_HOURS)->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereNull('last_api_update')
                ->orWhere('last_api_update', '<', now()->subMinutes(self::QUIET_MINUTES))
                ->orWhere('match_date', '<', $utc->copy()->subHours(self::HARD_HOURS)->format('Y-m-d H:i:s')));
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /** Closes every stale match; returns how many. Saved quietly: no broadcasts or scoring storm. */
    public function close(): int
    {
        $closed = 0;
        $this->query()->chunkById(200, function ($matches) use (&$closed) {
            foreach ($matches as $match) {
                $meta = is_array($match->metadata) ? $match->metadata : [];
                $meta['status_inferred_at'] = now()->toIso8601String();
                $meta['status_before_inferred'] = $match->status;
                try {
                    $match->forceFill(['status' => 'FINISHED', 'metadata' => $meta])->saveQuietly();
                    $closed++;
                } catch (\Throwable $e) {
                    // One bad metadata payload must not leave the rest of the backlog live.
                    Log::warning('Stale match model save failed; closing the row directly', [
                        'match_id' => $match->id, 'error' => $e->getMessage(),
                    ]);
                    try {
                        DB::table('matches')->where('id', $match->id)->update([
                            'status' => 'FINISHED',
                            'metadata' => json_encode(['status_inferred_at' => $meta['status_inferred_at'], 'status_before_inferred' => $meta['status_before_inferred']]),
                            'updated_at' => now(),
                        ]);
                        $closed++;
                    } catch (\Throwable $again) {
                        Log::error('Stale match could not be closed', ['match_id' => $match->id, 'error' => $again->getMessage()]);
                    }
                }
            }
        });
        if ($closed) {
            Log::info('Closed stale live matches', ['count' => $closed]);
        }

        return $closed;
    }
}
