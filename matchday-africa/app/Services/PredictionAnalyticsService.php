<?php

namespace App\Services;

use App\Models\PredictionSet;
use App\Models\UserPrediction;
use App\Models\PredictionLeaderboard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PredictionAnalyticsService
{
    /**
     * Get comprehensive analytics for a prediction set
     */
    public function getPredictionSetAnalytics(PredictionSet $predictionSet): array
    {
        $stats = $this->getBasicStats($predictionSet);
        $participation = $this->getParticipationStats($predictionSet);
        $accuracy = $this->getAccuracyStats($predictionSet);
        $leaderboard = $this->getTopPerformers($predictionSet, 10);

        return [
            'basic_stats' => $stats,
            'participation' => $participation,
            'accuracy' => $accuracy,
            'top_performers' => $leaderboard,
        ];
    }

    /**
     * Get basic statistics
     */
    public function getBasicStats(PredictionSet $predictionSet): array
    {
        $totalPredictions = $predictionSet->userPredictions()->count();
        $uniqueUsers = $predictionSet->userPredictions()->distinct('user_id')->count();
        $matchesCount = $predictionSet->matches()->count();
        $correctPredictions = $predictionSet->userPredictions()->where('is_correct', true)->count();
        $accuracy = $totalPredictions > 0 ? round(($correctPredictions / $totalPredictions) * 100, 2) : 0;
        $lastPrediction = $predictionSet->userPredictions()->latest()->first()?->submitted_at?->diffForHumans();

        return [
            'total_predictions' => $totalPredictions,
            'unique_users' => $uniqueUsers,
            'matches_count' => $matchesCount,
            'correct_predictions' => $correctPredictions,
            'accuracy_percentage' => $accuracy,
            'average_predictions_per_user' => $uniqueUsers > 0 ? round($totalPredictions / $uniqueUsers, 2) : 0,
            'last_prediction' => $lastPrediction,
        ];
    }

    /**
     * Get participation statistics
     */
    public function getParticipationStats(PredictionSet $predictionSet): array
    {
        $dailyParticipation = $predictionSet->userPredictions()
            ->selectRaw('DATE(submitted_at) as date, COUNT(DISTINCT user_id) as users')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $participationByMatch = $predictionSet->matches()
            ->withCount('userPredictions')
            ->get()
            ->map(function ($match) {
                return [
                    'match_id' => $match->id,
                    'home_team' => $match->match->homeTeam->name,
                    'away_team' => $match->match->awayTeam->name,
                    'predictions_count' => $match->user_predictions_count,
                ];
            });

        return [
            'daily_participation' => $dailyParticipation,
            'participation_by_match' => $participationByMatch,
        ];
    }

    /**
     * Get accuracy statistics
     */
    public function getAccuracyStats(PredictionSet $predictionSet): array
    {
        $accuracyByType = $predictionSet->userPredictions()
            ->selectRaw('prediction_type, 
                        COUNT(*) as total, 
                        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
                        ROUND((SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as accuracy')
            ->groupBy('prediction_type')
            ->get();

        $accuracyByMatch = $predictionSet->matches()
            ->with(['match.homeTeam', 'match.awayTeam'])
            ->get()
            ->map(function ($predictionMatch) {
                $total = $predictionMatch->userPredictions()->count();
                $correct = $predictionMatch->userPredictions()->where('is_correct', true)->count();
                $accuracy = $total > 0 ? round(($correct / $total) * 100, 2) : 0;

                return [
                    'match_id' => $predictionMatch->match_id,
                    'home_team' => $predictionMatch->match->homeTeam->name,
                    'away_team' => $predictionMatch->match->awayTeam->name,
                    'total_predictions' => $total,
                    'correct_predictions' => $correct,
                    'accuracy_percentage' => $accuracy,
                ];
            });

        return [
            'accuracy_by_type' => $accuracyByType,
            'accuracy_by_match' => $accuracyByMatch,
        ];
    }

    /**
     * Get top performers
     */
    public function getTopPerformers(PredictionSet $predictionSet, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $predictionSet->leaderboards()
            ->with('user')
            ->orderBy('total_points', 'desc')
            ->orderBy('accuracy_percentage', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get user performance analytics
     */
    public function getUserPerformanceAnalytics(User $user, ?PredictionSet $predictionSet = null): array
    {
        $query = $user->predictions();

        if ($predictionSet) {
            $query->where('prediction_set_id', $predictionSet->id);
        }

        $totalPredictions = $query->count();
        $correctPredictions = $query->where('is_correct', true)->count();
        $totalPoints = $query->sum('points_earned');
        $accuracy = $totalPredictions > 0 ? round(($correctPredictions / $totalPredictions) * 100, 2) : 0;

        // Performance over time
        $performanceOverTime = $query
            ->selectRaw('DATE(submitted_at) as date, 
                        COUNT(*) as total, 
                        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
                        SUM(points_earned) as points')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Performance by prediction type
        $performanceByType = $query
            ->selectRaw('prediction_type, 
                        COUNT(*) as total, 
                        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
                        ROUND((SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as accuracy')
            ->groupBy('prediction_type')
            ->get();

        return [
            'total_predictions' => $totalPredictions,
            'correct_predictions' => $correctPredictions,
            'total_points' => $totalPoints,
            'accuracy_percentage' => $accuracy,
            'performance_over_time' => $performanceOverTime,
            'performance_by_type' => $performanceByType,
        ];
    }

    /**
     * Get global analytics
     */
    public function getGlobalAnalytics(): array
    {
        $totalUsers = User::count();
        $activeUsers = User::whereHas('predictions')->count();
        $totalPredictions = UserPrediction::count();
        $totalPredictionSets = PredictionSet::count();
        $activePredictionSets = PredictionSet::where('status', 'active')->count();

        $recentActivity = UserPrediction::with(['user', 'predictionSet'])
            ->orderBy('submitted_at', 'desc')
            ->limit(10)
            ->get();

        $topPerformers = PredictionLeaderboard::with('user')
            ->whereNull('prediction_set_id')
            ->orderBy('total_points', 'desc')
            ->limit(10)
            ->get();

        return [
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'total_predictions' => $totalPredictions,
            'total_prediction_sets' => $totalPredictionSets,
            'active_prediction_sets' => $activePredictionSets,
            'recent_activity' => $recentActivity,
            'top_performers' => $topPerformers,
        ];
    }

    /**
     * Export prediction set data
     */
    public function exportPredictionSetData(PredictionSet $predictionSet): array
    {
        $predictions = $predictionSet->userPredictions()
            ->with(['user', 'match.homeTeam', 'match.awayTeam'])
            ->get()
            ->map(function ($prediction) {
                return [
                    'user_name' => $prediction->user->name,
                    'user_email' => $prediction->user->email,
                    'match' => $prediction->match->homeTeam->name . ' vs ' . $prediction->match->awayTeam->name,
                    'prediction_type' => $prediction->prediction_type,
                    'prediction_value' => $prediction->prediction_value,
                    'is_correct' => $prediction->is_correct ? 'Yes' : 'No',
                    'points_earned' => $prediction->points_earned,
                    'submitted_at' => $prediction->submitted_at->format('Y-m-d H:i:s'),
                ];
            });

        return [
            'prediction_set' => [
                'name' => $predictionSet->name,
                'description' => $predictionSet->description,
                'deadline' => $predictionSet->prediction_deadline->format('Y-m-d H:i:s'),
                'status' => $predictionSet->status,
            ],
            'predictions' => $predictions,
            'exported_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get prediction trends
     */
    public function getPredictionTrends(int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days);

        $dailyStats = UserPrediction::where('submitted_at', '>=', $startDate)
            ->selectRaw('DATE(submitted_at) as date, 
                        COUNT(*) as total_predictions, 
                        COUNT(DISTINCT user_id) as unique_users,
                        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_predictions')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $predictionTypeTrends = UserPrediction::where('submitted_at', '>=', $startDate)
            ->selectRaw('prediction_type, 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
                        ROUND((SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as accuracy')
            ->groupBy('prediction_type')
            ->get();

        return [
            'daily_stats' => $dailyStats,
            'prediction_type_trends' => $predictionTypeTrends,
            'period_days' => $days,
        ];
    }

    /**
     * Get general analytics for the dashboard
     */
    public function getGeneralAnalytics(array $filters = []): array
    {
        $query = UserPrediction::query();
        
        if (isset($filters['prediction_set_id']) && $filters['prediction_set_id']) {
            $query->where('prediction_set_id', $filters['prediction_set_id']);
        }
        
        if (isset($filters['date_from'])) {
            $query->where('submitted_at', '>=', Carbon::parse($filters['date_from']));
        }
        
        if (isset($filters['date_to'])) {
            $query->where('submitted_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        // Basic stats
        $basicStats = $this->getBasicStatsForQuery($query);
        
        // Participation stats
        $participation = $this->getParticipationStatsForQuery($query);
        
        // Accuracy stats
        $accuracy = $this->getAccuracyStatsForQuery($query);
        
        // Top performers
        $topPerformers = $this->getTopPerformersForQuery($query, 10);

        return [
            'basic_stats' => $basicStats,
            'participation' => $participation,
            'accuracy' => $accuracy,
            'top_performers' => $topPerformers,
        ];
    }

    /**
     * Get basic stats for a query
     */
    private function getBasicStatsForQuery($query): array
    {
        $totalPredictions = $query->count();
        $uniqueUsers = $query->distinct('user_id')->count();
        $correctPredictions = $query->where('is_correct', true)->count();
        $accuracy = $totalPredictions > 0 ? round(($correctPredictions / $totalPredictions) * 100, 2) : 0;
        $lastPrediction = $query->latest('submitted_at')->first()?->submitted_at?->diffForHumans();

        return [
            'total_predictions' => $totalPredictions,
            'unique_users' => $uniqueUsers,
            'correct_predictions' => $correctPredictions,
            'accuracy_percentage' => $accuracy,
            'average_predictions_per_user' => $uniqueUsers > 0 ? round($totalPredictions / $uniqueUsers, 2) : 0,
            'last_prediction' => $lastPrediction,
            'matches_count' => $query->distinct('match_id')->count(),
        ];
    }

    /**
     * Get participation stats for a query
     */
    private function getParticipationStatsForQuery($query): array
    {
        $dailyParticipation = $query->selectRaw('DATE(submitted_at) as date, 
                                                COUNT(*) as daily_predictions, 
                                                COUNT(DISTINCT user_id) as daily_users')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'dates' => $dailyParticipation->pluck('date')->map(fn($date) => Carbon::parse($date)->format('M j')),
            'daily_predictions' => $dailyParticipation->pluck('daily_predictions'),
            'daily_users' => $dailyParticipation->pluck('daily_users'),
        ];
    }

    /**
     * Get accuracy stats for a query
     */
    private function getAccuracyStatsForQuery($query): array
    {
        $accuracyByType = $query->selectRaw('prediction_type, 
                                            COUNT(*) as total,
                                            SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
                                            ROUND((SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as accuracy')
            ->groupBy('prediction_type')
            ->get();

        return [
            'types' => $accuracyByType->pluck('prediction_type'),
            'values' => $accuracyByType->pluck('accuracy'),
        ];
    }

    /**
     * Get top performers for a query
     */
    private function getTopPerformersForQuery($query, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $query->selectRaw('user_id, 
                                 COUNT(*) as total_predictions,
                                 SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_predictions,
                                 SUM(points_earned) as total_points,
                                 ROUND((SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as accuracy_percentage')
            ->with('user')
            ->groupBy('user_id')
            ->orderBy('total_points', 'desc')
            ->orderBy('accuracy_percentage', 'desc')
            ->limit($limit)
            ->get();
    }
}
