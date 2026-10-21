<?php

namespace App\Services;

use App\Models\RadioBulletin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class RadioService
{
    public function queue(bool $fresh = false): RadioBulletin
    {
        $now = now('Africa/Lagos');
        $slot = $now->copy()->startOfDay();
        foreach (config('radio.hours') as $hour) {
            if ($hour <= $now->hour) {
                $slot = $now->copy()->setTime($hour, 0);
            }
        }
        if ($now->hour < 6) {
            $slot = $now->copy()->subDay()->setTime(18, 0);
        }

        return RadioBulletin::firstOrCreate(['slot' => $slot->format('Y-m-d-H').($fresh ? '-r'.$now->format('His') : '')], [
            'title' => match ($slot->hour) {
                6 => 'The morning update', 12 => 'The midday update', default => 'The evening update'
            },
        ]);
    }

    public function work(): string
    {
        if (! RadioBulletin::available()) {
            return 'Radio is off or awaiting its migration.';
        }
        $lock = Cache::lock('radio-pipeline', 1800);
        if (! $lock->get()) {
            return 'Another radio worker is running.';
        }
        try {
            RadioBulletin::where('status', 'processing')->where('updated_at', '<', now()->subMinutes(30))
                ->update(['status' => 'failed', 'failure_reason' => 'Worker stopped before completing the edition.']);
            $bulletin = RadioBulletin::where(function ($q) {
                $q->where('status', 'queued')->orWhere(function ($q) {
                    $q->where('status', 'failed')->where('attempts', '<', config('radio.max_attempts'))
                        ->where('updated_at', '<=', now()->subMinutes(5));
                });
            })->orderByDesc('slot')->first();
            if (! $bulletin) {
                return 'No radio work due.';
            }
            if ($bulletin->slotTime()->lt(now('Africa/Lagos')->subHours(6))) {
                $bulletin->update(['status' => 'held', 'failure_reason' => 'Edition expired before completion. Queue the current edition.']);

                return 'Held an expired edition.';
            }
            $bulletin->update(['status' => 'processing', 'attempts' => $bulletin->attempts + 1, 'failure_reason' => null]);
            try {
                if ($bulletin->stage === 'script') {
                    $facts = app(RadioFacts::class)->collect();
                    $bulletin->update(['facts' => $facts, 'recorded_at' => now()]);
                    $segments = app(RadioScript::class)->write($facts);
                    $script = $this->compose($bulletin, $segments);
                    // Keep rejected candidates private but inspectable in the radio desk.
                    $bulletin->update(['segments' => $segments, 'script' => $script]);
                    app(RadioScript::class)->verify($segments, $facts);
                    $bulletin->update(['facts' => $facts, 'segments' => $segments, 'script' => $script,
                        'recorded_at' => now(), 'stage' => 'audio', 'attempts' => 0,
                        'status' => config('radio.auto_publish') ? 'queued' : 'draft']);
                } else {
                    $this->assertFresh($bulletin);
                    $audio = app(RadioAudio::class)->render($bulletin);
                    $bulletin->update($audio + ['status' => 'ready']);
                    if (config('radio.auto_publish')) {
                        $this->publish($bulletin);
                    }
                }

                return "Edition {$bulletin->slot}: {$bulletin->status}.";
            } catch (\Throwable $e) {
                // Provider payloads can contain secrets. Store a useful, bounded message only.
                $message = $e instanceof \Symfony\Component\Process\Exception\ProcessFailedException
                    ? 'Kokoro or FFmpeg failed. Run radio:check and check the server worker log.' : Str::limit($e->getMessage(), 500);
                $bulletin->update(['status' => 'failed', 'failure_reason' => $message]);
                Log::warning('Radio edition failed', ['id' => $bulletin->id, 'stage' => $bulletin->stage]);

                return "Edition {$bulletin->slot} failed: {$message}";
            }
        } finally {
            $lock->release();
        }
    }

    public function assertFresh(RadioBulletin $bulletin): void
    {
        if (! $bulletin->recorded_at || $bulletin->recorded_at->lt(now()->subHours(6))) {
            throw new RuntimeException('These facts are older than six hours. Hold this edition and queue a fresh one.');
        }
    }

    public function compose(RadioBulletin $bulletin, array $segments): string
    {
        return 'Welcome to Matchday Africa. Here is your '.Str::lower(str_replace('The ', '', $bulletin->title)).'.'
            ."\n\n".collect($segments)->pluck('text')->implode("\n\n")
            ."\n\nThat is your update. Join us again on Matchday Africa.";
    }

    public function publish(RadioBulletin $bulletin): void
    {
        $this->assertFresh($bulletin);
        if ($bulletin->status !== 'ready' || ! $bulletin->audioFile()) {
            throw new RuntimeException('Only an edition with checked, ready audio can be published.');
        }
        if (RadioBulletin::published()->where('slot', '>', $bulletin->slot)->exists()) {
            throw new RuntimeException('A newer edition is already published.');
        }
        $bulletin->update(['status' => 'published', 'published_at' => now(), 'failure_reason' => null]);
    }
}
