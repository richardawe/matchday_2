<?php

namespace App\Services;

use App\Models\PredictionSet;
use App\Models\PredictionSetMatch;
use App\Models\FootballMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PredictionSetService
{
    /**
     * Create a new prediction set
     */
    public function createPredictionSet(array $data, User $admin): PredictionSet
    {
        return DB::transaction(function () use ($data, $admin) {
            $predictionSet = PredictionSet::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'admin_id' => $admin->id,
                'status' => $data['status'] ?? 'draft',
                'prediction_deadline' => Carbon::parse($data['prediction_deadline']),
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Add matches to the prediction set
            if (isset($data['matches']) && is_array($data['matches'])) {
                $this->addMatchesToPredictionSet($predictionSet, $data['matches']);
            }

            Log::info('Prediction set created', [
                'prediction_set_id' => $predictionSet->id,
                'admin_id' => $admin->id,
                'matches_count' => count($data['matches'] ?? [])
            ]);

            return $predictionSet;
        });
    }

    /**
     * Update an existing prediction set
     */
    public function updatePredictionSet(PredictionSet $predictionSet, array $data): PredictionSet
    {
        return DB::transaction(function () use ($predictionSet, $data) {
            $predictionSet->update([
                'name' => $data['name'] ?? $predictionSet->name,
                'description' => $data['description'] ?? $predictionSet->description,
                'status' => $data['status'] ?? $predictionSet->status,
                'prediction_deadline' => isset($data['prediction_deadline']) 
                    ? Carbon::parse($data['prediction_deadline']) 
                    : $predictionSet->prediction_deadline,
                'metadata' => $data['metadata'] ?? $predictionSet->metadata,
            ]);

            // Update matches if provided
            if (isset($data['matches']) && is_array($data['matches'])) {
                $this->updatePredictionSetMatches($predictionSet, $data['matches']);
            }

            return $predictionSet->fresh();
        });
    }

    /**
     * Add matches to a prediction set
     */
    public function addMatchesToPredictionSet(PredictionSet $predictionSet, array $matches): void
    {
        foreach ($matches as $matchData) {
            PredictionSetMatch::create([
                'prediction_set_id' => $predictionSet->id,
                'match_id' => $matchData['match_id'],
                'prediction_type' => $matchData['prediction_type'] ?? 'result',
                'points_value' => $matchData['points_value'] ?? 1,
            ]);
        }
    }

    /**
     * Update matches in a prediction set
     */
    public function updatePredictionSetMatches(PredictionSet $predictionSet, array $matches): void
    {
        // Remove existing matches
        $predictionSet->matches()->delete();

        // Add new matches
        $this->addMatchesToPredictionSet($predictionSet, $matches);
    }

    /**
     * Get available matches for prediction sets
     */
    public function getAvailableMatches(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->where('match_date', '>', now())
            ->where('match_date', '<=', now()->addDays(7)) // Load matches within 7 days
            ->whereNotIn('status', ['FINISHED', 'CANCELLED', 'POSTPONED']);

        // Apply filters
        if (isset($filters['league_id']) && $filters['league_id']) {
            $query->where('league_id', $filters['league_id']);
        }

        if (isset($filters['date_from']) && $filters['date_from']) {
            $query->where('match_date', '>=', Carbon::parse($filters['date_from']));
        }

        if (isset($filters['date_to']) && $filters['date_to']) {
            $query->where('match_date', '<=', Carbon::parse($filters['date_to']));
        }

        if (isset($filters['search']) && $filters['search']) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->whereHas('homeTeam', function ($subQ) use ($search) {
                    $subQ->where('name', 'like', "%{$search}%");
                })->orWhereHas('awayTeam', function ($subQ) use ($search) {
                    $subQ->where('name', 'like', "%{$search}%");
                });
            });
        }

        return $query->orderBy('match_date', 'asc')->get();
    }

    /**
     * Validate prediction set data
     */
    public function validatePredictionSetData(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }

        if (empty($data['prediction_deadline'])) {
            $errors[] = 'Prediction deadline is required';
        } elseif (Carbon::parse($data['prediction_deadline']) <= now()) {
            $errors[] = 'Prediction deadline must be in the future';
        }

        if (empty($data['matches']) || !is_array($data['matches'])) {
            $errors[] = 'At least one match is required';
        } else {
            foreach ($data['matches'] as $index => $match) {
                if (empty($match['match_id'])) {
                    $errors[] = "Match ID is required for match at index {$index}";
                } elseif (!FootballMatch::where('id', $match['match_id'])->exists()) {
                    $errors[] = "Match with ID {$match['match_id']} does not exist";
                }
            }
        }

        return $errors;
    }

    /**
     * Get prediction set statistics
     */
    public function getPredictionSetStats(PredictionSet $predictionSet): array
    {
        $totalPredictions = $predictionSet->userPredictions()->count();
        $uniqueUsers = $predictionSet->userPredictions()->distinct('user_id')->count();
        $correctPredictions = $predictionSet->userPredictions()->where('is_correct', true)->count();
        $accuracy = $totalPredictions > 0 ? round(($correctPredictions / $totalPredictions) * 100, 2) : 0;

        return [
            'total_predictions' => $totalPredictions,
            'unique_users' => $uniqueUsers,
            'correct_predictions' => $correctPredictions,
            'accuracy_percentage' => $accuracy,
            'matches_count' => $predictionSet->matches()->count(),
            'is_active' => $predictionSet->isActive(),
            'deadline_passed' => $predictionSet->isDeadlinePassed(),
        ];
    }

    /**
     * Activate a prediction set
     */
    public function activatePredictionSet(PredictionSet $predictionSet): bool
    {
        if ($predictionSet->isDeadlinePassed()) {
            return false;
        }

        $predictionSet->update(['status' => 'active']);
        
        Log::info('Prediction set activated', [
            'prediction_set_id' => $predictionSet->id
        ]);

        return true;
    }

    /**
     * Close a prediction set
     */
    public function closePredictionSet(PredictionSet $predictionSet): bool
    {
        $predictionSet->update(['status' => 'closed']);
        
        Log::info('Prediction set closed', [
            'prediction_set_id' => $predictionSet->id
        ]);

        return true;
    }

    /**
     * Archive a prediction set
     */
    public function archivePredictionSet(PredictionSet $predictionSet): bool
    {
        $predictionSet->update(['status' => 'archived']);
        
        Log::info('Prediction set archived', [
            'prediction_set_id' => $predictionSet->id
        ]);

        return true;
    }
}
