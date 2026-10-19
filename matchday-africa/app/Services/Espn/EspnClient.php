<?php

namespace App\Services\Espn;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for ESPN's public soccer feed. Never retries or sleeps: a failed poll is
 * simply picked up by the next scheduler run, and repeated failures open a circuit
 * breaker so the slower providers take over.
 */
class EspnClient
{
    private const FAILURES_KEY = 'espn:consecutive-failures';
    private const PAUSED_KEY = 'espn:paused-until';

    public function scoreboard(string $league, string $date): ?array
    {
        return $this->get("{$league}/scoreboard", ['dates' => str_replace('-', '', $date)]);
    }

    public function summary(string $league, int $eventId): ?array
    {
        return $this->get("{$league}/summary", ['event' => $eventId]);
    }

    public function isPaused(): bool
    {
        return (int) Cache::get(self::PAUSED_KEY, 0) > now()->timestamp;
    }

    public function pausedUntil(): ?string
    {
        $until = (int) Cache::get(self::PAUSED_KEY, 0);

        return $until > now()->timestamp ? date(DATE_ATOM, $until) : null;
    }

    private function get(string $path, array $query): ?array
    {
        if ($this->isPaused()) {
            return null;
        }

        try {
            $response = Http::timeout(config('espn.timeout', 8))
                ->acceptJson()
                ->get(rtrim(config('espn.base_url'), '/').'/'.$path, $query);
            $data = $response->json();

            if ($response->successful() && is_array($data)) {
                Cache::forget(self::FAILURES_KEY);

                return $data;
            }

            $this->recordFailure($path, 'HTTP '.$response->status());
        } catch (Throwable $e) {
            $this->recordFailure($path, $e->getMessage());
        }

        return null;
    }

    private function recordFailure(string $path, string $reason): void
    {
        $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;
        Cache::put(self::FAILURES_KEY, $failures, now()->addHour());
        Log::warning('ESPN request failed', ['path' => $path, 'reason' => $reason, 'consecutive' => $failures]);

        if ($failures >= config('espn.breaker_failures', 5)) {
            Cache::put(self::PAUSED_KEY, now()->addMinutes(config('espn.breaker_minutes', 15))->timestamp, now()->addHour());
            Cache::forget(self::FAILURES_KEY);
            Log::error('ESPN feed paused after repeated failures', ['minutes' => config('espn.breaker_minutes', 15)]);
        }
    }
}
