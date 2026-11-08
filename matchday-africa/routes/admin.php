<?php

use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\MatchController;
use App\Http\Controllers\Admin\MatchPreviewController;
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
    Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
    Route::get('/griot', [\App\Http\Controllers\Admin\GriotChallengeController::class, 'index'])->name('griot.index');
    Route::post('/griot/winners/{winner}/credit', [\App\Http\Controllers\Admin\GriotChallengeController::class, 'credit'])->name('griot.credit');
    Route::get('/player-photos', [\App\Http\Controllers\Admin\PlayerPhotoController::class, 'index'])->name('player-photos.index');
    Route::get('/youtube', [\App\Http\Controllers\Admin\YouTubeController::class, 'index'])->name('youtube.index');
    Route::get('/youtube/connect', [\App\Http\Controllers\Admin\YouTubeController::class, 'connect'])->name('youtube.connect');
    Route::get('/youtube/callback', [\App\Http\Controllers\Admin\YouTubeController::class, 'callback'])->name('youtube.callback');
    Route::post('/youtube/disconnect', [\App\Http\Controllers\Admin\YouTubeController::class, 'disconnect'])->name('youtube.disconnect');
    Route::post('/youtube/schedule', [\App\Http\Controllers\Admin\YouTubeController::class, 'schedule'])->name('youtube.schedule');
    Route::post('/youtube/test', [\App\Http\Controllers\Admin\YouTubeController::class, 'test'])->name('youtube.test');
    Route::get('/social-media', [\App\Http\Controllers\Admin\SocialMediaController::class, 'index'])->name('social-media.index');
    Route::post('/social-media', [\App\Http\Controllers\Admin\SocialMediaController::class, 'store'])->name('social-media.store');
    Route::get('/youtube/uploads', [\App\Http\Controllers\Admin\YouTubeController::class, 'uploads'])->name('youtube.uploads');
    Route::post('/youtube/uploads/{match}/retry', [\App\Http\Controllers\Admin\YouTubeController::class, 'retry'])->name('youtube.uploads.retry');
    Route::post('/griot/message', [\App\Http\Controllers\Admin\GriotChallengeController::class, 'message'])->name('griot.message');
    Route::post('/griot/{challenge}/message', [\App\Http\Controllers\Admin\GriotChallengeController::class, 'message'])->name('griot.message.round');
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
        Route::post('/{match}/events', [MatchController::class, 'storeEvent'])->name('events.store');
        Route::delete('/{match}/events/{event}', [MatchController::class, 'destroyEvent'])->name('events.destroy');
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
