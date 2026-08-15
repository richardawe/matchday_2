<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeagueController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\SocialSharingController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\War\WarController;
use App\Http\Controllers\War\WarFixtureController;
use App\Http\Controllers\War\WarRoomController;
use App\Http\Controllers\War\WarGrowthController;
use App\Http\Controllers\War\WarAdminController;
use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\SupporterController;
use App\Http\Controllers\CreatorController;
use App\Http\Controllers\CommerceController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SponsorController;

// ============================================================================
// PUBLIC ROUTES
// ============================================================================

// Home page
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/api/matchday-pulse', [HomeController::class, 'pulse'])->name('home.pulse');

// Matchday War — native subdirectory experience
Route::prefix('war')->name('war.')->group(function () {
    Route::get('/', [WarController::class, 'index'])->name('index');
    Route::get('/match/{match}', [WarController::class, 'match'])->name('match');
    Route::get('/challenge/{match}', [WarController::class, 'challenge'])->name('challenge');
    Route::get('/api/fixtures', [WarFixtureController::class, 'index'])->name('api.fixtures');
    Route::get('/api/fixtures/{match}', [WarFixtureController::class, 'show'])->name('api.fixtures.show');
    Route::post('/api/rooms', [WarRoomController::class, 'store'])->name('api.rooms.store');
    Route::get('/api/rooms/{room}', [WarRoomController::class, 'show'])->name('api.rooms.show');
    Route::post('/api/rooms/{room}/join', [WarRoomController::class, 'join'])->name('api.rooms.join');
    Route::post('/api/rooms/{room}/start', [WarRoomController::class, 'start'])->name('api.rooms.start');
    Route::post('/api/rooms/{room}/action', [WarRoomController::class, 'action'])->name('api.rooms.action');
    Route::post('/api/growth/referral', [WarGrowthController::class, 'referral'])->name('api.growth.referral');
    Route::post('/api/growth/subscribe', [WarGrowthController::class, 'subscribe'])->name('api.growth.subscribe');
    Route::middleware(['auth','admin'])->prefix('api/admin')->name('api.admin.')->group(function () {
        Route::get('/campaigns', [WarGrowthController::class, 'campaigns'])->name('campaigns');
        Route::post('/campaigns/generate', [WarGrowthController::class, 'generate'])->name('campaigns.generate');
        Route::post('/campaigns/{campaign}', [WarGrowthController::class, 'update'])->name('campaigns.update');
    });
});

Route::middleware(['auth','admin'])->get('/admin/war', [WarAdminController::class, 'index'])->name('admin.war');

// League routes
Route::get('/leagues', [LeagueController::class, 'index'])->name('leagues.index');
Route::get('/leagues/{league}', [LeagueController::class, 'show'])->name('leagues.show');
Route::get('/leagues/{league}/standings', [LeagueController::class, 'standings'])->name('leagues.standings');

