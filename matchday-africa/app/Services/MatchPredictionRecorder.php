<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\PredictionSet;
use App\Models\PredictionSetMatch;
use App\Models\User;
use App\Models\UserPrediction;
use Illuminate\Support\Facades\DB;

/** Saves a score prediction into the match's open prediction round (one per match). */
class MatchPredictionRecorder
{
    public function record(User $user, FootballMatch $match, int $home, int $away, $submittedAt = null): UserPrediction
    {
        return DB::transaction(function () use ($user, $match, $home, $away, $submittedAt) {
            $set = PredictionSet::firstOrCreate(
                ['name' => 'Match #'.$match->id.' predictions'],
                ['description' => 'Open match prediction', 'admin_id' => $user->id, 'status' => 'active', 'prediction_deadline' => $match->match_date, 'metadata' => ['system' => true, 'match_id' => $match->id]]
            );
            PredictionSetMatch::firstOrCreate(
                ['prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'score'],
                ['points_value' => 3]
            );

            return UserPrediction::updateOrCreate(
                ['user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'score'],
                ['prediction_value' => $home.'-'.$away, 'home_score_prediction' => $home, 'away_score_prediction' => $away, 'submitted_at' => $submittedAt ?? now(), 'is_correct' => null, 'is_scored' => false, 'points_earned' => 0]
            );
        });
    }
}
