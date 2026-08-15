<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PredictionLeaderboard;
use App\Models\PredictionSeason;
use App\Models\PredictionSet;
use App\Models\UserPrediction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PredictionSeasonController extends Controller
{
    public function index(): View
    {
        return view('admin.predictions.season', [
            'currentSeason' => PredictionSeason::where('is_active', true)->latest('started_at')->first(),
            'seasons' => PredictionSeason::with('starter')->latest('started_at')->get(),
            'counts' => [
                'predictions' => UserPrediction::count(),
                'leaderboards' => PredictionLeaderboard::count(),
                'prediction_sets' => PredictionSet::where('status', '!=', 'archived')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'confirmation' => ['required', 'in:START NEW SEASON'],
            'acknowledge_deletion' => ['accepted'],
        ], [
            'confirmation.in' => 'Type START NEW SEASON exactly to confirm.',
            'acknowledge_deletion.accepted' => 'You must acknowledge that prediction records will be permanently deleted.',
        ]);

        $season = DB::transaction(function () use ($validated) {
            $predictionCount = UserPrediction::count();
            $leaderboardCount = PredictionLeaderboard::count();
            $setCount = PredictionSet::where('status', '!=', 'archived')->count();

            PredictionSeason::where('is_active', true)->update(['is_active' => false, 'ended_at' => now()]);
            PredictionLeaderboard::query()->delete();
            UserPrediction::query()->delete();
            PredictionSet::where('status', '!=', 'archived')->update(['status' => 'archived']);

            return PredictionSeason::create([
                'name' => $validated['name'],
                'started_at' => now(),
                'started_by' => auth()->id(),
                'is_active' => true,
                'cleared_predictions' => $predictionCount,
                'cleared_leaderboard_entries' => $leaderboardCount,
                'archived_prediction_sets' => $setCount,
            ]);
        });

        Log::warning('Prediction season reset completed', [
            'season_id' => $season->id,
            'season_name' => $season->name,
            'admin_id' => auth()->id(),
            'cleared_predictions' => $season->cleared_predictions,
            'cleared_leaderboard_entries' => $season->cleared_leaderboard_entries,
            'archived_prediction_sets' => $season->archived_prediction_sets,
        ]);

        return redirect()->route('admin.predictions.season.index')
            ->with('success', "{$season->name} started. Previous prediction records were cleared.");
    }
}
