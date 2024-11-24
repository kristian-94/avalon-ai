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
        $missions = $this->game->missions->map(function ($mission) {
            return [
                'id' => $mission->id,
                'name' => "Mission {$mission->mission_number}",
                'status' => $mission->status,
                'required' => $mission->required_players,
                'result' => $mission->status !== 'pending' ? [
                    'success' => $mission->status === 'success',
                    'team' => $mission->teamMembers->map(fn($tm) => $tm->player->name)->toArray(),
                    'votes' => [
                        'success' => $mission->success_votes,
                        'fail' => $mission->fail_votes
                    ]
                ] : null
            ];
        });

        $currentProposal = $this->game->currentProposal ? [
            'team' => $this->game->currentProposal->teamMembers->map(fn($tm) => $tm->player->name)->toArray(),
            'playerIndexes' => $this->game->currentProposal->teamMembers->map(fn($tm) => $tm->player->player_index)->toArray(),
            'votes' => $this->game->currentProposal->status !== 'pending'
                ? $this->game->currentProposal->votes->mapWithKeys(fn($vote) => [$vote->player_id => $vote->approved])->toArray()
                : null
        ] : null;

        return [
            'gameState' => [
                'currentPhase' => $this->game->current_phase,
                'turnCount' => $this->game->turn_count,
                'currentLeader' => $this->game->current_leader_id,
                'currentMission' => $this->game->currentMission ? [
                    'id' => $this->game->currentMission->id,
                    'required' => $this->game->currentMission->required_players,
                    'playerIndexes' => $this->game->currentMission->teamMembers->map(fn($tm) => $tm->player->player_index)->toArray(),
                    'team' => $this->game->currentMission->teamMembers->map(fn($tm) => $tm->player->name)->toArray()
                ] : null,
                'currentProposal' => $currentProposal,
                'missions' => $missions->toArray()
            ]
        ];
    }
}