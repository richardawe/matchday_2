<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Adaptive football refresh cycle. cPanel only needs `php artisan schedule:run` every minute.
Schedule::command('matches:refresh upcoming')->everySixHours()->withoutOverlapping(30)->name('matches-upcoming');
Schedule::command('matches:refresh today')->everyFifteenMinutes()->withoutOverlapping(10)->name('matches-today');
Schedule::command('matches:refresh live')->everyMinute()->withoutOverlapping(3)->name('matches-live');
Schedule::command('matches:refresh results')->everyFiveMinutes()->withoutOverlapping(10)->name('matches-results');
Schedule::command('sync:standings --all')->dailyAt('03:10')->withoutOverlapping(60)->name('standings-nightly');
Schedule::command('sync:leagues --featured')->dailyAt('02:30')->withoutOverlapping(60)->name('leagues-daily');
Schedule::command('sync:players --all --limit=20')->weeklyOn(1, '04:00')->withoutOverlapping(120)->name('players-weekly');
Schedule::command('previews:generate-daily')->dailyAt('05:30')->withoutOverlapping(60)->name('previews-daily');
Schedule::command('predictions:score')->everyFiveMinutes()->withoutOverlapping(10)->name('predictions-score');
Schedule::command('news:publish-daily --limit=1')->dailyAt('07:30')->withoutOverlapping(30)->name('news-morning');
Schedule::command('news:publish-daily --limit=1')->dailyAt('12:00')->withoutOverlapping(30)->name('news-midday');
Schedule::command('news:publish-daily --limit=1')->dailyAt('16:30')->withoutOverlapping(30)->name('news-evening');
