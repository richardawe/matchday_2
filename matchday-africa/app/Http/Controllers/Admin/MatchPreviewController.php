<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MatchPreviewService;
use App\Models\MatchPreview;
use App\Models\FootballMatch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MatchPreviewController extends Controller
{
    protected $matchPreviewService;

    public function __construct(MatchPreviewService $matchPreviewService)
    {
        $this->matchPreviewService = $matchPreviewService;
    }

    /**
     * Display match previews management page
     */
    public function index(Request $request): View
    {
        $date = $request->get('date', now()->format('Y-m-d'));
        $selectedDate = Carbon::parse($date);

        $previews = MatchPreview::with(['match.homeTeam', 'match.awayTeam', 'match.league'])
            ->whereHas('match', function($query) use ($selectedDate) {
                $query->whereDate('match_date', $selectedDate);
            })
            ->orderBy('generated_at', 'desc')
            ->paginate(20);

        $stats = $this->matchPreviewService->getStats();

        return view('admin.match-previews.index', compact('previews', 'selectedDate', 'stats'));
    }

    /**
     * Generate previews for a specific date
     */
    public function generateDaily(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
            'force' => 'boolean'
        ]);

        try {
            $date = Carbon::parse($request->date);
            $force = $request->boolean('force', false);

            $results = $this->matchPreviewService->generateDailyPreviews($date, $force);

            return response()->json([
                'success' => true,
                'message' => "Generated {$results['success']} previews for {$date->format('Y-m-d')}",
                'results' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Daily preview generation failed', [
                'date' => $request->date,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate previews: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Regenerate preview for a specific match
     */
    public function regenerate(Request $request, FootballMatch $match): JsonResponse
    {
        try {
            $preview = $this->matchPreviewService->regeneratePreview($match);

            return response()->json([
                'success' => true,
                'message' => 'Preview regenerated successfully',
                'preview' => $preview
            ]);

        } catch (\Exception $e) {
            Log::error('Preview regeneration failed', [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle featured status of a preview
     */
    public function toggleFeatured(MatchPreview $preview): JsonResponse
    {
        try {
            $this->matchPreviewService->toggleFeatured($preview);

            return response()->json([
                'success' => true,
                'message' => 'Featured status updated',
                'is_featured' => $preview->is_featured
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update featured status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a preview
     */
    public function destroy(MatchPreview $preview): JsonResponse
    {
        try {
            $preview->delete();

            return response()->json([
                'success' => true,
                'message' => 'Preview deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get preview statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->matchPreviewService->getStats();

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force regenerate all existing previews
     */
    public function forceRegenerateAll(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'date' => 'required|date'
            ]);

            $date = Carbon::parse($request->date);
            
            // Get all matches for the date
            $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
                ->whereDate('match_date', $date)
                ->where('status', '!=', 'FINISHED')
                ->get();

            $results = [
                'success' => 0,
                'failed' => 0,
                'total' => $matches->count()
            ];

            foreach ($matches as $match) {
                try {
                    // Force regenerate by deleting existing preview first
                    $match->preview()->delete();
                    
                    // Generate new preview
                    $preview = $this->matchPreviewService->generatePreview($match);
                    
                    if ($preview) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    Log::error('Force regeneration failed for match', [
                        'match_id' => $match->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Force regenerated {$results['success']} previews for {$date->format('Y-m-d')}",
                'results' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Force regenerate all failed', [
                'date' => $request->date,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to force regenerate previews: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available dates for preview generation
     */
    public function getAvailableDates(): JsonResponse
    {
        try {
            $dates = FootballMatch::selectRaw('DATE(match_date) as date')
                ->where('match_date', '>=', now()->subDays(7))
                ->where('match_date', '<=', now()->addDays(7))
                ->where('status', '!=', 'FINISHED')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('date');

            return response()->json([
                'success' => true,
                'dates' => $dates
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get available dates: ' . $e->getMessage()
            ], 500);
        }
    }
}