// Match routes
Route::get('/matches', [MatchController::class, 'index'])->name('matches.index');
Route::get('/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
Route::get('/matches/enhanced', [App\Http\Controllers\EnhancedMatchController::class, 'index'])->name('matches.enhanced');
Route::get('/matches/enhanced/{match}', [App\Http\Controllers\EnhancedMatchController::class, 'show'])->name('matches.enhanced.show');

// Team routes
Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
Route::get('/teams/{team}/squad', [TeamController::class, 'squad'])->name('teams.squad');

// Blog routes
Route::get('/blogs', [\App\Http\Controllers\BlogController::class, 'index'])->name('blogs.index');
Route::get('/media/blog/{filename}', [\App\Http\Controllers\BlogController::class, 'image'])
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('blogs.image');
Route::get('/blogs/{blog:slug}', [\App\Http\Controllers\BlogController::class, 'show'])->name('blogs.show');
Route::get('/african-players', [DiscoveryController::class, 'index'])->name('discovery.index');
Route::get('/african-players/{player}', [DiscoveryController::class, 'show'])->name('discovery.show');
Route::get('/supporters/{user}', [SupporterController::class, 'show'])->name('supporters.show');
Route::get('/creators/{creator:slug}', [CreatorController::class, 'show'])->name('creators.show');
Route::get('/shop', [CommerceController::class, 'shop'])->name('shop.index');
Route::get('/premium', [CommerceController::class, 'premium'])->name('premium.index');
Route::get('/sponsors/{sponsor}/go', [SponsorController::class, 'click'])->name('sponsors.click');
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Chat routes (viewing is public)
Route::get('/matches/{match}/chats', [ChatController::class, 'getMessages'])->name('matches.chats');

// GIF routes (public for browsing)
Route::get('/gifs/search', [ChatController::class, 'searchGifs'])->name('gifs.search');
Route::get('/gifs/trending', [ChatController::class, 'getTrendingGifs'])->name('gifs.trending');
Route::get('/gifs/football', [ChatController::class, 'getFootballGifs'])->name('gifs.football');
Route::get('/users/search', [ChatController::class, 'searchUsers'])->name('users.search');

// Social Sharing routes
Route::get('/share/{type}/{id}/{platform}', [SocialSharingController::class, 'share'])->name('social.share');
Route::get('/api/share-counts/{type}/{id}', [SocialSharingController::class, 'getShareCounts'])->name('social.share-counts');
Route::get('/api/popular-content', [SocialSharingController::class, 'getPopularContent'])->name('social.popular-content');

// ============================================================================
// AUTHENTICATED USER ROUTES
// ============================================================================

Route::middleware('auth')->group(function () {
    Route::get('/checkout/success', [CommerceController::class, 'success'])->name('commerce.success');
    Route::post('/checkout/{product:slug}', [CommerceController::class, 'checkout'])->name('commerce.checkout');
    Route::get('/library', [CommerceController::class, 'library'])->name('library.index');
    Route::get('/library/{product:slug}/download', [CommerceController::class, 'download'])->name('library.download');
    Route::patch('/supporter-identity', [SupporterController::class, 'updateIdentity'])->name('supporter.identity');
    Route::post('/supporter/daily-flame', [SupporterController::class, 'claimDaily'])->name('supporter.daily');
    Route::post('/creator/apply', [SupporterController::class, 'applyCreator'])->name('creator.apply');
    Route::get('/creator-studio', [CreatorController::class, 'studio'])->name('creator.studio');
    Route::post('/creator-studio', [CreatorController::class, 'submit'])->name('creator.submit');
    Route::get('/onboarding', [ExperienceController::class, 'onboarding'])->name('onboarding');
    Route::post('/onboarding/teams', [ExperienceController::class, 'saveTeams'])->name('onboarding.teams');
    Route::post('/teams/{team}/follow', [ExperienceController::class, 'toggleTeam'])->name('teams.follow');
    Route::get('/prediction-leagues', [ExperienceController::class, 'groups'])->name('groups.index');
    Route::post('/prediction-leagues', [ExperienceController::class, 'createGroup'])->name('groups.store');
    Route::post('/prediction-leagues/join', [ExperienceController::class, 'joinGroup'])->name('groups.join');
    Route::get('/prediction-leagues/{group}', [ExperienceController::class, 'group'])->name('groups.show');
    Route::get('/notification-settings', [ExperienceController::class, 'settings'])->name('notification-settings');
    Route::put('/notification-settings', [ExperienceController::class, 'saveSettings'])->name('notification-settings.update');
    // User dashboard
    Route::get('/dashboard', [App\Http\Controllers\UserDashboardController::class, 'dashboard'])
        ->middleware('verified')
        ->name('dashboard');
    
    // Profile management
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Chat posting (requires auth)
    Route::post('/matches/{match}/chats', [ChatController::class, 'storeMessage'])->name('matches.chats.store');
    
    // User prediction routes
    Route::prefix('predictions')->name('predictions.')->group(function () {
        // Specific routes first (to avoid conflicts)
        Route::get('/', [PredictionController::class, 'index'])->name('index');
        Route::get('/history', [PredictionController::class, 'history'])->name('history');
        Route::get('/leaderboard', [PredictionController::class, 'leaderboard'])->name('leaderboard');
        
        // General prediction routes
        Route::get('/{prediction}', [PredictionController::class, 'show'])->name('show');
        Route::post('/{prediction}/submit', [PredictionController::class, 'submit'])->name('submit');
        Route::put('/{prediction}/update', [PredictionController::class, 'update'])->name('update');
    });
    
    // Prediction API endpoints
    Route::prefix('api/predictions')->name('api.predictions.')->group(function () {
        Route::get('/available', [PredictionController::class, 'getAvailable'])->name('available');
        Route::get('/{prediction}/user-predictions', [PredictionController::class, 'getUserPredictions'])->name('user-predictions');
        Route::get('/leaderboard', [PredictionController::class, 'getLeaderboard'])->name('leaderboard');
        Route::get('/stats', [PredictionController::class, 'stats'])->name('stats');
    });
});

Route::post('/api/analytics', [ExperienceController::class, 'track'])->name('analytics.track');

// ============================================================================
// ADMIN ROUTES
// ============================================================================

require __DIR__.'/admin.php';

// ============================================================================
// AUTHENTICATION ROUTES
// ============================================================================

require __DIR__.'/auth.php';

// ============================================================================
// API ROUTES
// ============================================================================

// OAuth Configuration API
Route::get('/api/oauth-config', function () {
    return response()->json([
        'google_client_id' => config('services.google.client_id'),
        'twitter_client_id' => config('services.twitter.client_id'),
        'app_url' => config('app.url'),
    ]);
});

// CSRF Token API
Route::get('/api/csrf-token', function () {
    return response()->json([
        'csrf_token' => csrf_token()
    ]);
});

// OAuth Callback Route
Route::get('/oauth-callback', function () {
    return view('oauth-callback');
});

// Odds routes
Route::get('/odds', function () {
    return view('odds.index');
})->name('odds.index');

// Odds API routes
Route::prefix('api/odds')->group(function () {
    Route::get('/epl/weekend', [App\Http\Controllers\OddsController::class, 'eplWeekend'])->name('odds.epl.weekend');
    Route::get('/epl/upcoming', [App\Http\Controllers\OddsController::class, 'upcoming'])->name('odds.epl.upcoming');
    Route::get('/match/{eventId}', [App\Http\Controllers\OddsController::class, 'matchOdds'])->name('odds.match');
});

// Twitter OAuth 2.0 routes
Route::get('/twitter/oauth/authorize', [App\Http\Controllers\TwitterOAuthController::class, 'authorize'])->name('twitter.oauth.authorize');
Route::get('/auth/twitter/callback', [App\Http\Controllers\TwitterOAuthController::class, 'callback'])->name('twitter.oauth.callback');
Route::post('/twitter/oauth/revoke', [App\Http\Controllers\TwitterOAuthController::class, 'revoke'])->name('twitter.oauth.revoke');

// Twitter admin routes (admin only)
Route::middleware(['auth', 'admin'])->prefix('admin/twitter')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\TwitterController::class, 'index'])->name('admin.twitter.index');
    Route::post('/tweet-matches', [App\Http\Controllers\Admin\TwitterController::class, 'tweetMatches'])->name('admin.twitter.tweet-matches');
    Route::post('/test-connection', [App\Http\Controllers\Admin\TwitterController::class, 'testConnection'])->name('admin.twitter.test-connection');
    Route::post('/send-test-tweet', [App\Http\Controllers\Admin\TwitterController::class, 'sendTestTweet'])->name('admin.twitter.send-test-tweet');
    Route::post('/authorize', [App\Http\Controllers\Admin\TwitterController::class, 'authorize'])->name('admin.twitter.authorize');
});
