<?php

namespace App\Services;

use App\Contracts\AgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService implements AgentService
{
    use AgentResponseSchema;
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
                    'max_tokens' => 500,
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

}
