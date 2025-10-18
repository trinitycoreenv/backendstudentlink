<?php

namespace App\Events;

use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TypingStatus implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatRoom $chatRoom;
    public User $user;
    public bool $isTyping;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatRoom $chatRoom, User $user, bool $isTyping)
    {
        $this->chatRoom = $chatRoom;
        $this->user = $user;
        $this->isTyping = $isTyping;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $participantIds = $this->chatRoom->getParticipantIds();
        $channels = [];

        foreach ($participantIds as $participantId) {
            if ($participantId !== $this->user->id) {
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
            'type' => 'typing_status',
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role,
            ],
            'is_typing' => $this->isTyping,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'typing.status';
    }
}

