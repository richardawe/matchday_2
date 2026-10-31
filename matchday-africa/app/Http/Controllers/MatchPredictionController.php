<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Services\GuestPredictions;
use App\Services\MatchPredictionRecorder;
use Illuminate\Http\Request;

class MatchPredictionController extends Controller
{
    /** Signed-in fans save straight to their account; visitors save a guest pick and are asked to join. */
    public function store(Request $request, FootballMatch $match, MatchPredictionRecorder $recorder, GuestPredictions $guests)
    {
        abort_unless($match->predictionsAreOpen(), 422, 'Predictions are closed because this match is already in play or complete.');
        $data = $request->validate([
            'home_score' => 'required|integer|min:0|max:20',
            'away_score' => 'required|integer|min:0|max:20',
        ]);

        if ($user = $request->user()) {
            $recorder->record($user, $match, (int) $data['home_score'], (int) $data['away_score']);

            return back()->with('success', 'Your prediction is saved. You can edit it until the match goes live.');
        }

        abort_unless(GuestPredictions::ready(), 403);
        $guests->store($request, $match, (int) $data['home_score'], (int) $data['away_score']);
        // Signing up from the prompt brings the visitor back to this match with the pick saved.
        $request->session()->put('url.intended', route('matches.show', $match).'#prediction-arena');

        return redirect(route('matches.show', $match).'#prediction-arena')->with('guest_pick', true);
    }
}
