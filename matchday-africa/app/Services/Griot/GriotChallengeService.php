<?php

namespace App\Services\Griot;

use App\Models\FootballMatch;
use App\Models\GriotChallenge;
use App\Models\GriotChallengeEntry;
use App\Models\GriotChallengeMatch;
use App\Models\GriotChallengeWinner;
use App\Models\GuestPrediction;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserPrediction;
use App\Notifications\InboxMessage;
use App\Services\Alerts\Alerts;
use App\Services\GuestPredictions;
use App\Services\MatchPredictionRecorder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Beat the Griot, run by `griot:challenge` every ten minutes:
 *
 *  1. plan  - when no round is open, the next Premier League round's three most interesting
 *             matches (on different days where possible) get the Griot's calls; fans with alerts
 *             on hear about it.
 *  2. remind - three hours before the first kick-off, fans with alerts on who have calls missing.
 *  3. score - once all three are confirmed finished: 3 points for the exact score, 1 for the
 *             result; fans with more points than the Griot beat it, and ten of them are drawn
 *             at random for the prize. Winners quote their code to a Matchday social account
 *             and an admin credits the data by hand (/admin/griot).
 *
 * Fans' calls are ordinary score predictions (user_predictions / guest_predictions), so a call
 * made on the match page counts here too. A call counts only if it was made before kick-off.
 * Kick-offs: matches.match_date holds UTC wall-clock values (AGENTS.md rule 5).
 */
class GriotChallengeService
{
    private const WAITING = ['SCHEDULED', 'TIMED', 'NS', 'PRE'];
    private const VOID = ['POSTPONED', 'CANCELLED', 'SUSPENDED', 'ABANDONED'];

    public function __construct(
        private GriotForecaster $forecaster,
        private MatchPredictionRecorder $recorder,
        private GuestPredictions $guests,
        private Alerts $alerts,
    ) {}

