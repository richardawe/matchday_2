<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\GuestPrediction;
use App\Models\PredictionGroup;
use App\Models\User;
use App\Models\UserPrediction;
use App\Support\Attribution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Predictions made before signing up. A visitor gets an encrypted md_guest cookie; their picks
 * wait in guest_predictions and move onto the account at the next login or signup, together
 * with any league invite they opened.
 */
class GuestPredictions
{
    public const COOKIE = 'md_guest';
    public const LEAGUE_SESSION = 'pending_league_code';

    public function __construct(private MatchPredictionRecorder $recorder) {}

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('guest_predictions');
    }

    public function token(Request $request): string
    {
        $token = (string) $request->cookie(self::COOKIE);
        if (!Str::isUuid($token)) {
            $token = (string) Str::uuid();
        }
        Cookie::queue(self::COOKIE, $token, 60 * 24 * 365, null, null, null, true, false, 'lax');

        return $token;
    }

    public function store(Request $request, FootballMatch $match, int $home, int $away): GuestPrediction
    {
        return GuestPrediction::updateOrCreate(
            ['guest_token' => $this->token($request), 'match_id' => $match->id],
            ['home_score' => $home, 'away_score' => $away, 'utm_source' => Attribution::read($request)['utm_source'] ?? null]
        );
    }

    public function current(Request $request, FootballMatch $match): ?GuestPrediction
    {
        $token = (string) $request->cookie(self::COOKIE);

        return self::ready() && Str::isUuid($token)
            ? GuestPrediction::where('guest_token', $token)->where('match_id', $match->id)->whereNull('claimed_at')->first()
            : null;
    }

    /**
     * Moves the visitor's unclaimed picks onto the account. A pick the user already made while
     * signed in wins; a guest pick keeps the time it was made, so it counts even if the match has
     * kicked off since (and keeps its place in a prize race). Returns how many picks moved.
     */
    public function claim(User $user, Request $request): int
    {
        $this->joinPendingLeague($user, $request);
        $token = (string) $request->cookie(self::COOKIE);
        if (!self::ready() || !Str::isUuid($token)) {
            return 0;
        }
        $moved = 0;
        foreach (GuestPrediction::with('match')->where('guest_token', $token)->whereNull('claimed_at')->get() as $pick) {
            // Guest picks are only accepted while predictions are open, so each one counts.
            $exists = UserPrediction::where('user_id', $user->id)->where('match_id', $pick->match_id)->where('prediction_type', 'score')->exists();
            if ($pick->match && !$exists) {
                $this->recorder->record($user, $pick->match, $pick->home_score, $pick->away_score, $pick->updated_at);
                $moved++;
            }
            $pick->update(['claimed_by' => $user->id, 'claimed_at' => now()]);
        }

        return $moved;
    }

    private function joinPendingLeague(User $user, Request $request): void
    {
        if (!$request->hasSession() || !($code = $request->session()->pull(self::LEAGUE_SESSION))) {
            return;
        }
        if ($group = PredictionGroup::where('code', $code)->first()) {
            $group->members()->syncWithoutDetaching($user->id);
            $request->session()->flash('success', 'You joined '.$group->name.'.');
        }
    }
}
