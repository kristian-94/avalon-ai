<?php
namespace App\Contracts;

interface AgentService
{
    /**
     * Get a response from an agent based on the conversation history
     *
     * @param array $messages Array of messages in the conversation
     * @return array Response containing message and any additional data
     */
    public function getChatResponse(array $messages): array;
}