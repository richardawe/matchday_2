<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\FootballDataService;
use App\Services\MatchService;
use App\Services\LeagueService;
use App\Services\StandingService;
use App\Services\PlayerService;
use App\Services\MatchPreviewService;
use App\Models\League;
use App\Models\Team;
use App\Models\FootballMatch;
use App\Models\Standing;
use App\Models\Player;
use App\Models\MatchPreview;
use App\Models\SyncRun;
use App\Models\MatchEvent;
use App\Models\NewsCandidate;

class AdminController extends Controller
{
    public function __construct(
        private FootballDataService $footballDataService,
        private MatchService $matchService,
        private LeagueService $leagueService,
        private StandingService $standingService,
        private PlayerService $playerService,
        private MatchPreviewService $previewService
    ) {
        // Add middleware in routes instead
    }

    /**
     * Admin dashboard
     */
    public function dashboard(): View
    {
        $stats = $this->getDashboardStats();
        $apiStatus = $this->footballDataService->getApiStatus();
        $recentLogs = $this->getRecentSyncLogs();
        $syncRuns = SyncRun::latest('started_at')->limit(12)->get();
        $operations = [
            'last_success' => SyncRun::where('status', 'success')->max('finished_at'),
            'stale_live' => FootballMatch::whereIn('status', FootballMatch::LIVE_STATUSES)->where(fn($q)=>$q->whereNull('last_api_update')->orWhere('last_api_update','<',now()->subMinutes(5)))->count(),
            'finished_missing_score' => FootballMatch::whereIn('status',['FINISHED','FT'])->where(fn($q)=>$q->whereNull('home_score')->orWhereNull('away_score'))->count(),
            'finished_missing_events' => FootballMatch::whereIn('status',['FINISHED','FT'])->where('match_date','>=',now()->subDays(7))->whereDoesntHave('events')->count(),
            'news_today' => NewsCandidate::where('status','published')->whereDate('updated_at',now())->count(),
            'events_today' => MatchEvent::whereDate('updated_at',now())->count(),
        ];
        
        return view('admin.dashboard', compact('stats', 'apiStatus', 'recentLogs', 'syncRuns', 'operations'));
    }

    /**
     * Data synchronization management page
     */
    public function syncIndex(): View
    {
        $leagues = League::where('is_active', true)->orderBy('name')->get();
        $teams = Team::where('is_active', true)->with('league')->orderBy('name')->limit(50)->get();
        $apiStatus = $this->footballDataService->getApiStatus();
        
        return view('admin.sync.index', compact('leagues', 'teams', 'apiStatus'));
    }

