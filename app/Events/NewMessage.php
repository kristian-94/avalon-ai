<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The name of the queue connection to use when broadcasting the event.
     *
     * @var string
     */
    public string $connection = 'sync'; // We need messages to be fast.

    public function __construct(public Message $message)
    {
    }

    public function broadcastOn(): Channel
    {
        Log::info('Broadcasting message', [
            'driver' => config('broadcasting.default'),
            'channel' => 'game.' . $this->message->game_id
        ]);
        return new Channel('game.' . $this->message->game_id);
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'content' => $this->message->content,
            'player_id' => $this->message->player_id,
            'player_name' => $this->message->player->name,
            'created_at' => $this->message->created_at
        ];
    }
}