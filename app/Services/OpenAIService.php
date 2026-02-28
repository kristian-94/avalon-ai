<?php

namespace App\Services;

use App\Contracts\AgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AgentService
{
    private string $apiKey;

    private string $model = 'gpt-4.1-mini';

    private string $baseUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = env('OPEN_AI_API_KEY');
    }

    public function getChatResponse(array $messages): array
    {
        $messages = array_values($messages);
        
        // Extract current phase from messages to customize function parameters
        $currentPhase = $this->extractCurrentPhase($messages);
        
        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl, [
                    'model' => $this->model,
                    'messages' => $messages,
                    'response_format' => ['type' => 'json_object'],
                    'functions' => [[
                        'name' => 'game_response',
                        'description' => 'Respond to the current game situation',
                        'parameters' => $this->getPhaseSpecificParameters($currentPhase),
                    ]],
                    'function_call' => ['name' => 'game_response'],
                    'temperature' => 0.7,
                    'max_tokens' => 500,
                ]);

            Log::info('OpenAI raw response', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if (! $response->successful()) {
                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return $this->getFallbackResponse();
            }

            $responseData = $response->json();
            $functionCall = $responseData['choices'][0]['message']['function_call'] ?? null;

            if (! $functionCall || $functionCall['name'] !== 'game_response') {
                Log::error('OpenAI API Invalid Response', [
                    'functionCall' => $functionCall,
                ]);

                return $this->getFallbackResponse();
            }

            $arguments = json_decode($functionCall['arguments'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('OpenAI API JSON Parse Error', [
                    'arguments' => $functionCall['arguments'],
                    'error' => json_last_error_msg(),
                ]);

                return $this->getFallbackResponse();
            }

            $arguments['_usage'] = $responseData['usage'] ?? null;

            return $arguments;

        } catch (\Exception $e) {
            Log::error('OpenAI API Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->getFallbackResponse();
        }
    }

    private function getFallbackResponse(): array
    {
        return [
            'message' => '', // Empty message to avoid spamming the chat
            'reasoning' => 'Encountered an issue processing my thoughts, taking a moment to reflect.',
            'vote' => null,
            'mission_action' => null,
        ];
    }
    
    private function extractCurrentPhase(array $messages): string
    {
        // Look for phase information in recent system messages
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
            'message' => [
                'type' => 'string',
                'description' => 'Your in-character dialogue for the public chat. Express opinions, suspicions, emotions, and reasoning in your character\'s voice. IMPORTANT: Do NOT narrate your vote or game action (e.g. never say "I vote to approve" or "I propose Alex and Sam" — the game already shows that). Instead, say WHY you feel the way you do, what you observe, who you trust or distrust. Keep it natural and conversational, 1-2 sentences. If you have nothing NEW to add — no fresh observations, no new information, no response to something someone said to you — return an empty string "". Do NOT rehash or rephrase points you or others already made. Silence is fine.',
            ],
            'reasoning' => [
                'type' => 'string',
                'description' => 'Private internal reasoning — your true thoughts and strategy, not visible to others',
            ],
        ];
        
        $required = ['reasoning'];
        
        // Add phase-specific properties
        // Phases ending in _must_act indicate the player MUST provide the action field.
        switch ($phase) {
            case 'team_proposal_leader':
                $baseProperties['team_proposal'] = [
                    'type' => 'string',
                    'description' => 'Comma-separated list of player names for the team proposal. Example: "Max,Riley". You MUST propose a team now.',
                ];
                $required[] = 'team_proposal';
                break;

            case 'team_proposal':
                // Non-leader during proposal phase — can only chat
                break;

            case 'team_voting':
                $baseProperties['vote'] = [
                    'type' => 'boolean',
                    'description' => 'Your vote on the proposed team. true = approve, false = reject',
                ];
                $required[] = 'vote';
                break;

            case 'team_voting_voted':
                // Player has already voted — they can chat but don't need to vote again
                break;

            case 'mission_on_team':
                $baseProperties['mission_action'] = [
                    'type' => 'boolean',
                    'description' => 'Your mission action. true = success, false = fail (evil only). You MUST choose now.',
                ];
                $required[] = 'mission_action';
                break;

            case 'mission':
                // Observer — not on mission team, can only chat
                break;

            case 'assassination_assassin':
                $baseProperties['assassination_target'] = [
                    'type' => 'string',
                    'description' => 'The name of the player you believe is Merlin. You MUST choose a target now.',
                ];
                $required[] = 'assassination_target';
                break;

            case 'assassination':
                // Non-assassin during assassination — can only chat
                break;
        }
        
        return [
            'type' => 'object',
            'properties' => $baseProperties,
            'required' => $required,
        ];
    }
}
