<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStateUpdate implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $connection = 'sync';
    private Game $game;

    public function __construct(Game $game)
    {
        $this->game = $game->load([
            'currentMission.teamMembers.player',
            'currentProposal.teamMembers.player',
            'currentProposal.votes',
            'missions.teamMembers',
            'missions.proposals'
        ]);
    }

    public function broadcastOn(): Channel
    {
        return new Channel('game.' . $this->game->id);
    }

    public function broadcastAs()
    {
        return 'GameStateUpdate';
    }

    public function broadcastWith()
    {
        return [
            'eventData' => $this->game->renderFullGameState(),
        ];
    }
}