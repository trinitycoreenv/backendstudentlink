<?php

namespace App\Events;

use App\Models\ConcernMessage;
use App\Models\ChatRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ConcernMessage $message;
    public ChatRoom $chatRoom;

    /**
     * Create a new event instance.
     */
    public function __construct(ConcernMessage $message, ChatRoom $chatRoom)
    {
        $this->message = $message;
        $this->chatRoom = $chatRoom;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $participantIds = $this->chatRoom->getParticipantIds();
        $channels = [];

        foreach ($participantIds as $participantId) {
            if ($participantId !== $this->message->author_id) {
                $channels[] = new PrivateChannel("chat.room.{$this->chatRoom->id}.user.{$participantId}");
            }
        }

        return $channels;
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'new_message',
            'message' => [
                'id' => $this->message->id,
                'message' => $this->message->message,
                'message_type' => $this->message->message_type,
                'created_at' => $this->message->created_at,
                'author' => [
                    'id' => $this->message->author->id,
                    'name' => $this->message->author->name,
                    'role' => $this->message->author->role,
                ],
                'reply_to' => $this->message->replyTo ? [
                    'id' => $this->message->replyTo->id,
                    'message' => $this->message->replyTo->message,
                    'author' => [
                        'name' => $this->message->replyTo->author->name,
                    ],
                ] : null,
            ],
            'chat_room' => [
                'id' => $this->chatRoom->id,
                'last_activity_at' => $this->chatRoom->last_activity_at,
            ],
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}

