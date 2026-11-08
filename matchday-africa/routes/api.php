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
