<?php

namespace App\Services\YouTube;

use App\Models\FootballMatch;
use App\Models\YoutubeAccount;
use App\Models\YoutubeBroadcast;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * One YouTube broadcast per matchday window: from 15 minutes before the first kick-off to the end of
 * the last match, for the competitions on stream (config/stream.php). Matches more than an hour apart
 * after the previous one has finished start a new window. Broadcasts start themselves when the
 * picture arrives (enableAutoStart) and are ended here at the end of their window.
 *
 * match_date holds UTC wall-clock values (AGENTS rule 5).
 */
class YouTubeBroadcasts
{
    public function __construct(private YouTubeClient $client) {}

    /**
     * Upcoming windows: [['key' => '2026-11-07', 'start' => Carbon, 'end' => Carbon, 'matches' => Collection], ...].
     */
    public function windows(?Carbon $now = null, ?int $hours = null): Collection
    {
        $now = ($now ?? now())->copy()->utc();
        $hours ??= (int) config('youtube.ahead_hours', 48);
        $before = (int) config('youtube.before_minutes', 15);
        $after = (int) config('youtube.after_minutes', 145);
        $codes = config('stream.competitions', []);
        $wall = fn (Carbon $at) => $at->format('Y-m-d H:i:s');

        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereHas('league', fn ($q) => $q->whereIn('short_code', $codes)->orWhereIn('espn_slug', $codes))
            ->whereNotIn('status', ['POSTPONED', 'CANCELLED', 'SUSPENDED'])
            ->whereBetween('match_date', [$wall($now->copy()->subMinutes($after)), $wall($now->copy()->addHours($hours))])
            ->orderBy('match_date')->get();

        $windows = collect();
        foreach ($matches as $match) {
            $kickoff = Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
            $last = $windows->last();
            if ($last && $kickoff->lte($last['end']->copy()->addHour())) {
                $last['end'] = $kickoff->copy()->addMinutes($after)->max($last['end']);
                $last['matches']->push($match);
                $windows->put($windows->keys()->last(), $last);
                continue;
            }
            $windows->push([
                'key' => $kickoff->copy()->setTimezone('Africa/Lagos')->format('Y-m-d').($last && $last['key'] === $kickoff->copy()->setTimezone('Africa/Lagos')->format('Y-m-d') ? '-'.$kickoff->format('Hi') : ''),
                'start' => $kickoff->copy()->subMinutes($before),
                'end' => $kickoff->copy()->addMinutes($after),
                'matches' => collect([$match]),
            ]);
        }

        return $windows->filter(fn ($w) => $w['end']->isAfter($now))->values();
    }

    /** Makes broadcasts for upcoming windows that don't have one, and ends finished ones. */
    public function run(?Carbon $now = null): array
    {
        $result = ['created' => 0, 'ended' => 0, 'errors' => []];
        $account = YouTubeClient::account();
        if (!$account || !YouTubeClient::configured()) {
            return $result;
        }
        if (config('youtube.auto')) {
            foreach ($this->windows($now) as $window) {
                if (YoutubeBroadcast::where('window_key', $window['key'])->exists()) {
                    continue;
                }
                try {
                    $this->schedule($account, $window);
                    $result['created']++;
                } catch (\Throwable $e) {
                    $result['errors'][] = $e->getMessage();
                    Log::warning('YouTube broadcast not created', ['window' => $window['key'], 'error' => $e->getMessage()]);
                }
            }
        }
        $result['ended'] = $this->finish($account, $now);

        return $result;
    }

    /** Creates the broadcast for one window and records it. */
    public function schedule(YoutubeAccount $account, array $window): YoutubeBroadcast
    {
        $start = $window['start']->copy()->max(now()->addMinute());
        $title = $this->title($window);
        $created = $this->client->createBroadcast($account, $title, $this->description($window), $start, $window['end'], config('youtube.privacy'));

        return YoutubeBroadcast::create([
            'broadcast_id' => $created['id'], 'window_key' => $window['key'], 'title' => $title,
            'starts_at' => $start, 'ends_at' => $window['end'], 'privacy' => config('youtube.privacy'), 'status' => 'scheduled',
        ]);
    }

    /** A private 30-minute broadcast starting now, to check the stream from OBS before a matchday. */
    public function test(YoutubeAccount $account): YoutubeBroadcast
    {
        $start = now()->addMinute();
        $end = now()->addMinutes(31);
        $title = 'Matchday Live test '.$start->copy()->setTimezone('Africa/Lagos')->format('j M H:i');
        $created = $this->client->createBroadcast($account, $title, 'A private test of the Matchday Africa live stream.', $start, $end, 'private');

        return YoutubeBroadcast::create([
            'broadcast_id' => $created['id'], 'window_key' => 'test-'.$start->format('YmdHis'), 'title' => $title,
            'starts_at' => $start, 'ends_at' => $end, 'privacy' => 'private', 'status' => 'scheduled',
        ]);
    }

    /** Ends broadcasts whose window is over (YouTube's auto-stop only fires when the picture stops). */
    public function finish(YoutubeAccount $account, ?Carbon $now = null): int
    {
        $now ??= now();
        $ended = 0;
        foreach (YoutubeBroadcast::whereIn('status', ['scheduled', 'live'])->where('ends_at', '<=', $now)->get() as $broadcast) {
            try {
                $state = $this->client->lifeCycle($account, $broadcast->broadcast_id);
                if (in_array($state, ['live', 'testing'], true)) {
                    $this->client->complete($account, $broadcast->broadcast_id);
                }
                $broadcast->update(['status' => $state === null || in_array($state, ['live', 'testing', 'complete'], true) ? 'complete' : 'missed']);
                $ended++;
            } catch (\Throwable $e) {
                $broadcast->update(['error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
        }

        return $ended;
    }

    public function title(array $window): string
    {
        $matches = $window['matches'];
        $day = $window['start']->copy()->setTimezone('Africa/Lagos')->format('D j M');
        $name = fn (FootballMatch $m) => trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) ($m->homeTeam?->name))).' v '.trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) ($m->awayTeam?->name)));

        return $matches->count() === 1
            ? 'LIVE: '.$name($matches->first()).' | '.$matches->first()->league?->name.' scores | Matchday Africa'
            : 'LIVE: '.$matches->first()->league?->name.' scores, '.$day.' | '.$matches->count().' matches | Matchday Africa';
    }

    public function description(array $window): string
    {
        $lines = $window['matches']->map(function (FootballMatch $m) {
            $kickoff = Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone('Africa/Lagos');

            return $kickoff->format('H:i').' '.trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $m->homeTeam?->name)).' v '.trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $m->awayTeam?->name));
        })->implode("\n");

        return "Live scores, goals and red cards as they happen, with the Griot.\n\n"
            ."Kick-offs (Lagos time):\n{$lines}\n\n"
            ."Follow every match, call the scores and Beat the Griot: https://matchday.africa\n"
            .'Scores and graphics only: no match footage.';
    }
}
