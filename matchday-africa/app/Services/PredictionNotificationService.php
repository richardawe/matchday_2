<?php

namespace App\Services;

use App\Models\PredictionSet;
use App\Models\User;
use App\Models\UserPrediction;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PredictionNotificationService
{
    /**
     * Send deadline reminder notifications
     */
    public function sendDeadlineReminders(): int
    {
        $predictionSets = PredictionSet::where('status', 'active')
            ->where('prediction_deadline', '>', now())
            ->where('prediction_deadline', '<=', now()->addHours(24))
            ->get();

        $sentCount = 0;

        foreach ($predictionSets as $predictionSet) {
            $users = $this->getUsersToNotify($predictionSet);
            
            foreach ($users as $user) {
                try {
                    $this->sendDeadlineReminder($user, $predictionSet);
                    $sentCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to send deadline reminder', [
                        'user_id' => $user->id,
                        'prediction_set_id' => $predictionSet->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $sentCount;
    }

    /**
     * Send score update notifications
     */
    public function sendScoreUpdateNotifications(): int
    {
        $recentlyFinishedMatches = $this->getRecentlyFinishedMatches();
        $sentCount = 0;

        foreach ($recentlyFinishedMatches as $match) {
            $predictions = UserPrediction::where('match_id', $match->id)
                ->whereNull('is_correct')
                ->with(['user', 'predictionSet'])
                ->get();

            foreach ($predictions as $prediction) {
                try {
                    $this->sendScoreUpdateNotification($prediction->user, $prediction, $match);
                    $sentCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to send score update notification', [
                        'user_id' => $prediction->user->id,
                        'prediction_id' => $prediction->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $sentCount;
    }

    /**
     * Send prediction set created notification to users
     */
    public function sendPredictionSetCreatedNotification(PredictionSet $predictionSet): int
    {
        $users = User::whereHas('predictions')->get();
        $sentCount = 0;

        foreach ($users as $user) {
            try {
                $this->sendNewPredictionSetNotification($user, $predictionSet);
                $sentCount++;
            } catch (\Exception $e) {
                Log::error('Failed to send new prediction set notification', [
                    'user_id' => $user->id,
                    'prediction_set_id' => $predictionSet->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $sentCount;
    }

    /**
     * Send leaderboard update notifications
     */
    public function sendLeaderboardUpdateNotifications(?PredictionSet $predictionSet = null): int
    {
        $leaderboard = $this->getTopPerformers($predictionSet, 10);
        $sentCount = 0;

        foreach ($leaderboard as $entry) {
            try {
                $this->sendLeaderboardUpdateNotification($entry->user, $entry, $predictionSet);
                $sentCount++;
            } catch (\Exception $e) {
                Log::error('Failed to send leaderboard update notification', [
                    'user_id' => $entry->user->id,
                    'leaderboard_id' => $entry->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $sentCount;
    }

    /**
     * Get users to notify for deadline reminders
     */
    private function getUsersToNotify(PredictionSet $predictionSet): \Illuminate\Database\Eloquent\Collection
    {
        // Get users who haven't submitted predictions yet
        $usersWithPredictions = $predictionSet->userPredictions()
            ->pluck('user_id')
            ->toArray();

        return User::whereNotIn('id', $usersWithPredictions)
            ->where('email_verified_at', '!=', null)
            ->get();
    }

    /**
     * Get recently finished matches
     */
    private function getRecentlyFinishedMatches(): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\FootballMatch::where('status', 'FINISHED')
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->get();
    }

    /**
     * Get top performers for notifications
     */
    private function getTopPerformers(?PredictionSet $predictionSet = null, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        $query = \App\Models\PredictionLeaderboard::with('user')
            ->where('period', 'all_time');

        if ($predictionSet) {
            $query->where('prediction_set_id', $predictionSet->id);
        } else {
            $query->whereNull('prediction_set_id');
        }

        return $query->orderBy('total_points', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Send deadline reminder notification
     */
    private function sendDeadlineReminder(User $user, PredictionSet $predictionSet): void
    {
        $hoursUntilDeadline = now()->diffInHours($predictionSet->prediction_deadline, false);
        
        $message = "Reminder: Prediction set '{$predictionSet->name}' deadline is in {$hoursUntilDeadline} hours!";
        
        // In a real implementation, you would use Laravel's notification system
        // For now, we'll just log it
        Log::info('Deadline reminder sent', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'prediction_set_id' => $predictionSet->id,
            'message' => $message
        ]);
    }

    /**
     * Send score update notification
     */
    private function sendScoreUpdateNotification(User $user, UserPrediction $prediction, \App\Models\FootballMatch $match): void
    {
        $isCorrect = $prediction->is_correct ? 'correct' : 'incorrect';
        $message = "Your prediction for {$match->homeTeam->name} vs {$match->awayTeam->name} was {$isCorrect}!";
        
        Log::info('Score update notification sent', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'prediction_id' => $prediction->id,
            'match_id' => $match->id,
            'message' => $message
        ]);
    }

    /**
     * Send new prediction set notification
     */
    private function sendNewPredictionSetNotification(User $user, PredictionSet $predictionSet): void
    {
        $message = "New prediction set '{$predictionSet->name}' is now available! Deadline: {$predictionSet->prediction_deadline->format('M j, Y H:i')}";
        
        Log::info('New prediction set notification sent', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'prediction_set_id' => $predictionSet->id,
            'message' => $message
        ]);
    }

    /**
     * Send leaderboard update notification
     */
    private function sendLeaderboardUpdateNotification(User $user, \App\Models\PredictionLeaderboard $entry, ?PredictionSet $predictionSet = null): void
    {
        $setName = $predictionSet ? $predictionSet->name : 'Global';
        $message = "You're ranked #{$entry->rank} on the {$setName} leaderboard with {$entry->total_points} points!";
        
        Log::info('Leaderboard update notification sent', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'leaderboard_id' => $entry->id,
            'rank' => $entry->rank,
            'message' => $message
        ]);
    }

    /**
     * Send admin notification for system events
     */
    public function sendAdminNotification(string $event, array $data = []): void
    {
        $admins = User::whereHas('predictionSets')->get();
        
        foreach ($admins as $admin) {
            try {
                $this->sendSystemEventNotification($admin, $event, $data);
            } catch (\Exception $e) {
                Log::error('Failed to send admin notification', [
                    'admin_id' => $admin->id,
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Send system event notification to admin
     */
    private function sendSystemEventNotification(User $admin, string $event, array $data): void
    {
        $message = "System Event: {$event} - " . json_encode($data);
        
        Log::info('Admin notification sent', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'event' => $event,
            'message' => $message
        ]);
    }
}
