<?php

namespace App\Services;

use App\Contracts\AgentService;

class RandomAgentService implements AgentService
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

        // Figure out what we're doing depending on the latest message that is a game event.
        $latestGameEventMessage = collect($messages)->reverse()->first(fn($m) => $m['role'] === 'system');
        $vote = null;
        $missionAction = null;
        $teamProposal = null;
        $content = $latestGameEventMessage['content'];
        $role = $latestGameEventMessage['role'];
        if (str_contains($content, 'You are the leader for this round. You need to propose a team')) {
            $teamProposal = 'Max,Riley';
        } else if (str_contains($content, 'You need to vote on the proposed team')) {
            $vote = (bool)random_int(0, 1);
        } else if (str_contains($content, 'You are on the mission team. You need to decide whether to support or sabotage the mission')) {
            $missionAction = (bool)random_int(0, 1);
        }

        return [
            'message' => $message,
            'reasoning' => 'No reason at all',
            'team_proposal' => $teamProposal,
            'vote' => $vote,
            'mission_action' => $missionAction,
        ];
    }
}