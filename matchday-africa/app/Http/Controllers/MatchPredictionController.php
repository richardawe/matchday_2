<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\PredictionSet;
use App\Models\PredictionSetMatch;
use App\Models\UserPrediction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatchPredictionController extends Controller
{
    public function store(Request $request, FootballMatch $match)
    {
        abort_unless($match->predictionsAreOpen(), 422, 'Predictions are closed because this match is already in play or complete.');
        $data = $request->validate([
            'home_score' => 'required|integer|min:0|max:20',
            'away_score' => 'required|integer|min:0|max:20',
        ]);

        DB::transaction(function () use ($request, $match, $data) {
            $set = PredictionSet::firstOrCreate(
                ['name' => 'Match #'.$match->id.' predictions'],
                ['description' => 'Open match prediction', 'admin_id' => $request->user()->id, 'status' => 'active', 'prediction_deadline' => $match->match_date, 'metadata' => ['system' => true, 'match_id' => $match->id]]
            );
            PredictionSetMatch::firstOrCreate(
                ['prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'score'],
                ['points_value' => 3]
            );
            UserPrediction::updateOrCreate(
                ['user_id' => $request->user()->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'score'],
                ['prediction_value' => $data['home_score'].'-'.$data['away_score'], 'home_score_prediction' => $data['home_score'], 'away_score_prediction' => $data['away_score'], 'submitted_at' => now(), 'is_correct' => null, 'is_scored' => false, 'points_earned' => 0]
            );
        });

        return back()->with('success', 'Your prediction is saved. You can edit it until the match goes live.');
    }
}
