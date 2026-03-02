<?php

namespace App\Services;

use App\Contracts\AgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AgentService
{
    use AgentResponseSchema;
    private string $apiKey;

    private string $model;

    private string $baseUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = env('OPEN_AI_API_KEY');
        $this->model = env('AI_MODEL', 'gpt-4.1-mini');
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
    
}
