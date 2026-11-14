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

// Matchday Radio for the posting bot: a day's published editions, mp3 and vertical mp4 (same bearer token).
Route::middleware(['throttle:social-queue-read', SocialQueueToken::class])->group(function () {
    Route::get('/radio-export', [App\Http\Controllers\Api\RadioExportController::class, 'index'])->name('api.radio-export.index');
    Route::get('/radio-export/{edition}/audio.mp3', [App\Http\Controllers\Api\RadioExportController::class, 'audio'])->whereNumber('edition')->name('api.radio-export.audio');
    Route::get('/radio-export/{edition}/video.mp4', [App\Http\Controllers\Api\RadioExportController::class, 'video'])->whereNumber('edition')->name('api.radio-export.video');
});
