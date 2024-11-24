<?php

namespace App\Services;

use App\Contracts\AgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AgentService
{
    private string $apiKey;
    private string $model = 'gpt-3.5-turbo-1106';
    private string $baseUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = env('OPEN_AI_API_KEY');
    }

    public function getChatResponse(array $messages): array
    {
        $messages = array_values($messages);
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
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => [
                                    'type' => 'string',
                                    'description' => 'The message to be displayed in public chat'
                                ],
                                'vote' => [
                                    'type' => 'boolean',
                                    'description' => 'Optional vote decision'
                                ],
                                'team_proposal' => [
                                    'type' => 'string',
                                    'description' => 'Comma separated list of player names for the team proposal, no spaces. Example: "Max,Riley"'
                                ],
                                'assassination_target' => [
                                    'type' => 'string',
                                    'description' => 'A single player name to assassinate, this is who you think Merlin is if you are the Assassin'
                                ],
                                'mission_action' => [
                                    'type' => 'boolean',
                                    'description' => 'Optional mission action'
                                ],
                                'urgency' => [
                                    'type' => 'number',
                                    'description' => 'Urgency of the action, 0-1. 1 is most urgent. You would use this to indicate that you want to speak first.'
                                ],
                                'reasoning' => [
                                    'type' => 'string',
                                    'description' => 'Private reasoning for the action'
                                ]
                            ],
                            'required' => ['message', 'reasoning']
                        ]
                    ]],
                    'function_call' => ['name' => 'game_response'],
                    'temperature' => 0.7,
                    'max_tokens' => 500
                ]);

            Log::info('OpenAI raw response', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            if (!$response->successful()) {
                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'body' => $response->json()
                ]);
                return $this->getFallbackResponse();
            }

            $responseData = $response->json();
            $functionCall = $responseData['choices'][0]['message']['function_call'] ?? null;

            if (!$functionCall || $functionCall['name'] !== 'game_response') {
                Log::error('OpenAI API Invalid Response', [
                    'functionCall' => $functionCall
                ]);
                return $this->getFallbackResponse();
            }

            $arguments = json_decode($functionCall['arguments'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('OpenAI API JSON Parse Error', [
                    'arguments' => $functionCall['arguments'],
                    'error' => json_last_error_msg()
                ]);
                return $this->getFallbackResponse();
            }

            return $arguments;

        } catch (\Exception $e) {
            Log::error('OpenAI API Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            'mission_action' => null
        ];
    }
}