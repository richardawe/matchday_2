<?php

namespace App\Services\Alerts;

use App\Models\FootballMatch;
use App\Models\MatchAlert;
use App\Models\MatchEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\InboxMessage;
use App\Services\ReelService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Alert me" on any match: a kick-off reminder, kick-off, every goal, red cards, half-time, full
 * time and the reel, each sent once per match (match_alert_log) to the users who asked, in their
 * inbox and by push. Runs every minute (alerts:scan) from the scores the live syncs keep.
 */
class MatchAlerts
{
    private const HALF_TIME = ['PAUSED', 'HT'];
    private const FULL_TIME = ['FINISHED', 'FT'];

    public function __construct(private Alerts $alerts) {}

    /** Turns an alert on. A match already under way is caught up silently: no burst of old goals. */
    public function follow(User $user, FootballMatch $match): MatchAlert
    {
        $alert = MatchAlert::firstOrCreate(['user_id' => $user->id, 'match_id' => $match->id]);
        foreach ($this->events($match) as $event) {
            if ($event['key'] !== 'reminder' || $this->started($match)) {
                $this->claim($match, $event['key'], silent: true);
            }
        }

        return $alert;
    }

    public function unfollow(User $user, FootballMatch $match): void
    {
        MatchAlert::where(['user_id' => $user->id, 'match_id' => $match->id])->delete();
    }

    /** Sends what is new on every followed match. Returns how many alerts went out. */
    public function scan(): int
    {
        if (!$this->alerts->ready()) {
            return 0;
        }
        $from = now('UTC')->subHours(8)->format('Y-m-d H:i:s');
        $to = now('UTC')->addMinutes(config('push.reminder_minutes') + 5)->format('Y-m-d H:i:s');
        $sent = 0;
        FootballMatch::with(['homeTeam', 'awayTeam'])->whereBetween('match_date', [$from, $to])
            ->whereIn('id', MatchAlert::select('match_id'))->get()
            ->each(function (FootballMatch $match) use (&$sent) {
                foreach ($this->events($match) as $event) {
                    if (!$this->claim($match, $event['key'])) {
                        continue;
                    }
                    $users = $this->recipients($match);
                    $result = $this->alerts->deliver($users, new InboxMessage('match_alert', $event['title'], $event['body'],
                        route('matches.show', $match), ['match_id' => $match->id, 'event' => $event['key']]), topic: "match-{$match->id}");
                    DB::table('match_alert_log')->where(['match_id' => $match->id, 'key' => $event['key']])->update(['recipients' => $result['inbox']]);
                    $sent += $result['inbox'] ? 1 : 0;
                }
            });

        return $sent;
    }

    /**
     * What there is to say about a match right now, oldest first. Keys are stable, so each goes
     * out once: reminder, kickoff, score:2-1, red:<event id>, half_time, full_time, reel.
     *
     * @return array<int, array{key: string, title: string, body: string}>
     */
    public function events(FootballMatch $match): array
    {
        $home = $this->club($match->homeTeam?->name, 'Home');
        $away = $this->club($match->awayTeam?->name, 'Away');
        $score = fn ($h, $a) => "{$home} {$h}–{$a} {$away}";
        $kickoff = Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
        $events = [];

        if (!$this->started($match)) {
            if ($kickoff->isFuture() && $kickoff->lte(now('UTC')->addMinutes(config('push.reminder_minutes')))) {
                $events[] = ['key' => 'reminder', 'title' => "{$home} v {$away} soon", 'body' => 'Kick-off in '.max(1, (int) ceil(now('UTC')->diffInMinutes($kickoff))).' minutes. Get your prediction in.'];
            }

            return $events;
        }
        $events[] = ['key' => 'kickoff', 'title' => 'Kick-off!', 'body' => "{$home} v {$away} is under way."];

        $h = (int) $match->home_score;
        $a = (int) $match->away_score;
        if ($h + $a > 0) {
            // The latest goal the feed has named, for the scorer. A score the feed corrected
            // downwards (a goal ruled out) is not announced again when it is restored.
            $goal = MatchEvent::where('match_id', $match->id)->where('type', 'goal')->orderByDesc('minute')->orderByDesc('id')->first();
            $who = $goal?->player_name ? Str::of($goal->player_name)->trim().($goal->minute ? " {$goal->minute}′" : '').'. ' : '';
            $events[] = ['key' => "score:{$h}-{$a}", 'title' => 'GOAL! '.$score($h, $a), 'body' => $who.'Follow it live on Matchday Africa.'];
        }
        MatchEvent::where('match_id', $match->id)->where('type', 'red_card')->orderBy('id')->get()
            ->each(function (MatchEvent $card) use (&$events, $match, $home, $away) {
                $side = $card->team_id === $match->away_team_id ? $away : $home;
                $events[] = ['key' => "red:{$card->id}", 'title' => "Red card! {$side} down to ten",
                    'body' => trim(($card->player_name ? "{$card->player_name} is sent off" : 'A player is sent off').($card->minute ? " ({$card->minute}′)." : '.'))];
            });
        if (in_array($match->status, self::HALF_TIME, true)) {
            $events[] = ['key' => 'half_time', 'title' => 'Half-time: '.$score($h, $a), 'body' => 'The second half is coming. Stay with it.'];
        }
        if (in_array($match->status, self::FULL_TIME, true) && !$match->statusInferred()) {
            $verdict = $h === $a ? 'Honours even.' : (($h > $a ? $home : $away).' take it.');
            $events[] = ['key' => 'full_time', 'title' => 'Full time: '.$score($h, $a), 'body' => "{$verdict} The full story is on Matchday Africa."];
            if (app(ReelService::class)->url($match)) {
                $events[] = ['key' => 'reel', 'title' => "The reel is out: {$home} v {$away}", 'body' => 'The whole match, told by the Griot. Watch it now.'];
            }
        }

        return $events;
    }

    private function started(FootballMatch $match): bool
    {
        return in_array($match->status, FootballMatch::LIVE_STATUSES, true) || in_array($match->status, self::FULL_TIME, true);
    }

    /** Records an event as said; false when it already was (another run, or caught up silently). */
    private function claim(FootballMatch $match, string $key, bool $silent = false): bool
    {
        try {
            DB::table('match_alert_log')->insert(['match_id' => $match->id, 'key' => $key, 'recipients' => 0, 'created_at' => now(), 'updated_at' => now()]);

            return !$silent;
        } catch (QueryException) {
            return false;
        }
    }

    /** Users who asked for this match, minus those who switched match alerts off in their settings. */
    private function recipients(FootballMatch $match)
    {
        $muted = NotificationPreference::where('match_alerts', false)->pluck('user_id');

        return User::whereIn('id', MatchAlert::where('match_id', $match->id)->select('user_id'))->whereNotIn('id', $muted)->get();
    }

    private function club(?string $name, string $fallback): string
    {
        return trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $name)) ?: $fallback;
    }
}
