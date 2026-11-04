<?php

namespace App\Http\Controllers;

use App\Services\Griot\GriotChallengeService;
use App\Services\PredictionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /predictions now leads to Beat the Griot (or the fixtures while it is off); the fan's own
 * record stays at /predictions/history. Single-match calls are made on match pages.
 */
class PredictionController extends Controller
{
    public function index()
    {
        return redirect()->route(GriotChallengeService::ready() ? 'griot.challenge' : 'matches.index', [], 301);
    }

    public function history(Request $request, PredictionService $predictions): View
    {
        $filters = $request->only(['date_from', 'date_to', 'is_correct']);

        return view('predictions.history', [
            'predictions' => $predictions->getUserPredictionHistory($request->user(), $filters),
            'userStats' => $predictions->getUserStats($request->user()),
            'filters' => $filters,
        ]);
    }
}
