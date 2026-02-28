<?php

namespace App\Services;

use App\Contracts\AgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AgentService
{
    private string $apiKey;

    private string $model = 'gpt-4o-mini';

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
                'description' => 'Your in-character dialogue for the public chat. Express opinions, suspicions, emotions, and reasoning in your character\'s voice. IMPORTANT: Do NOT narrate your vote or game action (e.g. never say "I vote to approve" or "I propose Alex and Sam" — the game already shows that). Instead, say WHY you feel the way you do, what you observe, who you trust or distrust. Keep it natural and conversational, 1-2 sentences.',
            ],
            'reasoning' => [
                'type' => 'string',
                'description' => 'Private internal reasoning — your true thoughts and strategy, not visible to others',
            ],
        ];
        
        $required = ['reasoning'];
        
        // Add phase-specific properties
        switch ($phase) {
            case 'team_proposal':
                $baseProperties['team_proposal'] = [
                    'type' => 'string',
                    'description' => 'ONLY if you are the leader: comma-separated list of player names for the team proposal. Example: "Max,Riley"',
                ];
                break;
                
            case 'team_voting':
                $baseProperties['vote'] = [
                    'type' => 'boolean',
                    'description' => 'Your vote on the proposed team. true = approve, false = reject',
                ];
                $required[] = 'vote';
                break;
                
            case 'mission':
                $baseProperties['mission_action'] = [
                    'type' => 'boolean',
                    'description' => 'ONLY if you are on the mission team: true = success, false = fail (evil only)',
                ];
                break;
                
            case 'assassination':
                $baseProperties['assassination_target'] = [
                    'type' => 'string',
                    'description' => 'ONLY if you are the Assassin: the name of the player you believe is Merlin',
                ];
                break;
        }
        
        return [
            'type' => 'object',
            'properties' => $baseProperties,
            'required' => $required,
        ];
    }
}
