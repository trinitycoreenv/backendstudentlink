<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConcernUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $concern;
    public $action;

    /**
     * Create a new event instance.
     */
    public function __construct($concern, $action = 'updated')
    {
        $this->concern = $concern;
        $this->action = $action;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('concerns'),
            new PrivateChannel('concerns.department.' . $this->concern->department_id),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'concern' => [
                'id' => $this->concern->id,
                'reference_number' => $this->concern->reference_number,
                'subject' => $this->concern->subject,
                'status' => $this->concern->status,
                'priority' => $this->concern->priority,
                'department_id' => $this->concern->department_id,
                'updated_at' => $this->concern->updated_at,
            ],
            'action' => $this->action,
            'timestamp' => now()->toISOString(),
        ];
    }
}
