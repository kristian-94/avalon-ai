<?php

namespace App\Services;

trait AgentResponseSchema
{
    private function extractCurrentPhase(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message['role'] === 'system' && str_contains($message['content'], 'Phase:')) {
                if (preg_match('/Phase:\s*(\w+)/', $message['content'], $matches)) {
                    return $matches[1];
                }
            }
        }

        return 'unknown';
    }

    private function getPhaseSpecificParameters(string $phase): array
    {
        $baseProperties = [
            'reasoning' => [
                'type' => 'string',
                'description' => 'Private internal reasoning — think through the game state, weigh your options, and reach a conclusion. Do this FIRST before deciding your message or action.',
            ],
            'message' => [
                'type' => 'string',
                'description' => 'Your in-character public chat. Must match your reasoning above AND the action you are about to take. IMPORTANT: do not say you suspect person A and then vote/target person B — your chat and your action must be consistent with each other. Stay in your personality voice. Do NOT narrate your vote or game action (the game shows that automatically). Say WHY you feel the way you do. Keep it to 1 sentence, max 2. If you have nothing NEW to add, return empty string "". Silence is fine and often better than repeating yourself.',
            ],
        ];

        $required = ['reasoning'];

        switch ($phase) {
            case 'evil_discussion_evil':
                $baseProperties['message']['description'] = 'Speak in character. Share who you suspect might be Merlin and why — reason aloud from the game record. You are speaking publicly but framing it as deduction, not as an evil player. Your reasoning field should identify your actual best guess at Merlin.';
                break;

            case 'team_proposal_leader':
                $baseProperties['team_proposal'] = [
                    'type' => 'string',
                    'description' => 'Comma-separated list of player names for your team proposal. Example: "Max,Riley". Decide based on your reasoning above. Your message should reflect this choice.',
                ];
                $required[] = 'team_proposal';
                break;

            case 'team_voting':
                $baseProperties['vote'] = [
                    'type' => 'boolean',
                    'description' => 'Your vote: true = approve, false = reject. Must match your reasoning and message above.',
                ];
                $required[] = 'vote';
                break;

            case 'mission_on_team':
                $baseProperties['mission_action'] = [
                    'type' => 'boolean',
                    'description' => 'Your mission vote: true = success, false = fail. Evil players may choose fail to sabotage. Must match your reasoning above.',
                ];
                $required[] = 'mission_action';
                break;

            case 'assassination_assassin':
                $baseProperties['message']['description'] = 'You already spoke during the evil discussion. Your message here should be a brief final declaration (e.g. "I\'m going with [name].") — or empty string "" if you have nothing new to add. Do NOT repeat what you said in the evil discussion.';
                $baseProperties['assassination_target'] = [
                    'type' => 'string',
                    'description' => 'The player name you believe is Merlin. Think carefully in your reasoning above — who has been making suspiciously accurate reads? Who steered the team most effectively? This field must match your final decision.',
                ];
                $required[] = 'assassination_target';
                break;
        }

        return [
            'type' => 'object',
            'properties' => $baseProperties,
            'required' => $required,
        ];
    }
}
