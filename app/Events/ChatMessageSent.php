<?php

namespace App\Events;

use App\Models\ChatRoom;
use App\Models\ConcernMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chatRoom;
    public $message;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatRoom $chatRoom, ConcernMessage $message)
    {
        $this->chatRoom = $chatRoom;
        $this->message = $message->load(['author', 'replyTo']);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-chat.room.' . $this->chatRoom->id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'new_message',
            'chat_room_id' => $this->chatRoom->id,
            'message' => $this->message->toArray(),
        ];
    }
}