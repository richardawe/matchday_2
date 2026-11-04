<?php

namespace App\Http\Controllers;

use App\Models\GriotChallenge;
use App\Services\Griot;
use App\Services\Griot\ChallengeCardRenderer;
use App\Services\Griot\ChallengeLines;
use App\Services\Griot\GriotChallengeService;
use Illuminate\Http\Request;

/** Beat the Griot: the open round's three calls, the fan's own calls, and last round's verdict. */
class GriotChallengeController extends Controller
{
    public function show(Request $request, GriotChallengeService $service, ChallengeLines $lines)
    {
        abort_unless(GriotChallengeService::ready(), 404);
        $open = $service->open();
        $last = $service->lastScored();
        $voice = Griot::voiceFor($request);
        $user = $request->user();

        return view('griot.challenge', [
            'open' => $open,
            'last' => $last,
            'voice' => $voice,
            'lines' => $lines,
            'service' => $service,
            'calls' => $open ? $service->callsFor($open, $user, $request) : [],
            'lastEntry' => $last && $user ? $last->entries()->where('user_id', $user->id)->first() : null,
            'myWin' => $last && $user ? $last->winners->firstWhere('user_id', $user->id) : null,
        ]);
    }

    public function store(Request $request, GriotChallengeService $service)
    {
        abort_unless(GriotChallengeService::ready(), 404);
        $open = $service->open();
        abort_unless($open, 422, 'There is no open round right now.');
        $data = $request->validate([
            'calls' => 'required|array',
            'calls.*.home' => 'required|integer|min:0|max:20',
            'calls.*.away' => 'required|integer|min:0|max:20',
        ]);
        $saved = $service->submit($open, $request->user(), $request, $data['calls']);
        if (!$request->user()) {
            // Signing up from the prompt brings the visitor back here with the calls saved.
            $request->session()->put('url.intended', route('griot.challenge'));
        }

        return redirect()->route('griot.challenge')->with('griot_saved', $saved);
    }

    public function card(GriotChallenge $challenge, string $kind, ChallengeCardRenderer $cards)
    {
        abort_unless(GriotChallengeService::tablesReady(), 404);
        abort_if($kind === 'verdict' && $challenge->status !== 'scored', 404);
        $challenge->load('picks.match.homeTeam', 'picks.match.awayTeam');

        return response()->file($cards->cardPath($challenge, $kind), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
