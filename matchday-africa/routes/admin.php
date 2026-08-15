<?php

use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\MatchController;
use App\Http\Controllers\Admin\MatchPreviewController;
use App\Http\Controllers\Admin\PredictionController;
use App\Http\Controllers\Admin\PredictionSeasonController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Admin\CreatorController;
use App\Http\Controllers\Admin\CommerceController;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    
    // ============================================================================
    // DASHBOARD
    // ============================================================================
    Route::get('/', function() {
        return redirect()->route('admin.dashboard');
    })->name('index');
    
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/commerce', [CommerceController::class, 'index'])->name('commerce.index');
    Route::post('/commerce/sponsors', [CommerceController::class, 'sponsor'])->name('commerce.sponsors');
    Route::post('/commerce/products/{product}/toggle', [CommerceController::class, 'toggleProduct'])->name('commerce.products.toggle');
    Route::post('/commerce/earnings/{earning}/pay', [CommerceController::class, 'pay'])->name('commerce.earnings.pay');
    Route::get('/creators', [CreatorController::class, 'index'])->name('creators.index');
    Route::post('/creators/{creator}/approve', [CreatorController::class, 'approve'])->name('creators.approve');
    Route::post('/creators/{creator}/reject', [CreatorController::class, 'reject'])->name('creators.reject');
    Route::post('/creator-posts/{blog}/publish', [CreatorController::class, 'publish'])->name('creator-posts.publish');
    Route::post('/creator-posts/{blog}/return', [CreatorController::class, 'returnDraft'])->name('creator-posts.return');
    
    // ============================================================================
    // CONTENT MANAGEMENT
    // ============================================================================
    
    // Blog Management
    Route::prefix('blogs')->name('blogs.')->group(function () {
        Route::get('/', [BlogController::class, 'index'])->name('index');
        Route::get('/create', [BlogController::class, 'create'])->name('create');
        Route::post('/', [BlogController::class, 'store'])->name('store');
        Route::get('/{blog}', [BlogController::class, 'show'])->name('show');
        Route::get('/{blog}/edit', [BlogController::class, 'edit'])->name('edit');
        Route::put('/{blog}', [BlogController::class, 'update'])->name('update');
        Route::delete('/{blog}', [BlogController::class, 'destroy'])->name('destroy');
        Route::post('/{blog}/toggle-status', [BlogController::class, 'toggleStatus'])->name('toggle-status');
        Route::get('/{blog}/preview', [BlogController::class, 'preview'])->name('preview');
    });
    
    // Match Preview Management
    Route::prefix('match-previews')->name('match-previews.')->group(function () {
        Route::get('/', [MatchPreviewController::class, 'index'])->name('index');
        Route::post('/generate-daily', [MatchPreviewController::class, 'generateDaily'])->name('generate-daily');
        Route::post('/regenerate/{match}', [MatchPreviewController::class, 'regenerate'])->name('regenerate');
        Route::post('/toggle-featured/{preview}', [MatchPreviewController::class, 'toggleFeatured'])->name('toggle-featured');
        Route::delete('/{preview}', [MatchPreviewController::class, 'destroy'])->name('destroy');
        Route::get('/stats', [MatchPreviewController::class, 'stats'])->name('stats');
        Route::get('/available-dates', [MatchPreviewController::class, 'getAvailableDates'])->name('available-dates');
        Route::post('/force-regenerate-all', [MatchPreviewController::class, 'forceRegenerateAll'])->name('force-regenerate-all');
    });
    
    // ============================================================================
    // MATCH MANAGEMENT
    // ============================================================================
    
    // Match Management
    Route::prefix('matches')->name('matches.')->group(function () {
        Route::get('/', [MatchController::class, 'index'])->name('index');
        Route::get('/{match}', [MatchController::class, 'show'])->name('show');
        Route::post('/{match}/update-score', [MatchController::class, 'updateScore'])->name('update-score');
        Route::post('/{match}/force-score', [MatchController::class, 'forceScore'])->name('force-score');
        Route::post('/bulk-update-status', [MatchController::class, 'bulkUpdateStatus'])->name('bulk-update-status');
        Route::post('/auto-update-scores', [MatchController::class, 'autoUpdateScores'])->name('auto-update-scores');
        Route::post('/verify-all-scores', [MatchController::class, 'verifyAllScores'])->name('verify-all-scores');
        Route::get('/api/matches', [MatchController::class, 'getMatches'])->name('api.matches');
        Route::get('/api/stats', [MatchController::class, 'getStats'])->name('api.stats');
    });
    
    // ============================================================================
    // PREDICTION SYSTEM
    // ============================================================================
    
    // Prediction Sets Management
    Route::prefix('predictions')->name('predictions.')->group(function () {
        // Main prediction sets
        Route::get('/', [PredictionController::class, 'index'])->name('index');
        Route::get('/create', [PredictionController::class, 'create'])->name('create');
        Route::post('/', [PredictionController::class, 'store'])->name('store');
        Route::get('/season', [PredictionSeasonController::class, 'index'])->name('season.index');
        Route::post('/season', [PredictionSeasonController::class, 'store'])->name('season.store');
        
        // Specific routes (must come before parameterized routes)
        Route::get('/analytics', [PredictionController::class, 'analyticsDashboard'])->name('analytics');
        Route::get('/analytics/export', [PredictionController::class, 'exportAnalytics'])->name('analytics.export');
        Route::get('/transparency', [PredictionController::class, 'predictionsTransparency'])->name('transparency');
        Route::get('/match/{match}/predictions', [PredictionController::class, 'matchPredictionsDetail'])->name('match-detail');
        
        // Utilities
        Route::get('/available-matches', [PredictionController::class, 'getAvailableMatches'])->name('available-matches');
        Route::post('/score', [PredictionController::class, 'scorePredictions'])->name('score');
        Route::get('/scoring-stats', [PredictionController::class, 'getScoringStats'])->name('scoring-stats');
        
        // Individual prediction set management
        Route::get('/{prediction}', [PredictionController::class, 'show'])->name('show');
        Route::get('/{prediction}/edit', [PredictionController::class, 'edit'])->name('edit');
        Route::put('/{prediction}', [PredictionController::class, 'update'])->name('update');
        Route::delete('/{prediction}', [PredictionController::class, 'destroy'])->name('destroy');
        
        // Prediction set actions
        Route::get('/{prediction}/analytics', [PredictionController::class, 'analytics'])->name('prediction.analytics');
        Route::get('/{prediction}/export', [PredictionController::class, 'export'])->name('export');
        Route::post('/{prediction}/activate', [PredictionController::class, 'activate'])->name('activate');
        Route::post('/{prediction}/close', [PredictionController::class, 'close'])->name('close');
        Route::post('/{prediction}/archive', [PredictionController::class, 'archive'])->name('archive');
        Route::post('/{prediction}/rescore', [PredictionController::class, 'rescore'])->name('rescore');
    });
    
    // ============================================================================
    // DATA MANAGEMENT
    // ============================================================================
    
    // Data Synchronization
    Route::prefix('sync')->name('sync.')->group(function () {
        Route::get('/', [AdminController::class, 'syncIndex'])->name('index');
        Route::post('/leagues', [AdminController::class, 'syncLeagues'])->name('leagues');
        Route::post('/matches', [AdminController::class, 'syncMatches'])->name('matches');
        Route::post('/standings', [AdminController::class, 'syncStandings'])->name('standings');
        Route::post('/players', [AdminController::class, 'syncPlayers'])->name('players');
    });
    
    // ============================================================================
    // SYSTEM MANAGEMENT
    // ============================================================================
    
    // System Utilities
    Route::prefix('system')->name('system.')->group(function () {
        Route::post('/cache/clear', [AdminController::class, 'clearCache'])->name('cache.clear');
        Route::get('/api/status', [AdminController::class, 'apiStatus'])->name('api.status');
    });
    
    // Legacy routes for backward compatibility
    Route::post('/cache/clear', [AdminController::class, 'clearCache'])->name('cache.clear');
    Route::get('/api/status', [AdminController::class, 'apiStatus'])->name('api.status');
});
