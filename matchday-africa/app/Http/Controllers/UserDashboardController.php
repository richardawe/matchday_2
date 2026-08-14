<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Services\PredictionService;
use App\Models\FootballMatch;

class UserDashboardController extends Controller
{
    public function __construct(
        private PredictionService $predictionService
    ) {}

    /**
     * User dashboard
     */
    public function dashboard(): View
    {
        $user = auth()->user();
        
        // Get user statistics
        $userStats = $this->predictionService->getUserStats($user);
        
        // Get recent matches (last 5)
        $recentMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->where('match_date', '>=', now()->subDays(7))
            ->orderBy('match_date', 'desc')
            ->limit(5)
            ->get();
        
        return view('dashboard', compact('userStats', 'recentMatches'));
    }
}