    public static function tablesReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('griot_challenges') && Schema::hasTable('griot_challenge_winners');
    }

    /** On (GRIOT_CHALLENGE) and migrated. */
    public static function ready(): bool
    {
        return config('griot_challenge.enabled') && self::tablesReady();
    }

    public function open(): ?GriotChallenge
    {
        return GriotChallenge::with('picks.match.homeTeam', 'picks.match.awayTeam')->where('status', 'open')->latest('id')->first();
    }

    public function lastScored(): ?GriotChallenge
    {
        return GriotChallenge::with('picks.match.homeTeam', 'picks.match.awayTeam', 'winners.user')->where('status', 'scored')->latest('id')->first();
    }

    /** @return array{planned: ?int, reminded: int, scored: ?int} */
    public function run(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $result = ['planned' => null, 'reminded' => 0, 'scored' => null];
        if ($open = $this->open()) {
            $result['reminded'] = $this->remind($open, $now);
            if ($this->score($open, $now)) {
                $result['scored'] = $open->id;
            }
        }
        if (!$this->open() && ($planned = $this->plan($now))) {
            $result['planned'] = $planned->id;
        }

        return $result;
    }

    // ------------------------------------------------------------------ plan

    /** Plans the next round, or returns null when one is open or no round is far enough off. */
    public function plan(?Carbon $now = null): ?GriotChallenge
    {
        $now = ($now ?? now())->copy()->utc();
        if (GriotChallenge::where('status', 'open')->exists()) {
            return null;
        }
        $earliest = $now->copy()->addHours((int) config('griot_challenge.min_lead_hours'));
        $leagues = config('griot_challenge.leagues');
        $upcoming = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->whereHas('league', fn ($q) => $q->whereIn('short_code', $leagues)->orWhereIn('espn_slug', $leagues))
            ->whereIn('status', self::WAITING)
            ->whereBetween('match_date', [$earliest->format('Y-m-d H:i:s'), $now->copy()->addDays(8)->format('Y-m-d H:i:s')])
            ->orderBy('match_date')->get()
            // Never reuse a match an earlier round already had.
            ->reject(fn ($m) => GriotChallengeMatch::where('match_id', $m->id)->exists())
            ->values();
        if ($upcoming->isEmpty()) {
            return null;
        }
        // One round: from the earliest fixture to four days after it (a weekend or a midweek round).
        $start = $this->kickoff($upcoming->first());
        $round = $upcoming->filter(fn ($m) => $this->kickoff($m)->lte($start->copy()->addDays(4)))->values();
        $count = (int) config('griot_challenge.matches');
        if ($round->count() < $count) {
            return null;
        }
        $chosen = $this->choose($round, $count);

        $challenge = DB::transaction(function () use ($chosen) {
            $challenge = GriotChallenge::create([
                'status' => 'open',
                'first_kickoff' => $chosen->min(fn ($m) => $m->match_date->format('Y-m-d H:i:s')),
                'last_kickoff' => $chosen->max(fn ($m) => $m->match_date->format('Y-m-d H:i:s')),
            ]);
            foreach ($chosen->sortBy(fn ($m) => $m->match_date->format('Y-m-d H:i:s'))->values() as $i => $match) {
                $call = $this->forecaster->forecast($match);
                $challenge->picks()->create([
                    'match_id' => $match->id, 'position' => $i + 1,
                    'home_pick' => $call['home'], 'away_pick' => $call['away'], 'facts' => $call['facts'],
                ]);
            }

            return $challenge;
        });
        $challenge->load('picks.match.homeTeam', 'picks.match.awayTeam');
        $this->announce($challenge);
        Log::info('Beat the Griot planned', ['challenge' => $challenge->id, 'matches' => $chosen->pluck('id')->all()]);

        return $challenge;
    }

    /**
     * The round's most interesting matches: clubs close in the table, big clubs (more so when both
     * are), and how many of our fans already follow or predicted the match. Different days first,
     * so the challenge runs all round.
     */
    private function choose(Collection $round, int $count): Collection
    {
        $big = config('griot_challenge.big_clubs');
        $positions = DB::table('standings')->where('is_current', true)->pluck('position', 'team_id');
        $follows = Schema::hasTable('match_alerts')
            ? DB::table('match_alerts')->whereIn('match_id', $round->pluck('id'))->groupBy('match_id')->selectRaw('match_id, count(*) as n')->pluck('n', 'match_id')
            : collect();
        $predicted = UserPrediction::whereIn('match_id', $round->pluck('id'))->groupBy('match_id')->selectRaw('match_id, count(*) as n')->pluck('n', 'match_id');

        $scored = $round->map(function (FootballMatch $m) use ($big, $positions, $follows, $predicted) {
            $bigs = collect([$m->homeTeam, $m->awayTeam])->filter(fn ($t) => in_array(\App\Support\Armies::key((string) $t?->name), $big, true))->count();
            $score = $bigs * 6 + ($bigs === 2 ? 8 : 0);
            $ph = $positions[$m->home_team_id] ?? null;
            $pa = $positions[$m->away_team_id] ?? null;
            if ($ph && $pa) {
                $score += max(0, 10 - abs($ph - $pa)) + max(0, 10 - min($ph, $pa)) / 2;
            }
            $score += log(1 + ($follows[$m->id] ?? 0) + ($predicted[$m->id] ?? 0)) * 3;

            return ['match' => $m, 'score' => $score];
        })->sortByDesc('score')->values();

        $chosen = collect();
        $days = [];
        foreach ($scored as $row) {
            $day = $this->kickoff($row['match'])->setTimezone('Africa/Lagos')->toDateString();
            if ($chosen->count() < $count && !in_array($day, $days, true)) {
                $chosen->push($row['match']);
                $days[] = $day;
            }
        }
        foreach ($scored as $row) {
            if ($chosen->count() < $count && !$chosen->contains('id', $row['match']->id)) {
                $chosen->push($row['match']);
            }
        }

        return $chosen;
    }

    private function announce(GriotChallenge $challenge): void
    {
        $first = $challenge->picks->first();
        $line = $first ? $this->club($first->match->homeTeam).' '.$first->call().' '.$this->club($first->match->awayTeam) : '';
        $this->alerts->deliver($this->alertUsers(), new InboxMessage(
            'griot_challenge',
            'The Griot has made its calls',
            "Three matches, three scores. {$line} is one of them. Beat the Griot and you could win ".config('griot_challenge.prize').'.',
            route('griot.challenge'),
        ), true, 'griot-challenge-'.$challenge->id);
    }

    // ------------------------------------------------------------------ play

    /** The fan's calls for the round: [match_id => [home, away]]. */
    public function callsFor(GriotChallenge $challenge, ?User $user, Request $request): array
    {
        $ids = $challenge->picks->pluck('match_id');
        if ($user) {
            return UserPrediction::where('user_id', $user->id)->whereIn('match_id', $ids)->where('prediction_type', 'score')
                ->orderBy('submitted_at')->get()
                ->mapWithKeys(fn ($p) => [$p->match_id => [(int) $p->home_score_prediction, (int) $p->away_score_prediction]])->all();
        }
        $token = (string) $request->cookie(GuestPredictions::COOKIE);
        if (!GuestPredictions::ready() || !Str::isUuid($token)) {
            return [];
        }

        return GuestPrediction::where('guest_token', $token)->whereIn('match_id', $ids)->whereNull('claimed_at')->get()
            ->mapWithKeys(fn ($p) => [$p->match_id => [$p->home_score, $p->away_score]])->all();
    }

    /** Saves the calls for matches that haven't kicked off. Returns how many were saved. */
    /**
     * Fans with an account who made calls in a round, most calls first:
     * [['user' => User, 'calls' => 0-3, 'last' => Carbon], ...]. Entries only exist once a round
     * is scored, so this reads the calls themselves.
     */
    public function callers(GriotChallenge $challenge): Collection
    {
        $ids = $challenge->picks->pluck('match_id');
        $rows = UserPrediction::whereIn('match_id', $ids)->where('prediction_type', 'score')
            ->whereNotNull('home_score_prediction')->whereNotNull('away_score_prediction')
            ->selectRaw('user_id, count(distinct match_id) as calls, max(coalesce(submitted_at, updated_at)) as last_at')
            ->groupBy('user_id')->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows->filter(fn ($r) => $users->has($r->user_id))
            ->map(fn ($r) => ['user' => $users[$r->user_id], 'calls' => (int) $r->calls, 'last' => $r->last_at ? Carbon::parse($r->last_at) : null])
            ->sortByDesc(fn ($r) => sprintf('%d%s', $r['calls'], $r['last']?->format('YmdHis') ?? ''))->values();
    }

    /** Visitors without an account who made calls in a round (they can't get inbox messages). */
    public function guestCallers(GriotChallenge $challenge): int
    {
        if (!GuestPredictions::ready()) {
            return 0;
        }

        return GuestPrediction::whereIn('match_id', $challenge->picks->pluck('match_id'))->whereNull('claimed_at')
            ->distinct()->count('guest_token');
    }

    /** Everyone with an account who has made a call in any round of Beat the Griot. */
    public static function everyCaller()
    {
        return User::whereIn('id', UserPrediction::where('prediction_type', 'score')
            ->whereIn('match_id', GriotChallengeMatch::select('match_id'))->select('user_id'));
    }

    public function submit(GriotChallenge $challenge, ?User $user, Request $request, array $calls): int
    {
        $saved = 0;
        foreach ($challenge->picks as $pick) {
            $call = $calls[$pick->match_id] ?? null;
            if (!is_array($call) || !isset($call['home'], $call['away']) || !$this->callable($pick->match)) {
                continue;
            }
            [$home, $away] = [max(0, min(20, (int) $call['home'])), max(0, min(20, (int) $call['away']))];
            if ($user) {
                $this->recorder->record($user, $pick->match, $home, $away);
            } elseif (GuestPredictions::ready()) {
                $this->guests->store($request, $pick->match, $home, $away);
            } else {
                continue;
            }
            $saved++;
        }

        return $saved;
    }

    public function callable(?FootballMatch $match): bool
    {
        return $match && $match->predictionsAreOpen() && $this->kickoff($match)->isFuture();
    }

    // ------------------------------------------------------------------ remind

    private function remind(GriotChallenge $challenge, Carbon $now): int
    {
        $first = $challenge->firstKickoffUtc();
        if (data_get($challenge->metadata, 'reminded_at') || $now->lt($first->copy()->subHours(3)) || $now->gte($first)) {
            return 0;
        }
        $challenge->update(['metadata' => array_merge($challenge->metadata ?? [], ['reminded_at' => $now->toIso8601String()])]);
        $ids = $challenge->picks->pluck('match_id');
        $complete = UserPrediction::whereIn('match_id', $ids)->where('prediction_type', 'score')
            ->groupBy('user_id')->havingRaw('count(distinct match_id) >= ?', [$ids->count()])->pluck('user_id');
        $users = $this->alertUsers()->reject(fn ($u) => $complete->contains($u->id));

        return $this->alerts->deliver($users, new InboxMessage(
            'griot_challenge',
            'Your calls against the Griot',
            'The first of the Griot\'s three matches kicks off in three hours. Make your calls before kick-off.',
            route('griot.challenge'),
        ), true, 'griot-challenge-'.$challenge->id)['inbox'];
    }

    // ------------------------------------------------------------------ score

    /** 3 for the exact score, 1 for the right result. */
    public static function points(int $callHome, int $callAway, int $home, int $away): int
    {
        if ($callHome === $home && $callAway === $away) {
            return 3;
        }

        return ($callHome <=> $callAway) === ($home <=> $away) ? 1 : 0;
    }

    /** Scores the round once every match is finished (or void); returns false while it waits. */
    public function score(GriotChallenge $challenge, ?Carbon $now = null): bool
    {
        $now = ($now ?? now())->copy()->utc();
        $challenge->loadMissing('picks.match');
        // A match postponed (or still unsettled three days after the round's last kick-off) is void.
        $giveUp = $now->gte($challenge->lastKickoffUtc()->addDays(3));
        $counted = collect();
        foreach ($challenge->picks as $pick) {
            $match = $pick->match;
            $void = !$match || in_array(strtoupper((string) $match->status), self::VOID, true);
            if (!$void && $match->isFinished() && !$match->statusInferred()) {
                $counted->push($pick);
            } elseif (!$void && !$giveUp) {
                return false;
            }
        }
        $griot = $counted->sum(fn ($p) => self::points($p->home_pick, $p->away_pick, (int) $p->match->home_score, (int) $p->match->away_score));

        $entries = $this->entries($counted, $griot);
        DB::transaction(function () use ($challenge, $entries, $griot) {
            foreach ($entries as $entry) {
                GriotChallengeEntry::updateOrCreate(
                    ['griot_challenge_id' => $challenge->id, 'user_id' => $entry['user_id']],
                    ['points' => $entry['points'], 'beat' => $entry['beat'], 'picks' => $entry['picks']]
                );
            }
            $beaters = $entries->where('beat', true);
            foreach ($beaters->shuffle()->take((int) config('griot_challenge.winners')) as $entry) {
                GriotChallengeWinner::firstOrCreate(
                    ['griot_challenge_id' => $challenge->id, 'user_id' => $entry['user_id']],
                    ['code' => $this->code()]
                );
            }
            $challenge->update([
                'status' => 'scored', 'griot_points' => $griot, 'players' => $entries->count(),
                'beaters' => $beaters->count(), 'scored_at' => now(),
            ]);
        });
        $this->tell($challenge->fresh(['winners', 'entries']));
        Log::info('Beat the Griot scored', ['challenge' => $challenge->id, 'griot' => $griot, 'players' => $entries->count()]);

        return true;
    }

    /**
     * Every account that called all the counted matches before kick-off.
     *
     * @return Collection<int, array{user_id: int, points: int, beat: bool, picks: array}>
     */
    private function entries(Collection $counted, int $griot): Collection
    {
        if ($counted->isEmpty()) {
            return collect();
        }
        $kickoffs = $counted->mapWithKeys(fn ($p) => [$p->match_id => $this->kickoff($p->match)]);
        $calls = UserPrediction::whereIn('match_id', $counted->pluck('match_id'))->where('prediction_type', 'score')
            ->whereNotNull('home_score_prediction')->whereNotNull('away_score_prediction')
            ->orderBy('submitted_at')->get()
            // submitted_at is app time (Lagos); compare instants.
            ->filter(fn ($p) => $p->submitted_at && $p->submitted_at->getTimestamp() < $kickoffs[$p->match_id]->getTimestamp())
            ->groupBy('user_id');

        return $calls->map(function (Collection $byUser, $userId) use ($counted, $griot) {
            $latest = $byUser->keyBy('match_id'); // ordered by submitted_at, so the last call wins
            if ($latest->count() < $counted->count()) {
                return null;
            }
            $picks = [];
            $points = 0;
            foreach ($counted as $pick) {
                $call = $latest[$pick->match_id];
                $p = self::points((int) $call->home_score_prediction, (int) $call->away_score_prediction, (int) $pick->match->home_score, (int) $pick->match->away_score);
                $points += $p;
                $picks[$pick->match_id] = [(int) $call->home_score_prediction, (int) $call->away_score_prediction, $p];
            }

            return ['user_id' => (int) $userId, 'points' => $points, 'beat' => $points > $griot, 'picks' => $picks];
        })->filter()->values();
    }

    private function tell(GriotChallenge $challenge): void
    {
        $winners = $challenge->winners->keyBy('user_id');
        $prize = config('griot_challenge.prize');
        $accounts = collect(config('social.accounts'))->only(['x', 'instagram'])->map(fn ($a) => $a['handle'].' on '.$a['name'])->implode(' or ');
        $groups = $challenge->entries->groupBy(fn ($e) => $winners->has($e->user_id) ? 'won' : ($e->beat ? 'beat' : 'lost'));
        $users = User::whereIn('id', $challenge->entries->pluck('user_id'))->get()->keyBy('id');
        $url = route('griot.challenge');

        foreach ($groups->get('won', collect()) as $entry) {
            $code = $winners[$entry->user_id]->code;
            $this->alerts->deliver(collect([$users[$entry->user_id] ?? null]), new InboxMessage(
                'griot_winner',
                "You won {$prize}!",
                "You beat the Griot ({$entry->points} to {$challenge->griot_points}) and you're one of this week's winners. Send a message to {$accounts} with your code {$code}, your phone number and network (".config('griot_challenge.country').', '.implode(', ', config('griot_challenge.networks')).'). We credit your data by hand within a day.',
                $url, ['code' => $code],
            ), true, 'griot-winner-'.$challenge->id);
        }
        $beat = $groups->get('beat', collect())->map(fn ($e) => $users[$e->user_id] ?? null);
        $this->alerts->deliver($beat, new InboxMessage('griot_challenge', 'You beat the Griot!',
            "You scored more than the Griot this round. You weren't drawn for the data this time; the next three calls are up.", $url), true, 'griot-result-'.$challenge->id);
        $lost = $groups->get('lost', collect())->map(fn ($e) => $users[$e->user_id] ?? null);
        $this->alerts->deliver($lost, new InboxMessage('griot_challenge', 'The Griot won this round',
            "The Griot scored {$challenge->griot_points}. The next three calls are up: try again.", $url), true, 'griot-result-'.$challenge->id);
    }

    // ------------------------------------------------------------------ helpers

    private function alertUsers(): Collection
    {
        if (!$this->alerts->ready()) {
            return collect();
        }

        return User::whereIn('id', PushSubscription::query()->distinct()->pluck('user_id'))->get();
    }

    private function code(): string
    {
        do {
            $code = 'GRIOT-'.strtoupper(Str::random(5));
        } while (GriotChallengeWinner::where('code', $code)->exists());

        return $code;
    }

    public function kickoff(FootballMatch $match): Carbon
    {
        return Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
    }

    private function club($team): string
    {
        return \App\Support\ClubName::short($team);
    }

    /** "Kemi A." for public winner lists. */
    public static function publicName(?User $user): string
    {
        if (!$user) {
            return 'A fan';
        }
        if ($user->username) {
            return '@'.$user->username;
        }
        $parts = preg_split('/\s+/', trim((string) $user->name));

        return trim(($parts[0] ?? 'Fan').(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'.' : ''));
    }
}
