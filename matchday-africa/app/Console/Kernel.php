<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Sync today's matches every 15 minutes
        $schedule->call(function () {
            try {
                $matchService = app(\App\Services\MatchService::class);
                $result = $matchService->syncTodaysMatches();
                \Illuminate\Support\Facades\Log::info('Scheduled match sync completed', $result);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Scheduled match sync failed: ' . $e->getMessage());
            }
        })->everyFifteenMinutes()->name('sync-todays-matches');

        // A failed provider sync must never strand an old game as live.
        $schedule->call(function () {
            \App\Models\FootballMatch::whereIn('status', \App\Models\FootballMatch::LIVE_STATUSES)
                ->where('match_date', '<', now()->subHours(6))
                ->update(['status' => 'FINISHED']);
        })->everyFifteenMinutes()->name('reconcile-stale-live-matches');

        // Sync matches every hour during peak hours (14:00-23:00 UTC)
        $schedule->call(function () {
            try {
                $matchService = app(\App\Services\MatchService::class);
                $result = $matchService->syncTodaysMatches();
                \Illuminate\Support\Facades\Log::info('Hourly matches sync completed', $result);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Hourly matches sync failed: ' . $e->getMessage());
            }
        })->hourly()->between('14:00', '23:00')->name('sync-matches-hourly');

        // Sync league standings once daily at 3 AM
        $schedule->call(function () {
            try {
                $standingService = app(\App\Services\StandingService::class);
                $result = $standingService->syncAllLeagueStandings();
                \Illuminate\Support\Facades\Log::info('Scheduled standings sync completed', $result);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Scheduled standings sync failed: ' . $e->getMessage());
            }
        })->dailyAt('03:00')->name('sync-standings');

        // Note: TeamService sync requires specific league IDs, so we'll handle this in individual commands

        // Sync player squads once weekly on Sunday at 4 AM
        $schedule->call(function () {
            try {
                $playerService = app(\App\Services\PlayerService::class);
                $result = $playerService->syncAllTeamPlayers();
                \Illuminate\Support\Facades\Log::info('Scheduled players sync completed', $result);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Scheduled players sync failed: ' . $e->getMessage());
            }
        })->weekly()->sundays()->at('04:00')->name('sync-players');

        // Clean up old logs weekly
        $schedule->command('log:clear')->weekly()->name('cleanup-logs');

        // Cache clear daily at midnight
        $schedule->command('cache:clear')->dailyAt('00:00')->name('clear-cache');

        // Score predictions every 5 minutes
        $schedule->command('predictions:score')->everyFiveMinutes()->name('score-predictions');
        $schedule->command('matchday:send-digest')->dailyAt('08:00')->name('weekly-matchday-digest')->withoutOverlapping();

        // Send prediction notifications every hour
        $schedule->call(function () {
            try {
                $notificationService = app(\App\Services\PredictionNotificationService::class);
                $deadlineReminders = $notificationService->sendDeadlineReminders();
                $scoreUpdates = $notificationService->sendScoreUpdateNotifications();
                \Illuminate\Support\Facades\Log::info('Prediction notifications sent', [
                    'deadline_reminders' => $deadlineReminders,
                    'score_updates' => $scoreUpdates
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Prediction notifications failed: ' . $e->getMessage());
            }
        })->hourly()->name('send-prediction-notifications');

        // Tweet match links daily at 8 AM for today's matches
        $schedule->command('twitter:tweet-matches --individual --summary')
            ->dailyAt('08:00')
            ->name('tweet-daily-matches')
            ->withoutOverlapping();

        // Tweet match links 2 hours before each match (if matches are found)
        $schedule->call(function () {
            try {
                $twitterService = app(\App\Services\TwitterService::class);
                $matchService = app(\App\Services\MatchService::class);
                
                // Get matches starting in the next 2 hours
                $upcomingMatches = \App\Models\FootballMatch::with(['homeTeam', 'awayTeam'])
                    ->where('status', 'SCHEDULED')
                    ->whereBetween('match_date', [now(), now()->addHours(2)])
                    ->get();
                
                if ($upcomingMatches->isNotEmpty()) {
                    foreach ($upcomingMatches as $match) {
                        $matchUrl = route('matches.show', $match->id);
                        $tweetText = $twitterService->formatMatchTweet([
                            'home_team' => $match->homeTeam->name ?? 'TBD',
                            'away_team' => $match->awayTeam->name ?? 'TBD',
                            'competition' => $match->competition ?? 'Premier League',
                            'kickoff' => $match->match_date
                        ], $matchUrl);
                        
                        $result = $twitterService->postTweet($tweetText);
                        \Illuminate\Support\Facades\Log::info('Pre-match tweet posted', [
                            'match_id' => $match->id,
                            'tweet_result' => $result
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Pre-match tweets failed: ' . $e->getMessage());
            }
        })->everyThirtyMinutes()->name('tweet-pre-match-reminders');

        // Remove abandoned two-player War rooms after their reconnect window.
        $schedule->call(fn()=>\App\Models\WarRoom::where('expires_at','<',now())->delete())
            ->hourly()->name('war-clean-expired-rooms');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
