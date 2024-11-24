<?php

namespace App\Services;

use App\Contracts\AgentService;
use App\Models\Game;

class BasicAgentService implements AgentService
{
    private array $quickResponses = [
        "I agree with that.",
        "That seems suspicious...",
        "Let's see how this goes.",
        "I'm not sure about this.",
        "Interesting choice.",
        "We should be careful here.",
        "That makes sense to me.",
        "I have my doubts about that.",
        "Let's give it a try.",
        "We need to think this through."
    ];

    public function getChatResponse(array $messages): array
    {
        $message = $this->quickResponses[array_rand($this->quickResponses)];

        $initialMessage = collect($messages)->first(fn($m) => $m['role'] === 'system');
        $content = $initialMessage['content'];
        $role = $initialMessage['role'];

        // Figure out if we are evil or good.
        $isEvil = str_contains($content, 'You are the Assassin') || str_contains($content, 'You are a Minion of Mordred');

        // Get latest game.
        $game = Game::orderBy('id', 'desc')->first();
        $currentMission = $game->currentMission;

        $teamProposal = '';
        if ($currentMission) {
            $required = $currentMission->required_players;
            if ($required === 2) {
                $teamProposal = 'Max,Riley';
            } elseif ($required === 3) {
                $teamProposal = 'Max,Riley,Alex';
            } else {
                $teamProposal = 'Max,Riley,Alex,Sam';
            }
        }

        $vote = true; // Always vote yes for any team for now.
        $missionAction = !$isEvil; // Always succeed if we are good.

        return [
            'message' => $message,
            'reasoning' => 'No reason at all',
            'team_proposal' => $teamProposal,
            'vote' => $vote,
            'mission_action' => $missionAction,
            'assassination_target' => 'Max',
        ];
    }
}