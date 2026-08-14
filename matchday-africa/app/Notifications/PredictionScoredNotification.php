<?php

namespace App\Notifications;

use App\Models\FootballMatch;
use App\Models\UserPrediction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PredictionScoredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $match;
    protected $prediction;
    protected $isCorrect;

    /**
     * Create a new notification instance.
     */
    public function __construct(FootballMatch $match, UserPrediction $prediction, bool $isCorrect)
    {
        $this->match = $match;
        $this->prediction = $prediction;
        $this->isCorrect = $isCorrect;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $result = $this->isCorrect ? 'correct' : 'incorrect';
        $points = $this->prediction->points_earned;
        
        return (new MailMessage)
            ->subject("Your prediction was {$result}!")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your prediction for {$this->match->homeTeam->name} vs {$this->match->awayTeam->name} was {$result}!")
            ->line("Match Result: {$this->match->home_score}-{$this->match->away_score}")
            ->line("Your Prediction: {$this->prediction->prediction_value}")
            ->line("Points Earned: {$points}")
            ->action('View Leaderboard', url('/predictions/leaderboard'))
            ->line('Thank you for using Matchday Africa!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'prediction_scored',
            'match_id' => $this->match->id,
            'prediction_id' => $this->prediction->id,
            'is_correct' => $this->isCorrect,
            'points_earned' => $this->prediction->points_earned,
            'match_result' => "{$this->match->home_score}-{$this->match->away_score}",
            'prediction_value' => $this->prediction->prediction_value,
            'home_team' => $this->match->homeTeam->name,
            'away_team' => $this->match->awayTeam->name,
        ];
    }
}
