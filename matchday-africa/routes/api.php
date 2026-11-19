<?php

use App\Http\Controllers\Api\SocialQueueController;
use App\Http\Middleware\SocialQueueToken;
use Illuminate\Support\Facades\Route;

// The posting bot (stateless: no session, no CSRF). Bearer token SOCIAL_QUEUE_TOKEN; limits keyed by IP.
Route::get('/social-queue', [SocialQueueController::class, 'index'])
    ->middleware(['throttle:social-queue-read', SocialQueueToken::class])->name('api.social-queue.index');
Route::post('/social-queue/{id}/posted', [SocialQueueController::class, 'posted'])
    ->where('id', '[A-Za-z]+:[0-9]+')
    ->middleware(['throttle:social-queue-write', SocialQueueToken::class])->name('api.social-queue.posted');
// A long video from the bot: multipart mp4 → public/generated/videos/{id}/video.mp4, /reels/videos/{id}, queue item video:<id>.
Route::post('/social-queue/videos', [App\Http\Controllers\Api\SocialVideoController::class, 'store'])
    ->middleware(['throttle:social-queue-write', SocialQueueToken::class])->name('api.social-queue.videos');

// Matchday Radio for the posting bot: a day's published editions, mp3 and vertical mp4 (same bearer token).
Route::middleware(['throttle:social-queue-read', SocialQueueToken::class])->group(function () {
    Route::get('/radio-export', [App\Http\Controllers\Api\RadioExportController::class, 'index'])->name('api.radio-export.index');
    Route::get('/radio-export/{edition}/audio.mp3', [App\Http\Controllers\Api\RadioExportController::class, 'audio'])->whereNumber('edition')->name('api.radio-export.audio');
    Route::get('/radio-export/{edition}/video.mp4', [App\Http\Controllers\Api\RadioExportController::class, 'video'])->whereNumber('edition')->name('api.radio-export.video');
});

// The agent API (the social media assistant): bearer token from api_tokens (agent:token), per-token
// limits, every request audit-logged. Read content data, request renders, draft articles. docs/agent-api.md.
Route::prefix('agent')->name('api.agent.')->middleware(['throttle:agent-api', App\Http\Middleware\AgentApiToken::class])
    ->controller(App\Http\Controllers\Api\AgentController::class)->group(function () {
        Route::get('/matches', 'matches')->name('matches');
        Route::get('/players', 'players')->name('players');
        Route::get('/radio', 'radio')->name('radio');
        Route::get('/radio/{id}', 'radioEdition')->whereNumber('id')->name('radio.show');
        Route::get('/articles', 'articles')->name('articles');
        Route::get('/predictions', 'predictions')->name('predictions');
        Route::get('/analytics', 'analytics')->name('analytics');
        Route::get('/render/{id}', 'renderStatus')->where('id', '(story|slideshow):[0-9]+')->name('render.show');
        Route::get('/social-queue', 'socialQueue')->name('social-queue');
        Route::get('/breaking', 'breaking')->name('breaking');
        Route::middleware('throttle:agent-api-write')->group(function () {
            Route::post('/render', 'render')->name('render');
            Route::post('/social-queue/{id}/posted', 'socialQueuePosted')->where('id', '[A-Za-z]+:[0-9]+')->name('social-queue.posted');
            Route::post('/articles', 'storeArticle')->name('articles.store');
            Route::patch('/articles/{id}', 'updateArticle')->whereNumber('id')->name('articles.update');
            Route::post('/slideshows/{id}/approve', 'approveSlideshow')->whereNumber('id')->name('slideshows.approve');
            Route::post('/players/{id}/photo', 'playerPhoto')->whereNumber('id')->name('players.photo');
            Route::post('/player-photos', 'namedPlayerPhoto')->name('player-photos.store');
            Route::post('/radio', 'radioCreate')->name('radio.store');
            Route::post('/radio/{id}/retry', 'radioRetry')->whereNumber('id')->name('radio.retry');
            Route::post('/media', 'mediaStore')->name('media.store');
            Route::post('/breaking', 'breakingStore')->name('breaking.store');
            Route::patch('/breaking/{id}', 'breakingUpdate')->whereNumber('id')->name('breaking.update');
            Route::post('/breaking/{id}/end', 'breakingEnd')->whereNumber('id')->name('breaking.end');
        });
    });
