<?php

namespace App\Events;

use App\Models\FootballMatch;
use App\Models\UserPrediction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PredictionScored implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $match;
    public $prediction;

    /**
     * Create a new event instance.
     */
    public function __construct(FootballMatch $match, UserPrediction $prediction)
    {
        $this->match = $match;
        $this->prediction = $prediction;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->prediction->user_id),
            new Channel('predictions-scored'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'prediction_id' => $this->prediction->id,
            'match_id' => $this->match->id,
            'is_correct' => $this->prediction->is_correct,
            'points_earned' => $this->prediction->points_earned,
            'prediction_value' => $this->prediction->prediction_value,
            'match_result' => $this->match->home_score . '-' . $this->match->away_score,
            'home_team' => $this->match->homeTeam->name ?? 'Unknown',
            'away_team' => $this->match->awayTeam->name ?? 'Unknown',
            'scored_at' => now()->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'prediction.scored';
    }
}
