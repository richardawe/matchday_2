<?php

namespace App\Http\Controllers;

use App\Services\PredictionService;
use App\Services\PredictionAnalyticsService;
use App\Models\PredictionSet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

class PredictionController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

    public function __construct(
        private PredictionService $predictionService,
        private PredictionAnalyticsService $analyticsService
    ) {
        //
    }

    /**
     * Display available prediction sets
     */
    public function index(): View
    {
        $predictionSets = $this->predictionService->getAvailablePredictionSets(auth()->user());
        $userStats = $this->predictionService->getUserStats(auth()->user());
        
        // Get recent predictions for the user
        $recentPredictions = collect();
        if (auth()->check()) {
            $recentPredictions = auth()->user()->predictions()
                ->with(['match.homeTeam', 'match.awayTeam', 'match.league', 'predictionSet'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
        }

        return view('predictions.index', compact('predictionSets', 'userStats', 'recentPredictions'));
    }

    /**
     * Display a specific prediction set
     */
    public function show(PredictionSet $prediction): View
    {
        $prediction->load(['matches.match.homeTeam', 'matches.match.awayTeam', 'matches.match.league']);
        
        // Check if user has already submitted predictions
        $hasSubmitted = $this->predictionService->hasUserSubmittedPredictions(auth()->user(), $prediction);
        $userPredictions = $hasSubmitted ? $this->predictionService->getUserPredictions(auth()->user(), $prediction) : collect();

        return view('predictions.show', compact('prediction', 'hasSubmitted', 'userPredictions'))->with('content', $prediction);
    }

    /**
     * Submit predictions for a prediction set
     */
    public function submit(Request $request, PredictionSet $prediction)
    {
        try {
            $data = $request->validate([
                'predictions' => 'required|array|min:1',
                'predictions.*.match_id' => 'required|exists:matches,id',
                'predictions.*.prediction_type' => 'required|in:result,score,goalscorer,total_goals',
                'predictions.*.prediction_value' => 'required|string|max:255',
            ]);

            $result = $this->predictionService->submitPredictions(auth()->user(), $prediction, $data['predictions']);

            // Force redirect for form submissions (when _force_redirect is present)
            if ($request->has('_force_redirect')) {
                if ($result['success']) {
                    return redirect()->route('predictions.show', $prediction)
                        ->with('success', $result['message']);
                } else {
                    return redirect()->back()
                        ->withErrors($result['errors'] ?? [])
                        ->withInput();
                }
            }

            // Handle AJAX requests
            if ($request->ajax()) {
                return response()->json([
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'submitted_count' => $result['submitted_count'],
                    'total_predictions' => $result['total_predictions'],
                    'errors' => $result['errors'] ?? []
                ]);
            }

            // Handle regular form submissions
            if ($result['success']) {
                return redirect()->route('predictions.show', $prediction)
                    ->with('success', $result['message']);
            } else {
                return redirect()->back()
                    ->withErrors($result['errors'] ?? [])
                    ->withInput();
            }

        } catch (\Exception $e) {
            Log::error('Failed to submit predictions', [
                'user_id' => auth()->id(),
                'prediction_set_id' => $prediction->id,
                'error' => $e->getMessage()
            ]);

            // Handle AJAX requests
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to submit predictions: ' . $e->getMessage()
                ], 500);
            }

            // Handle regular form submissions
            return redirect()->back()
                ->withErrors(['error' => 'Failed to submit predictions: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Update existing predictions
     */
    public function update(Request $request, PredictionSet $prediction)
    {
        try {
            $data = $request->validate([
                "predictions" => "required|array|min:1",
                "predictions.*.match_id" => "required|exists:matches,id",
                "predictions.*.prediction_type" => "required|in:result,score,goalscorer,total_goals",
                "predictions.*.prediction_value" => "required|string|max:255",
            ]);

            $result = $this->predictionService->updatePredictions(auth()->user(), $prediction, $data["predictions"]);

            // Force redirect for form submissions (when _force_redirect is present)
            if ($request->has('_force_redirect')) {
                if ($result["success"]) {
                    return redirect()->route('predictions.show', $prediction)
                        ->with('success', $result["message"]);
                } else {
                    return redirect()->back()
                        ->withErrors($result["errors"] ?? [])
                        ->withInput();
                }
            }

            // Handle AJAX requests
            if ($request->ajax()) {
                return response()->json([
                    "success" => $result["success"],
                    "message" => $result["message"],
                    "submitted_count" => $result["submitted_count"],
                    "total_predictions" => $result["total_predictions"],
                    "errors" => $result["errors"] ?? []
                ]);
            }

            // Handle regular form submissions
            if ($result["success"]) {
                return redirect()->route('predictions.show', $prediction)
                    ->with('success', $result["message"]);
            } else {
                return redirect()->back()
                    ->withErrors($result["errors"] ?? [])
                    ->withInput();
            }

        } catch (\Exception $e) {
            Log::error("Failed to update predictions", [
                "user_id" => auth()->id(),
                "prediction_set_id" => $prediction->id,
                "error" => $e->getMessage()
            ]);

            // Handle AJAX requests
            if ($request->ajax()) {
                return response()->json([
                    "success" => false,
                    "message" => "Failed to update predictions: " . $e->getMessage()
                ], 500);
            }

            // Handle regular form submissions
            return redirect()->back()
                ->withErrors(['error' => 'Failed to update predictions: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Get user prediction history
     */
    public function history(Request $request): View
    {
        $filters = $request->only(['prediction_set_id', 'date_from', 'date_to', 'is_correct']);
        $predictions = $this->predictionService->getUserPredictionHistory(auth()->user(), $filters);
        $userStats = $this->predictionService->getUserStats(auth()->user());
        $predictionSets = PredictionSet::whereHas('userPredictions', fn ($query) => $query->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get();

        return view('predictions.history', compact('predictions', 'userStats', 'filters', 'predictionSets'));
    }

    /**
     * Get leaderboard
     */
    public function leaderboard(Request $request)
    {
        $filters = $request->only(['prediction_set_id', 'limit']);
        $filters['period'] = 'all_time';
        $filters['limit'] = in_array((int) ($filters['limit'] ?? 50), [10, 25, 50, 100], true)
            ? (int) $filters['limit']
            : 50;
        
        $predictionSet = null;
        if (!empty($filters['prediction_set_id'] ?? null)) {
            $predictionSet = PredictionSet::find($filters['prediction_set_id']);
            if (!$predictionSet) {
                unset($filters['prediction_set_id']);
            }
        }
        
        try {
            $leaderboard = $this->predictionService->getLeaderboard($filters, $filters['limit']);
            $userStats = auth()->check() ? $this->predictionService->getUserStats(auth()->user(), $predictionSet) : [
                'total_predictions' => 0,
                'correct_predictions' => 0,
                'total_points' => 0,
                'accuracy_percentage' => 0,
                'rank' => 0,
            ];
        } catch (\Exception $e) {
            Log::error('Leaderboard error', [
                'error' => $e->getMessage(),
                'filters' => $filters,
                'user_id' => auth()->id()
            ]);
            
            // Return empty results on error
            $leaderboard = collect()->paginate(50);
            $userStats = [
                'total_predictions' => 0,
                'correct_predictions' => 0,
                'total_points' => 0,
                'accuracy_percentage' => 0,
                'rank' => 0,
            ];
        }
        
        // Get available prediction sets for filter dropdown
        $predictionSets = PredictionSet::whereHas('leaderboards', fn ($query) => $query->where('period', 'all_time'))
            ->orderBy('name')
            ->get();

        // Handle AJAX requests
        if ($request->ajax()) {
            return response()->json($this->predictionService->getLeaderboardData($filters, $filters['limit']));
        }

        return view('predictions.leaderboard', compact('leaderboard', 'userStats', 'predictionSet', 'predictionSets'));
    }

    /**
     * Get user statistics
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $predictionSetId = $request->get('prediction_set_id');
            $predictionSet = $predictionSetId ? PredictionSet::find($predictionSetId) : null;
            
            $stats = $this->predictionService->getUserStats(auth()->user(), $predictionSet);
            $performance = $this->analyticsService->getUserPerformanceAnalytics(auth()->user(), $predictionSet);

            return response()->json([
                'success' => true,
                'data' => [
                    'basic_stats' => $stats,
                    'performance' => $performance
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available prediction sets (API endpoint)
     */
    public function getAvailable(): JsonResponse
    {
        try {
            $predictionSets = $this->predictionService->getAvailablePredictionSets(auth()->user());

            return response()->json([
                'success' => true,
                'data' => $predictionSets
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch prediction sets: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user predictions for a specific prediction set (API endpoint)
     */
    public function getUserPredictions(PredictionSet $prediction): JsonResponse
    {
        try {
            $predictions = $this->predictionService->getUserPredictions(auth()->user(), $prediction);

            return response()->json([
                'success' => true,
                'data' => $predictions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user predictions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get leaderboard data (API endpoint)
     */
    public function getLeaderboard(Request $request): JsonResponse
    {
        try {
            $predictionSetId = $request->get('prediction_set_id');
            $predictionSet = $predictionSetId ? PredictionSet::find($predictionSetId) : null;
            
            $leaderboard = $this->predictionService->getLeaderboard($predictionSet, 50);

            return response()->json([
                'success' => true,
                'data' => $leaderboard
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch leaderboard: ' . $e->getMessage()
            ], 500);
        }
    }
}
