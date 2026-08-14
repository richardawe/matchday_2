<?php

namespace App\Events;

use App\Models\FootballMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MatchScoreUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $match;

    /**
     * Create a new event instance.
     */
    public function __construct(FootballMatch $match)
    {
        $this->match = $match;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('match-scores'),
            new Channel('match-' . $this->match->id),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'match_id' => $this->match->id,
            'home_team' => $this->match->homeTeam->name ?? 'Unknown',
            'away_team' => $this->match->awayTeam->name ?? 'Unknown',
            'home_score' => $this->match->home_score,
            'away_score' => $this->match->away_score,
            'status' => $this->match->status,
            'minute' => $this->match->minute,
            'league' => $this->match->league->name ?? 'Unknown',
            'updated_at' => $this->match->updated_at->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'score.updated';
    }
}
