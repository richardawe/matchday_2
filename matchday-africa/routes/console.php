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
// Matches still "live" 3 hours after kick-off with no update are closed (the first run cleans up the backlog).
Schedule::command('matches:close-stale')->everyTenMinutes()->withoutOverlapping(10)->name('matches-close-stale');
// ESPN feed (no-ops while ESPN_ENABLED=false): live passes every 20s inside each minute.
Schedule::command('espn:sync fixtures')->everySixHours()->withoutOverlapping(30)->runInBackground()->name('espn-fixtures');
Schedule::command('espn:sync live')->everyMinute()->withoutOverlapping(2)->runInBackground()->name('espn-live');
Schedule::command('reel:render')->everyMinute()->withoutOverlapping(30)->runInBackground()->name('griot-full-time-reels');
Schedule::command('buffer:publish')->everyMinute()->withoutOverlapping(10)->runInBackground()->name('buffer-social-posts');
Schedule::command('story:sync')->everyMinute()->withoutOverlapping(5)->name('griot-story-chapters');
Schedule::command('espn:sync details')->everyMinute()->withoutOverlapping(5)->runInBackground()->name('espn-details');
Schedule::command('sync:standings --all')->dailyAt('03:10')->withoutOverlapping(60)->name('standings-nightly');
Schedule::command('sync:leagues --featured')->dailyAt('02:30')->withoutOverlapping(60)->name('leagues-daily');
Schedule::command('sync:players --all --limit=20')->weeklyOn(1, '04:00')->withoutOverlapping(120)->name('players-weekly');
Schedule::command('previews:generate-daily')->dailyAt('05:30')->withoutOverlapping(60)->name('previews-daily');
Schedule::command('predictions:score')->everyFiveMinutes()->withoutOverlapping(10)->name('predictions-score');
Schedule::command('news:publish-daily --limit=1')->dailyAt('07:30')->withoutOverlapping(30)->name('news-morning');
Schedule::command('news:publish-daily --limit=1')->dailyAt('12:00')->withoutOverlapping(30)->name('news-midday');
Schedule::command('news:publish-daily --limit=1')->dailyAt('16:30')->withoutOverlapping(30)->name('news-evening');
// Three daily editions, not four: no midnight bulletin. Worker also services admin drafts.
Schedule::command('radio:edition')->cron('0 6,12,18 * * *')->timezone('Africa/Lagos')->withoutOverlapping(5)->name('radio-editions');
Schedule::command('radio:work')->everyMinute()->withoutOverlapping(30)->runInBackground()->name('radio-worker');
Schedule::command('twitter:engage')->everyTenMinutes()->withoutOverlapping(10)->name('x-timeline-planner');
Schedule::command('matches:enrich-events')->everyThirtyMinutes()->withoutOverlapping(20)->name('match-event-enrichment');
Schedule::command('statsbomb:import-open')->weeklyOn(1,'01:30')->withoutOverlapping(120)->name('statsbomb-open-data-weekly');
Schedule::command('matchday:send-digest')->dailyAt('08:00')->withoutOverlapping(30)->name('matchday-digest');
Schedule::call(function(){
    $service=app(\App\Services\PredictionNotificationService::class);
    $service->sendDeadlineReminders();
    $service->sendScoreUpdateNotifications();
})->hourlyAt(15)->name('prediction-engagement-notifications')->withoutOverlapping(15);
Schedule::call(fn()=>\App\Models\WarRoom::where('expires_at','<',now())->delete())->hourly()->name('war-clean-expired-rooms');
