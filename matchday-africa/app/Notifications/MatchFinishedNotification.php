<?php

namespace App\Notifications;

use App\Models\FootballMatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MatchFinishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $match;

    /**
     * Create a new notification instance.
     */
    public function __construct(FootballMatch $match)
    {
        $this->match = $match;
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
        return (new MailMessage)
            ->subject("Match Finished: {$this->match->homeTeam->name} vs {$this->match->awayTeam->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("The match you predicted on has finished!")
            ->line("Match Result: {$this->match->home_score}-{$this->match->away_score}")
            ->line("Your predictions have been scored and points have been awarded.")
            ->action('View Your Results', url('/predictions/history'))
            ->action('View Leaderboard', url('/predictions/leaderboard'))
            ->line('Thank you for using Matchday Africa!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'match_finished',
            'match_id' => $this->match->id,
            'match_result' => "{$this->match->home_score}-{$this->match->away_score}",
            'home_team' => $this->match->homeTeam->name,
            'away_team' => $this->match->awayTeam->name,
            'league' => $this->match->league->name,
        ];
    }
}
