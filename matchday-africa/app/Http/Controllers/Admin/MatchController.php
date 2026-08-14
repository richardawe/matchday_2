<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Models\League;
use App\Services\PredictionScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MatchController extends Controller
{
    protected $scoringService;

    public function __construct(PredictionScoringService $scoringService)
    {
        $this->scoringService = $scoringService;
    }

    /**
     * Display a listing of matches for admin management
     */
    public function index(Request $request)
    {
        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->orderBy('match_date', 'desc');

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('league_id')) {
            $query->where('league_id', $request->league_id);
        }

        // Auto-filter to current date if no date filters are provided
        if (!$request->filled('date_from') && !$request->filled('date_to')) {
            $today = now()->format('Y-m-d');
            $query->whereDate('match_date', $today);
        } else {
            if ($request->filled('date_from')) {
                $query->whereDate('match_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('match_date', '<=', $request->date_to);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('homeTeam', function($teamQuery) use ($search) {
                    $teamQuery->where('name', 'like', "%{$search}%");
                })->orWhereHas('awayTeam', function($teamQuery) use ($search) {
                    $teamQuery->where('name', 'like', "%{$search}%");
                });
            });
        }

        $matches = $query->paginate(20)->withQueryString();

        $leagues = League::orderBy('name')->get();
        $statuses = ['scheduled', 'live', 'finished', 'cancelled', 'postponed'];

        // Set default date values for the form
        $defaultDateFrom = $request->get('date_from', now()->format('Y-m-d'));
        $defaultDateTo = $request->get('date_to', now()->format('Y-m-d'));

        return view('admin.matches.index', compact('matches', 'leagues', 'statuses', 'defaultDateFrom', 'defaultDateTo'));
    }

    /**
     * Show match details and scoring interface
     */
    public function show(FootballMatch $match)
    {
        $match->load(['homeTeam', 'awayTeam', 'league', 'userPredictions']);
        
        // Get prediction sets that include this match
        $predictionSets = DB::table('prediction_set_matches')
            ->join('prediction_sets', 'prediction_set_matches.prediction_set_id', '=', 'prediction_sets.id')
            ->where('prediction_set_matches.match_id', $match->id)
            ->select('prediction_sets.*')
            ->get();

        // Get user predictions for this match
        $userPredictions = DB::table('user_predictions')
            ->join('users', 'user_predictions.user_id', '=', 'users.id')
            ->join('prediction_sets', 'user_predictions.prediction_set_id', '=', 'prediction_sets.id')
            ->where('user_predictions.match_id', $match->id)
            ->select('user_predictions.*', 'users.name as user_name', 'users.email as user_email', 'prediction_sets.name as prediction_set_name')
            ->get();

        return view('admin.matches.show', compact('match', 'predictionSets', 'userPredictions'));
    }

    /**
     * Update match score and trigger scoring
     */
    public function updateScore(Request $request, FootballMatch $match)
    {
        $request->validate([
            'home_score' => 'required|integer|min:0',
            'away_score' => 'required|integer|min:0',
            'status' => 'required|in:scheduled,live,finished,cancelled,postponed',
            'force_scoring' => 'boolean'
        ]);

        $oldStatus = $match->status;
        $oldHomeScore = $match->home_score;
        $oldAwayScore = $match->away_score;

        // Update match
        $match->update([
            'home_score' => $request->home_score,
            'away_score' => $request->away_score,
            'status' => $request->status,
            'scored_at' => strtolower($request->status) === 'finished' ? now() : null,
        ]);

        // Trigger scoring if match is finished and scores changed
        if (strtolower($request->status) === 'finished' && 
            (strtolower($oldStatus) !== 'finished' || $oldHomeScore != $request->home_score || $oldAwayScore != $request->away_score)) {
            
            try {
                $this->scoringService->scoreMatchPredictions($match);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Match score updated and predictions scored successfully!',
                    'scored_predictions' => $match->predictions()->where('is_scored', true)->count()
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Score updated but scoring failed: ' . $e->getMessage()
                ], 500);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Match score updated successfully!'
        ]);
    }

    /**
     * Force score all predictions for a match
     */
    public function forceScore(FootballMatch $match)
    {
        if (strtolower($match->status) !== 'finished') {
            return response()->json([
                'success' => false,
                'message' => 'Match must be finished to score predictions'
            ], 400);
        }

        try {
            $this->scoringService->scoreMatchPredictions($match);
            
            $scoredCount = $match->userPredictions()->where('is_scored', true)->count();
            
            return response()->json([
                'success' => true,
                'message' => "Successfully scored {$scoredCount} predictions!",
                'scored_predictions' => $scoredCount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Scoring failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update match statuses
     */
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'match_ids' => 'required|array',
            'match_ids.*' => 'exists:football_matches,id',
            'status' => 'required|in:scheduled,live,finished,cancelled,postponed',
            'home_scores' => 'array',
            'away_scores' => 'array',
            'force_scoring' => 'boolean'
        ]);

        $updated = 0;
        $scored = 0;

        DB::transaction(function () use ($request, &$updated, &$scored) {
            foreach ($request->match_ids as $index => $matchId) {
                $match = FootballMatch::find($matchId);
                if (!$match) continue;

                $oldStatus = $match->status;
                $oldHomeScore = $match->home_score;
                $oldAwayScore = $match->away_score;

                $updateData = ['status' => $request->status];
                
                if (strtolower($request->status) === 'finished') {
                    $updateData['scored_at'] = now();
                    
                    if (isset($request->home_scores[$index])) {
                        $updateData['home_score'] = $request->home_scores[$index];
                    }
                    if (isset($request->away_scores[$index])) {
                        $updateData['away_score'] = $request->away_scores[$index];
                    }
                }

                $match->update($updateData);
                $updated++;

                // Trigger scoring if match is finished and scores changed
                if (strtolower($request->status) === 'finished' && 
                    (strtolower($oldStatus) !== 'finished' || 
                     $oldHomeScore != ($updateData['home_score'] ?? $match->home_score) || 
                     $oldAwayScore != ($updateData['away_score'] ?? $match->away_score))) {
                    
                    try {
                        $this->scoringService->scoreMatchPredictions($match);
                        $scored++;
                    } catch (\Exception $e) {
                        // Log error but continue with other matches
                        \Log::error("Failed to score predictions for match {$matchId}: " . $e->getMessage());
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Updated {$updated} matches" . ($scored > 0 ? " and scored {$scored} prediction sets" : ""),
            'updated' => $updated,
            'scored' => $scored
        ]);
    }

    /**
     * Get matches for AJAX requests
     */
    public function getMatches(Request $request)
    {
        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->orderBy('match_date', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('league_id')) {
            $query->where('league_id', $request->league_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('match_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('match_date', '<=', $request->date_to);
        }

        $matches = $query->limit(50)->get();

        return response()->json($matches);
    }

    /**
     * Get match statistics
     */
    public function getStats()
    {
        $stats = [
            'total_matches' => FootballMatch::count(),
            'finished_matches' => FootballMatch::where('status', 'finished')->count(),
            'scheduled_matches' => FootballMatch::where('status', 'scheduled')->count(),
            'live_matches' => FootballMatch::where('status', 'live')->count(),
            'matches_today' => FootballMatch::whereDate('match_date', today())->count(),
            'matches_this_week' => FootballMatch::whereBetween('match_date', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Auto-update scores for finished matches from API
     */
    public function autoUpdateScores(Request $request)
    {
        $request->validate([
            'match_ids' => 'array',
            'match_ids.*' => 'exists:football_matches,id',
            'update_all_finished' => 'boolean'
        ]);

        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league']);

        if ($request->filled('match_ids')) {
            $query->whereIn('id', $request->match_ids);
        } elseif ($request->boolean('update_all_finished')) {
            // Find matches that should be finished (past match date but still TIMED/SCHEDULED)
            $query->whereIn('status', ['TIMED', 'SCHEDULED'])
                  ->where('match_date', '<', now()->subHours(2)); // 2 hours after match time
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Please specify match IDs or enable update_all_finished'
            ], 400);
        }

        $matches = $query->whereNotNull('football_data_id')->get();
        $updated = 0;
        $scored = 0;
        $errors = [];

        DB::transaction(function () use ($matches, &$updated, &$scored, &$errors) {
            $footballDataService = new \App\Services\FootballDataService();
            
            foreach ($matches as $index => $match) {
                try {
                    // Add delay between API calls to respect rate limits
                    if ($index > 0) {
                        sleep(6); // 6 seconds between calls (10 per minute max)
                    }
                    
                    // Fetch real match data from API
                    $matchData = $footballDataService->getMatchDetails($match->football_data_id);
                    
                    if (!$matchData) {
                        $errors[] = "Could not fetch data for match {$match->id} (FD_ID: {$match->football_data_id})";
                        continue;
                    }

                    // Extract scores from API response
                    $homeScore = $matchData['score']['fullTime']['home'] ?? null;
                    $awayScore = $matchData['score']['fullTime']['away'] ?? null;
                    $status = $matchData['status'] ?? 'SCHEDULED';

                    // Only update if we have valid scores and match is finished
                    if ($homeScore !== null && $awayScore !== null && in_array($status, ['FINISHED', 'FT'])) {
                        $match->update([
                            'status' => 'FINISHED',
                            'home_score' => $homeScore,
                            'away_score' => $awayScore,
                            'scored_at' => now(),
                            'last_api_update' => now(),
                        ]);

                        $updated++;

                        // Trigger scoring for predictions
                        try {
                            $this->scoringService->scoreMatchPredictions($match);
                            $scored++;
                        } catch (\Exception $e) {
                            $errors[] = "Failed to score predictions for match {$match->id}: " . $e->getMessage();
                        }
                    } else {
                        $errors[] = "Match {$match->id} not finished or no scores available (Status: {$status})";
                    }
                } catch (\Exception $e) {
                    $errors[] = "API error for match {$match->id}: " . $e->getMessage();
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Updated {$updated} matches from API and scored {$scored} prediction sets",
            'updated' => $updated,
            'scored' => $scored,
            'errors' => $errors,
            'matches' => $matches->map(function($match) {
                return [
                    'id' => $match->id,
                    'teams' => $match->homeTeam->name . ' vs ' . $match->awayTeam->name,
                    'score' => $match->home_score . '-' . $match->away_score,
                    'status' => $match->status
                ];
            })
        ]);
    }

    /**
     * Verify and correct all match scores from API
     */
    public function verifyAllScores(Request $request)
    {
        $request->validate([
            'limit' => 'integer|min:1|max:100',
            'status' => 'string|in:all,finished,finished_without_scores'
        ]);

        $limit = $request->get('limit', 10); // Reduced from 50 to 10 to avoid rate limits
        $status = $request->get('status', 'all');

        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereNotNull('football_data_id');

        if ($status === 'finished') {
            $query->where('status', 'FINISHED');
        } elseif ($status === 'finished_without_scores') {
            $query->where('status', 'FINISHED')
                  ->where(function($q) {
                      $q->whereNull('home_score')
                        ->orWhereNull('away_score');
                  });
        }

        $matches = $query->limit($limit)->get();
        $verified = 0;
        $corrected = 0;
        $errors = [];
        $corrections = [];

        DB::transaction(function () use ($matches, &$verified, &$corrected, &$errors, &$corrections) {
            $footballDataService = new \App\Services\FootballDataService();
            
            foreach ($matches as $index => $match) {
                try {
                    // Add delay between API calls to respect rate limits
                    if ($index > 0) {
                        sleep(6); // 6 seconds between calls (10 per minute max)
                    }
                    
                    // Fetch real match data from API
                    $matchData = $footballDataService->getMatchDetails($match->football_data_id);
                    
                    if (!$matchData) {
                        $errors[] = "Could not fetch data for match {$match->id} (FD_ID: {$match->football_data_id})";
                        continue;
                    }

                    // Extract scores from API response
                    $apiHomeScore = $matchData['score']['fullTime']['home'] ?? null;
                    $apiAwayScore = $matchData['score']['fullTime']['away'] ?? null;
                    $apiStatus = $matchData['status'] ?? 'SCHEDULED';

                    $verified++;

                    // Check if scores need correction
                    if ($apiHomeScore !== null && $apiAwayScore !== null) {
                        $needsUpdate = false;
                        $oldScore = $match->home_score . '-' . $match->away_score;
                        $newScore = $apiHomeScore . '-' . $apiAwayScore;

                        if ($match->home_score != $apiHomeScore || $match->away_score != $apiAwayScore) {
                            $needsUpdate = true;
                        }

                        if ($match->status !== 'FINISHED' && in_array($apiStatus, ['FINISHED', 'FT'])) {
                            $needsUpdate = true;
                        }

                        if ($needsUpdate) {
                            $match->update([
                                'status' => 'FINISHED',
                                'home_score' => $apiHomeScore,
                                'away_score' => $apiAwayScore,
                                'scored_at' => now(),
                                'last_api_update' => now(),
                            ]);

                            $corrections[] = [
                                'match_id' => $match->id,
                                'teams' => $match->homeTeam->name . ' vs ' . $match->awayTeam->name,
                                'old_score' => $oldScore,
                                'new_score' => $newScore,
                                'old_status' => $match->getOriginal('status'),
                                'new_status' => 'FINISHED'
                            ];

                            $corrected++;

                            // Re-score predictions if scores changed
                            try {
                                $this->scoringService->scoreMatchPredictions($match);
                            } catch (\Exception $e) {
                                $errors[] = "Failed to re-score predictions for match {$match->id}: " . $e->getMessage();
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = "API error for match {$match->id}: " . $e->getMessage();
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Verified {$verified} matches, corrected {$corrected} scores",
            'verified' => $verified,
            'corrected' => $corrected,
            'errors' => $errors,
            'corrections' => $corrections
        ]);
    }
}
