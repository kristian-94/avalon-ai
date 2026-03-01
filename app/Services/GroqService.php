<?php

namespace App\Services;

use App\Contracts\AgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService implements AgentService
{
    private string $apiKey;

    private string $model;

    private string $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = env('GROQ_API_KEY', '');
        $this->model = env('AI_MODEL', 'llama-3.3-70b-versatile');
    }

    public function getChatResponse(array $messages): array
    {
        $messages = array_values($messages);

        $currentPhase = $this->extractCurrentPhase($messages);

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl, array_filter([
                    'model' => $this->model,
                    'messages' => $messages,
                    'tools' => [[
                        'type' => 'function',
                        'function' => [
                            'name' => 'game_response',
                            'description' => 'Respond to the current game situation',
                            'parameters' => $this->getPhaseSpecificParameters($currentPhase),
                        ],
                    ]],
                    'tool_choice' => [
                        'type' => 'function',
                        'function' => ['name' => 'game_response'],
                    ],
                    'reasoning_effort' => env('AI_REASONING_EFFORT') ?: null,
                    'temperature' => 0.7,
                    'max_tokens' => 300,
                ]));

            Log::info('Groq raw response', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if (! $response->successful()) {
                Log::error('Groq API Error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return $this->getFallbackResponse();
            }

            $responseData = $response->json();
            $toolCalls = $responseData['choices'][0]['message']['tool_calls'] ?? null;

            if (! $toolCalls || $toolCalls[0]['function']['name'] !== 'game_response') {
                Log::error('Groq API Invalid Response', [
                    'tool_calls' => $toolCalls,
                ]);

                return $this->getFallbackResponse();
            }

            $arguments = json_decode($toolCalls[0]['function']['arguments'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Groq API JSON Parse Error', [
                    'arguments' => $toolCalls[0]['function']['arguments'],
                    'error' => json_last_error_msg(),
                ]);

                return $this->getFallbackResponse();
            }

            $arguments['_usage'] = $responseData['usage'] ?? null;

            return $arguments;

        } catch (\Exception $e) {
            Log::error('Groq API Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->getFallbackResponse();
        }
    }

    private function getFallbackResponse(): array
    {
        return [
            'message' => '',
            'reasoning' => 'Encountered an issue processing my thoughts, taking a moment to reflect.',
            'vote' => null,
            'mission_action' => null,
        ];
    }

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
            'message' => [
                'type' => 'string',
                'description' => 'Your in-character dialogue for the public chat. Stay in your personality voice. Do NOT narrate your vote or game action (the game shows that automatically). Say WHY you feel the way you do. Keep it to 1 sentence, max 2. If you have nothing NEW to add, return empty string "". Silence is fine and often better than repeating yourself.',
            ],
            'reasoning' => [
                'type' => 'string',
                'description' => 'Private internal reasoning — your true thoughts and strategy, not visible to others',
            ],
        ];

        $required = ['reasoning'];

        switch ($phase) {
            case 'team_proposal_leader':
                $baseProperties['team_proposal'] = [
                    'type' => 'string',
                    'description' => 'Comma-separated list of player names for the team proposal. Example: "Max,Riley". You MUST propose a team now.',
                ];
                $required[] = 'team_proposal';
                break;

            case 'team_proposal':
                break;

            case 'team_voting':
                $baseProperties['vote'] = [
                    'type' => 'boolean',
                    'description' => 'Your vote on the proposed team. true = approve, false = reject',
                ];
                $required[] = 'vote';
                break;

            case 'team_voting_voted':
                break;

            case 'mission_on_team':
                $baseProperties['mission_action'] = [
                    'type' => 'boolean',
                    'description' => 'Your mission action. true = success, false = fail (evil only). You MUST choose now.',
                ];
                $required[] = 'mission_action';
                break;

            case 'mission':
                break;

            case 'assassination_assassin':
                $baseProperties['assassination_target'] = [
                    'type' => 'string',
                    'description' => 'The name of the player you believe is Merlin. You MUST choose a target now.',
                ];
                $required[] = 'assassination_target';
                break;

            case 'assassination':
                break;
        }

        return [
            'type' => 'object',
            'properties' => $baseProperties,
            'required' => $required,
        ];
    }
}
