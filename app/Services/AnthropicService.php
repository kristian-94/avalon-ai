<?php

namespace App\Services;

use App\Contracts\AgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicService implements AgentService
{
    use AgentResponseSchema;

    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct(?string $model = null)
    {
        $this->apiKey = env('ANTHROPIC_API_KEY');
        $this->model = $model ?? env('AI_MODEL', 'claude-sonnet-4-6');
    }

    public function getChatResponse(array $messages): array
    {
        $messages = array_values($messages);
        $currentPhase = $this->extractCurrentPhase($messages);

        // Anthropic separates system messages from the conversation
        $systemParts = [];
        $conversationMessages = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemParts[] = $message['content'];
            } else {
                $conversationMessages[] = $message;
            }
        }

        // Append schema instruction to system prompt so reasoning comes before action
        $schema = $this->getPhaseSpecificParameters($currentPhase, $messages);
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT);
        $systemParts[] = "Respond with a JSON object matching this schema:\n{$schemaJson}\n\nIMPORTANT: Output fields in this exact order: reasoning first, then any action field (assassination_target / vote / team_proposal / mission_action), then message. Think through your reasoning before committing to an action.";

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($this->baseUrl, [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => implode("\n\n", $systemParts),
                'messages' => $conversationMessages,
            ]);

            Log::info('Anthropic raw response', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if (! $response->successful()) {
                Log::error('Anthropic API Error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return $this->getFallbackResponse();
            }

            $responseData = $response->json();
            $text = $responseData['content'][0]['text'] ?? '';

            // Strip markdown code fences if present
            $text = preg_replace('/^```json\s*/m', '', $text);
            $text = preg_replace('/^```\s*/m', '', $text);
            $text = trim($text);

            $arguments = json_decode($text, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Anthropic JSON Parse Error', [
                    'text' => $text,
                    'error' => json_last_error_msg(),
                ]);

                return $this->getFallbackResponse();
            }

            $arguments['_usage'] = $responseData['usage'] ?? null;

            return $arguments;

        } catch (\Exception $e) {
            Log::error('Anthropic API Exception', [
                'message' => $e->getMessage(),
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
