<?php

namespace App\Services;

use App\Contracts\AgentService;

class RandomAgentService implements AgentService
{
    public function getChatResponse(array $messages): array
    {
        $phase = $this->extractPhase($messages);
        $players = $this->extractPlayerNames($messages);

        return match ($phase) {
            'team_proposal' => $this->handleProposal($messages, $players),
            'team_voting'   => ['reasoning' => 'Random vote.', 'vote' => (bool) random_int(0, 1)],
            'mission'       => ['reasoning' => 'Playing mission card.', 'mission_action' => true],
            'assassination' => $this->handleAssassination($players),
            default         => ['reasoning' => 'Observing.', 'message' => '.'],
        };
    }

    private function extractPhase(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            $content = $message['content'] ?? '';
            if (preg_match('/Phase:\s*(\w+)/', $content, $matches)) {
                return $matches[1];
            }
        }

        return 'unknown';
    }

    private function extractPlayerNames(array $messages): array
    {
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            // System prompt format: "playing in a game of ... with N players: name1, name2, ..."
            if (preg_match('/with \d+ players:\s*([^.]+)\./', $content, $matches)) {
                return array_map('trim', explode(',', $matches[1]));
            }
        }

        return [];
    }

    private function handleProposal(array $messages, array $players): array
    {
        $required = 2;
        foreach (array_reverse($messages) as $message) {
            $content = $message['content'] ?? '';
            if (preg_match('/requires (\d+) players/', $content, $matches)) {
                $required = (int) $matches[1];
                break;
            }
        }

        if (empty($players)) {
            return ['reasoning' => 'No player info available.'];
        }

        shuffle($players);
        $team = array_slice($players, 0, $required);

        return [
            'reasoning'     => 'Random team selection.',
            'team_proposal' => implode(',', $team),
        ];
    }

    private function handleAssassination(array $players): array
    {
        if (empty($players)) {
            return ['reasoning' => 'No targets available.'];
        }

        return [
            'reasoning'            => 'Random assassination target.',
            'assassination_target' => $players[array_rand($players)],
        ];
    }
}
