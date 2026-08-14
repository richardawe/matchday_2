<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PredictionSetService;
use App\Services\PredictionAnalyticsService;
use App\Services\PredictionNotificationService;
use App\Services\PredictionScoringService;
use App\Models\PredictionSet;
use App\Models\League;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;

class PredictionController extends Controller
{
    public function __construct(
        private PredictionSetService $predictionSetService,
        private PredictionAnalyticsService $analyticsService,
        private PredictionNotificationService $notificationService,
        private PredictionScoringService $scoringService
    ) {
        //
    }

    /**
     * Display prediction sets management page
     */
    public function index(): View
    {
        $predictionSets = PredictionSet::with(['admin', 'matches.match.homeTeam', 'matches.match.awayTeam'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $leagues = League::where('is_active', true)->orderBy('name')->get();

        return view('admin.predictions.index', compact('predictionSets', 'leagues'));
    }

    /**
     * Show the form for creating a new prediction set
     */
    public function create(): View
    {
        $leagues = League::where('is_active', true)->orderBy('name')->get();
        $availableMatches = $this->predictionSetService->getAvailableMatches();

        // Set default date range (next 7 days)
        $defaultDateFrom = now()->format('Y-m-d');
        $defaultDateTo = now()->addDays(7)->format('Y-m-d');

        return view('admin.predictions.create', compact('leagues', 'availableMatches', 'defaultDateFrom', 'defaultDateTo'));
    }

    /**
     * Get available matches with filters (AJAX)
     */
    public function getAvailableMatches(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['league_id', 'date_from', 'date_to', 'search']);
            $matches = $this->predictionSetService->getAvailableMatches($filters);

            return response()->json([
                'success' => true,
                'matches' => $matches->map(function ($match) {
                    return [
                        'id' => $match->id,
                        'home_team' => $match->homeTeam->name,
                        'away_team' => $match->awayTeam->name,
                        'league' => $match->league->name,
                        'match_date' => $match->match_date->format('M j, Y H:i'),
                        'league_id' => $match->league_id
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available matches: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created prediction set
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'prediction_deadline' => 'required|date|after:now',
                'matches' => 'required|array|min:1',
                'matches.*.match_id' => 'required|exists:matches,id',
                'matches.*.prediction_type' => 'required|in:result,score,goalscorer,total_goals',
                'matches.*.points_value' => 'nullable|integer|min:1|max:10',
            ]);

            $data['admin_id'] = auth()->id();
            $data['status'] = 'draft';

            $predictionSet = $this->predictionSetService->createPredictionSet($data, auth()->user());

            return response()->json([
                'success' => true,
                'message' => 'Prediction set created successfully',
                'data' => $predictionSet
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create prediction set', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create prediction set: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified prediction set
     */
    public function show(PredictionSet $prediction): View
    {
        $prediction->load(['admin', 'matches.match.homeTeam', 'matches.match.awayTeam', 'matches.match.league']);
        $analytics = $this->analyticsService->getPredictionSetAnalytics($prediction);

        return view('admin.predictions.show', compact('prediction', 'analytics'));
    }

    /**
     * Show the form for editing the specified prediction set
     */
    public function edit(PredictionSet $prediction): View
    {
        $leagues = League::where('is_active', true)->orderBy('name')->get();
        $availableMatches = $this->predictionSetService->getAvailableMatches();
        $prediction->load(['matches']);

        return view('admin.predictions.edit', compact('prediction', 'leagues', 'availableMatches'));
    }

    /**
     * Update the specified prediction set
     */
    public function update(Request $request, PredictionSet $prediction): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'prediction_deadline' => 'required|date',
                'status' => 'required|in:draft,active,closed,archived',
                'matches' => 'nullable|array',
                'matches.*.match_id' => 'required|exists:matches,id',
                'matches.*.prediction_type' => 'required|in:result,score,goalscorer,total_goals',
                'matches.*.points_value' => 'nullable|integer|min:1|max:10',
            ]);

            $predictionSet = $this->predictionSetService->updatePredictionSet($prediction, $data);

            return response()->json([
                'success' => true,
                'message' => 'Prediction set updated successfully',
                'data' => $predictionSet
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update prediction set', [
                'prediction_set_id' => $prediction->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update prediction set: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified prediction set
     */
    public function destroy(PredictionSet $prediction): JsonResponse
    {
        try {
            $prediction->delete();

            return response()->json([
                'success' => true,
                'message' => 'Prediction set deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete prediction set', [
                'prediction_set_id' => $prediction->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete prediction set: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Activate a prediction set
     */
    public function activate(PredictionSet $prediction): JsonResponse
    {
        try {
            $success = $this->predictionSetService->activatePredictionSet($prediction);

            if ($success) {
                // Send notifications to users
                $this->notificationService->sendPredictionSetCreatedNotification($prediction);

                return response()->json([
                    'success' => true,
                    'message' => 'Prediction set activated successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot activate prediction set - deadline has passed'
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate prediction set: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Close a prediction set
     */
    public function close(PredictionSet $prediction): JsonResponse
    {
        try {
            $this->predictionSetService->closePredictionSet($prediction);

            return response()->json([
                'success' => true,
                'message' => 'Prediction set closed successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to close prediction set: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Archive a prediction set
     */
    public function archive(PredictionSet $prediction): JsonResponse
    {
        try {
            $this->predictionSetService->archivePredictionSet($prediction);

            return response()->json([
                'success' => true,
                'message' => 'Prediction set archived successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive prediction set: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get analytics for a prediction set
     */
    public function analytics(PredictionSet $prediction): JsonResponse
    {
        try {
            $analytics = $this->analyticsService->getPredictionSetAnalytics($prediction);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get general analytics dashboard
     */
    public function analyticsDashboard(Request $request)
    {
        try {
            $filters = $request->only(['prediction_set_id', 'date_from', 'date_to']);
            
            // Simple analytics data to avoid complex service issues
            // Get basic stats
            $totalPredictions = \App\Models\UserPrediction::count();
            $correctPredictions = \App\Models\UserPrediction::where('is_correct', true)->count();
            $uniqueUsers = \App\Models\UserPrediction::distinct('user_id')->count();
            
            // Get participation data for charts (last 30 days)
            $participationData = \App\Models\UserPrediction::selectRaw('DATE(created_at) as date, COUNT(*) as daily_predictions, COUNT(DISTINCT user_id) as daily_users')
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
            
            // Get accuracy by type for charts
            $accuracyByType = \App\Models\UserPrediction::selectRaw('prediction_type, COUNT(*) as total, SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct')
                ->groupBy('prediction_type')
                ->get();
            
            $analytics = [
                'basic_stats' => [
                    'total_predictions' => $totalPredictions,
                    'unique_users' => $uniqueUsers,
                    'correct_predictions' => $correctPredictions,
                    'accuracy_percentage' => 0
                ],
                'participation' => [
                    'total_users' => \App\Models\User::count(),
                    'active_users' => \App\Models\User::whereHas('predictions')->count(),
                    'dates' => $participationData->pluck('date')->toArray(),
                    'daily_predictions' => $participationData->pluck('daily_predictions')->toArray(),
                    'daily_users' => $participationData->pluck('daily_users')->toArray()
                ],
                'accuracy' => [
                    'accuracy_by_type' => $accuracyByType,
                    'types' => $accuracyByType->pluck('prediction_type')->toArray(),
                    'values' => $accuracyByType->map(function($item) {
                        return $item->total > 0 ? round(($item->correct / $item->total) * 100, 1) : 0;
                    })->toArray()
                ],
                'top_performers' => \App\Models\PredictionLeaderboard::with('user')
                    ->orderBy('total_points', 'desc')
                    ->limit(10)
                    ->get()
            ];
            
            // Calculate accuracy percentage
            if ($analytics['basic_stats']['total_predictions'] > 0) {
                $analytics['basic_stats']['accuracy_percentage'] = round(
                    ($analytics['basic_stats']['correct_predictions'] / $analytics['basic_stats']['total_predictions']) * 100, 
                    2
                );
            }
            
            $predictionSets = PredictionSet::orderBy('name')->get();

            return view('admin.predictions.analytics', compact('analytics', 'predictionSets'));

        } catch (\Exception $e) {
            Log::error('Failed to fetch analytics dashboard', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()->with('error', 'Failed to load analytics dashboard: ' . $e->getMessage());
        }
    }

    /**
     * Rescore predictions for a specific prediction set
     */
    public function rescore(PredictionSet $prediction): JsonResponse
    {
        try {
            // Get all predictions for this prediction set
            $predictions = \App\Models\UserPrediction::where('prediction_set_id', $prediction->id)
                ->whereNotNull('is_correct')
                ->get();

            if ($predictions->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No predictions found to rescore for this prediction set.'
                ]);
            }

            // Clear existing scores
            $cleared = \App\Models\UserPrediction::where('prediction_set_id', $prediction->id)
                ->whereNotNull('is_correct')
                ->update([
                    'is_correct' => null,
                    'points_earned' => 0
                ]);

            // Re-score with new logic
            $scoringService = new \App\Services\PredictionScoringService();
            $result = $scoringService->scoreAllPendingPredictions();

            if ($result['success']) {
                // Update leaderboard for this prediction set
                $scoringService->updateLeaderboardRanks($prediction);

                return response()->json([
                    'success' => true,
                    'scored_count' => $result['total_scored'],
                    'message' => "Successfully rescored {$result['total_scored']} predictions for '{$prediction->name}'"
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to rescore predictions: ' . implode(', ', $result['errors'] ?? ['Unknown error'])
                ], 500);
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Prediction set rescore failed', [
                'prediction_set_id' => $prediction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to rescore predictions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export general analytics data
     */
    public function exportAnalytics(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['prediction_set_id', 'date_from', 'date_to']);
            
            // Get analytics data
            $analytics = [
                'basic_stats' => [
                    'total_predictions' => \App\Models\UserPrediction::count(),
                    'unique_users' => \App\Models\UserPrediction::distinct('user_id')->count(),
                    'correct_predictions' => \App\Models\UserPrediction::where('is_correct', true)->count(),
                ],
                'participation' => [
                    'total_users' => \App\Models\User::count(),
                    'active_users' => \App\Models\User::whereHas('predictions')->count()
                ],
                'accuracy_by_type' => \App\Models\UserPrediction::selectRaw('prediction_type, COUNT(*) as total, SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct')
                    ->groupBy('prediction_type')
                    ->get(),
                'top_performers' => \App\Models\PredictionLeaderboard::with('user')
                    ->orderBy('total_points', 'desc')
                    ->limit(10)
                    ->get()
            ];
            
            // Calculate accuracy percentage
            if ($analytics['basic_stats']['total_predictions'] > 0) {
                $analytics['basic_stats']['accuracy_percentage'] = round(
                    ($analytics['basic_stats']['correct_predictions'] / $analytics['basic_stats']['total_predictions']) * 100, 
                    2
                );
            }

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'exported_at' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to export analytics data', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export analytics data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export prediction set data
     */
    public function export(PredictionSet $prediction): JsonResponse
    {
        try {
            $data = $this->analyticsService->exportPredictionSetData($prediction);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Score all pending predictions
     */
    public function scorePredictions(): JsonResponse
    {
        try {
            $result = $this->scoringService->scoreAllPendingPredictions();

            return response()->json([
                'success' => true,
                'message' => 'Predictions scored successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Admin prediction scoring failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to score predictions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get scoring statistics
     */
    public function getScoringStats(): JsonResponse
    {
        try {
            $stats = $this->scoringService->getScoringStats();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch scoring statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * View all predictions with transparency (predictions vs actual results)
     */
    public function predictionsTransparency(Request $request): View
    {
        try {
            $filters = $request->only(['prediction_set_id', 'match_id', 'user_id', 'status', 'date_from', 'date_to']);
            
            $query = \App\Models\UserPrediction::with([
                'user:id,name,email',
                'match.homeTeam:id,name',
                'match.awayTeam:id,name',
                'match.league:id,name',
                'predictionSet:id,name'
            ]);

            // Apply filters
            if (!empty($filters['prediction_set_id'])) {
                $query->where('prediction_set_id', $filters['prediction_set_id']);
            }

            if (!empty($filters['match_id'])) {
                $query->where('match_id', $filters['match_id']);
            }

            if (!empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }

            if (!empty($filters['status'])) {
                if ($filters['status'] === 'scored') {
                    $query->whereNotNull('is_correct');
                } elseif ($filters['status'] === 'pending') {
                    $query->whereNull('is_correct');
                }
            }

            if (!empty($filters['date_from'])) {
                $query->whereHas('match', function($q) use ($filters) {
                    $q->where('match_date', '>=', $filters['date_from']);
                });
            }

            if (!empty($filters['date_to'])) {
                $query->whereHas('match', function($q) use ($filters) {
                    $q->where('match_date', '<=', $filters['date_to']);
                });
            }

            $predictions = $query->orderBy('created_at', 'desc')->paginate(50);

            // Get filter options
            $predictionSets = \App\Models\PredictionSet::orderBy('name')->get();
            $matches = \App\Models\FootballMatch::with(['homeTeam', 'awayTeam'])
                ->whereNotNull('home_score')
                ->whereNotNull('away_score')
                ->orderBy('match_date', 'desc')
                ->limit(100)
                ->get();
            $users = \App\Models\User::orderBy('name')->get();

            return view('admin.predictions.transparency', compact(
                'predictions', 
                'predictionSets', 
                'matches', 
                'users', 
                'filters'
            ));

        } catch (\Exception $e) {
            Log::error('Failed to load predictions transparency view', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return redirect()->back()->with('error', 'Failed to load predictions transparency view');
        }
    }

    /**
     * Get detailed prediction comparison for a specific match
     */
    public function matchPredictionsDetail(\App\Models\FootballMatch $match): View
    {
        try {
            $match->load(['homeTeam', 'awayTeam', 'league']);
            
            $predictions = \App\Models\UserPrediction::with(['user', 'predictionSet'])
                ->where('match_id', $match->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // Group predictions by type
            $predictionsByType = $predictions->groupBy('prediction_type');

            return view('admin.predictions.match-detail', compact('match', 'predictions', 'predictionsByType'));

        } catch (\Exception $e) {
            Log::error('Failed to load match predictions detail', [
                'match_id' => $match->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return redirect()->back()->with('error', 'Failed to load match predictions detail');
        }
    }
}