    /**
     * Sync leagues via AJAX
     */
    public function syncLeagues(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type', 'featured'); // featured or all
            
            if ($type === 'all') {
                $result = $this->leagueService->syncAllCompetitions();
            } else {
                $result = $this->leagueService->syncFeaturedCompetitions();
            }
            
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Leagues synced successfully',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin leagues sync failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync matches via AJAX
     */
    public function syncMatches(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type', 'today'); // today, date, range, league
            
            switch ($type) {
                case 'today':
                    $result = $this->matchService->syncTodaysMatches();
                    break;
                    
                case 'date':
                    $date = $request->input('date', now()->toDateString());
                    $result = $this->matchService->syncMatchesByDate($date);
                    break;
                    
                case 'range':
                    $days = (int) $request->input('days', 7);
                    $result = $this->syncMatchesByRange($days);
                    break;
                    
                case 'league':
                    $leagueId = (int) $request->input('league_id');
                    $league = League::find($leagueId);
                    if (!$league) {
                        throw new \Exception('League not found');
                    }
                    $result = $this->matchService->syncLeagueMatches($league->football_data_id);
                    break;
                    
                default:
                    throw new \Exception('Invalid sync type');
            }
            
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Matches synced successfully',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin matches sync failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync standings via AJAX
     */
    public function syncStandings(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type', 'all'); // all or league
            
            if ($type === 'league') {
                $leagueId = (int) $request->input('league_id');
                $league = League::find($leagueId);
                if (!$league) {
                    throw new \Exception('League not found');
                }
                $result = $this->standingService->syncLeagueStandings($league->football_data_id);
            } else {
                $result = $this->standingService->syncAllLeagueStandings();
            }
            
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Standings synced successfully',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin standings sync failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync players via AJAX
     */
    public function syncPlayers(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type', 'team'); // team, league, all
            $limit = (int) $request->input('limit', 10);
            
            switch ($type) {
                case 'team':
                    $teamId = (int) $request->input('team_id');
                    $team = Team::find($teamId);
                    if (!$team) {
                        throw new \Exception('Team not found');
                    }
                    $result = $this->playerService->syncTeamPlayers($team->football_data_id);
                    break;
                    
                case 'league':
                    $leagueId = (int) $request->input('league_id');
                    $result = $this->syncPlayersByLeague($leagueId, $limit);
                    break;
                    
                case 'all':
                    $result = $this->syncPlayersWithLimit($limit);
                    break;
                    
                default:
                    throw new \Exception('Invalid sync type');
            }
            
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Players synced successfully',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin players sync failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear all football data caches
     */
    public function clearCache(): JsonResponse
    {
        try {
            $clearedKeys = $this->footballDataService->clearCache();
            
            return response()->json([
                'success' => true,
                'message' => "Cleared {$clearedKeys} cache entries",
                'cleared_keys' => $clearedKeys
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin cache clear failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Cache clear failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get API status information
     */
    public function apiStatus(): JsonResponse
    {
        try {
            $status = $this->footballDataService->getApiStatus();
            
            return response()->json([
                'success' => true,
                'status' => $status
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get API status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard statistics
     */
    private function getDashboardStats(): array
    {
        return [
            'leagues' => [
                'total' => League::count(),
                'active' => League::where('is_active', true)->count(),
                'featured' => League::where('is_featured', true)->count()
            ],
            'teams' => [
                'total' => Team::count(),
                'active' => Team::where('is_active', true)->count()
            ],
            'matches' => [
                'total' => FootballMatch::count(),
                'today' => FootballMatch::whereDate('match_date', now())->count(),
                'live' => FootballMatch::whereIn('status', FootballMatch::LIVE_STATUSES)->count(),
                'this_week' => FootballMatch::whereBetween('match_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])->count()
            ],
            'standings' => [
                'total' => Standing::count(),
                'current' => Standing::where('is_current', true)->count()
            ],
            'players' => [
                'total' => Player::count(),
                'active' => Player::where('is_active', true)->count()
            ],
            'previews' => [
                'total' => MatchPreview::count(),
                'active' => MatchPreview::where('generation_status', 'completed')->count(),
                'featured' => MatchPreview::where('is_featured', true)->count(),
                'today' => MatchPreview::whereDate('generated_at', now())->count()
            ]
        ];
    }

    /**
     * Get recent sync logs
     */
    private function getRecentSyncLogs(): array
    {
        $logFile = storage_path('logs/laravel.log');
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $logs = [];
        $handle = fopen($logFile, 'r');
        
        if ($handle) {
            $lines = [];
            while (($line = fgets($handle)) !== false) {
                if (str_contains($line, 'sync') && str_contains($line, 'completed')) {
                    $lines[] = $line;
                }
            }
            fclose($handle);
            
            // Get last 10 sync logs
            $logs = array_slice(array_reverse($lines), 0, 10);
        }
        
        return $logs;
    }

    /**
     * Sync matches for a date range
     */
    private function syncMatchesByRange(int $days): array
    {
        $totalSuccess = 0;
        $totalErrors = 0;
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result = $this->matchService->syncMatchesByDate($date);
            $totalSuccess += $result['success'];
            $totalErrors += $result['errors'];
            
            // Rate limiting
            if ($i > 0) {
                sleep(2);
            }
        }
        
        return [
            'success' => $totalSuccess,
            'errors' => $totalErrors,
            'message' => "Synced matches for {$days} days: {$totalSuccess} success, {$totalErrors} errors"
        ];
    }

    /**
     * Sync players for teams in a specific league
     */
    private function syncPlayersByLeague(int $leagueId, int $limit): array
    {
        $teams = Team::where('league_id', $leagueId)
            ->where('is_active', true)
            ->whereNotNull('football_data_id')
            ->limit($limit)
            ->get();
            
        if ($teams->isEmpty()) {
            return ['success' => 0, 'errors' => 1, 'message' => 'No teams found for this league'];
        }
        
        $totalSuccess = 0;
        $totalErrors = 0;
        
        foreach ($teams as $team) {
            $result = $this->playerService->syncTeamPlayers($team->football_data_id);
            $totalSuccess += $result['success'];
            $totalErrors += $result['errors'];
            
            // Rate limiting
            sleep(7);
        }
        
        return [
            'success' => $totalSuccess,
            'errors' => $totalErrors,
            'message' => "Synced players for {$teams->count()} teams: {$totalSuccess} success, {$totalErrors} errors"
        ];
    }

    /**
     * Sync players for all teams with limit
     */
    private function syncPlayersWithLimit(int $limit): array
    {
        $teams = Team::where('is_active', true)
            ->whereNotNull('football_data_id')
            ->limit($limit)
            ->get();
            
        if ($teams->isEmpty()) {
            return ['success' => 0, 'errors' => 1, 'message' => 'No teams found'];
        }
        
        $totalSuccess = 0;
        $totalErrors = 0;
        
        foreach ($teams as $team) {
            $result = $this->playerService->syncTeamPlayers($team->football_data_id);
            $totalSuccess += $result['success'];
            $totalErrors += $result['errors'];
            
            // Rate limiting
            sleep(7);
        }
        
        return [
            'success' => $totalSuccess,
            'errors' => $totalErrors,
            'message' => "Synced players for {$teams->count()} teams: {$totalSuccess} success, {$totalErrors} errors"
        ];
    }

    /**
     * Generate match previews for featured matches
     */
    public function generateMatchPreviews(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type', 'featured'); // featured, live, today, all
            $force = $request->boolean('force', false);
            
            $matches = collect();
            
            switch ($type) {
                case 'live':
                    $matches = FootballMatch::live()->get();
                    break;
                case 'today':
                    $matches = FootballMatch::today()->get();
                    break;
                case 'featured':
                    $matches = FootballMatch::where('is_featured', true)
                        ->where('match_date', '>=', now()->subDays(1))
                        ->get();
                    break;
                case 'all':
                    $matches = FootballMatch::where('match_date', '>=', now()->subDays(7))
                        ->limit(20)
                        ->get();
                    break;
            }
            
            if ($matches->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matches found for the specified type'
                ]);
            }
            
            $results = $this->previewService->generateBatchPreviews($matches);
            
            return response()->json([
                'success' => true,
                'message' => "Generated {$results['success']} previews successfully",
                'data' => $results
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin preview generation failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Preview generation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get preview statistics
     */
    public function getPreviewStats(): JsonResponse
    {
        try {
            $stats = $this->previewService->getStats();
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get preview stats: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get preview statistics'
            ], 500);
        }
    }

    /**
     * Toggle preview featured status
     */
    public function togglePreviewFeatured(MatchPreview $preview): JsonResponse
    {
        try {
            $preview = $this->previewService->toggleFeatured($preview);
            
            return response()->json([
                'success' => true,
                'message' => 'Preview featured status updated',
                'data' => [
                    'is_featured' => $preview->is_featured
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to toggle preview featured status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update preview status'
            ], 500);
        }
    }

    /**
     * Regenerate preview for a specific match
     */
    public function regeneratePreview(FootballMatch $match): JsonResponse
    {
        try {
            $preview = $this->previewService->regeneratePreview($match);
            
            return response()->json([
                'success' => true,
                'message' => 'Preview regenerated successfully',
                'data' => [
                    'preview_id' => $preview->id,
                    'generated_at' => $preview->generated_at
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to regenerate preview: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate preview'
            ], 500);
        }
    }
}
