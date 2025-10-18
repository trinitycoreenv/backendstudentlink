<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Pusher\PusherException;

class WebSocketService
{
    private $pusher;
    private $isEnabled;

    public function __construct()
    {
        $this->isEnabled = config('broadcasting.connections.pusher.key') !== null;
        
        if ($this->isEnabled) {
            try {
                $this->pusher = new Pusher(
                    config('broadcasting.connections.pusher.key'),
                    config('broadcasting.connections.pusher.secret'),
                    config('broadcasting.connections.pusher.app_id'),
                    [
                        'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                        'useTLS' => true,
                        'encrypted' => true,
                    ]
                );
            } catch (PusherException $e) {
                Log::error('Failed to initialize Pusher: ' . $e->getMessage());
                $this->isEnabled = false;
            }
        }
    }

    /**
     * Broadcast concern update to all users
     */
    public function broadcastConcernUpdate($concern, $eventType = 'concern_updated')
    {
        if (!$this->isEnabled) {
            Log::info('WebSocket broadcasting disabled, skipping concern update broadcast');
            return;
        }

        try {
            $this->pusher->trigger('concerns', $eventType, [
                'concern' => $concern,
                'timestamp' => now()->toISOString(),
                'event_type' => $eventType,
            ]);

            Log::info('Broadcasted concern update', [
                'concern_id' => $concern['id'],
                'event_type' => $eventType,
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast concern update: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast chat message to chat room participants
     */
    public function broadcastChatMessage($chatRoom, $message)
    {
        if (!$this->isEnabled) {
            Log::info('WebSocket broadcasting disabled, skipping chat message broadcast');
            return;
        }

        try {
            // Broadcast to specific chat room
            $this->pusher->trigger("chat.room.{$chatRoom->id}", 'new_message', [
                'message' => $message,
                'chat_room' => $chatRoom,
                'timestamp' => now()->toISOString(),
                'event_type' => 'new_message',
            ]);

            // Broadcast to general chat channel for real-time updates
            $this->pusher->trigger('chat-updates', 'new_message', [
                'message' => $message,
                'chat_room_id' => $chatRoom->id,
                'concern_id' => $chatRoom->concern_id,
                'timestamp' => now()->toISOString(),
                'event_type' => 'new_message',
            ]);

            Log::info('Broadcasted chat message', [
                'chat_room_id' => $chatRoom->id,
                'message_id' => $message->id,
                'author_id' => $message->author_id,
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast chat message: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast resolution confirmation event
     */
    public function broadcastResolutionConfirmed($concern, $studentNotes = null)
    {
        if (!$this->isEnabled) {
            Log::info('WebSocket broadcasting disabled, skipping resolution confirmation broadcast');
            return;
        }

        try {
            // Broadcast to general concerns channel
            $this->pusher->trigger('concerns', 'resolution_confirmed', [
                'concern' => $concern,
                'student_notes' => $studentNotes,
                'timestamp' => now()->toISOString(),
                'event_type' => 'resolution_confirmed',
            ]);

            // Broadcast to resolution updates channel
            $this->pusher->trigger('resolution_updates', 'resolution_confirmed', [
                'concern' => $concern,
                'student_notes' => $studentNotes,
                'timestamp' => now()->toISOString(),
                'event_type' => 'resolution_confirmed',
            ]);

            // Broadcast to specific chat room if exists
            if (isset($concern['chat_room_id'])) {
                $this->pusher->trigger("private-chat.room.{$concern['chat_room_id']}", 'chat_room_closed', [
                    'concern' => $concern,
                    'closure_reason' => 'student_confirmed_resolution',
                    'timestamp' => now()->toISOString(),
                    'event_type' => 'chat_room_closed',
                ]);
            }

            Log::info('Broadcasted resolution confirmation', [
                'concern_id' => $concern['id'],
                'chat_room_id' => $concern['chat_room_id'] ?? null,
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast resolution confirmation: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast resolution dispute event
     */
    public function broadcastResolutionDisputed($concern, $disputeReason)
    {
        if (!$this->isEnabled) {
            Log::info('WebSocket broadcasting disabled, skipping resolution dispute broadcast');
            return;
        }

        try {
            // Broadcast to general concerns channel
            $this->pusher->trigger('concerns', 'resolution_disputed', [
                'concern' => $concern,
                'dispute_reason' => $disputeReason,
                'timestamp' => now()->toISOString(),
                'event_type' => 'resolution_disputed',
            ]);

            // Broadcast to resolution updates channel
            $this->pusher->trigger('resolution_updates', 'resolution_disputed', [
                'concern' => $concern,
                'dispute_reason' => $disputeReason,
                'timestamp' => now()->toISOString(),
                'event_type' => 'resolution_disputed',
            ]);

            // Broadcast to specific chat room if exists
            if (isset($concern['chat_room_id'])) {
                $this->pusher->trigger("private-chat.room.{$concern['chat_room_id']}", 'chat_room_reopened', [
                    'concern' => $concern,
                    'reopening_reason' => 'student_disputed_resolution',
                    'dispute_reason' => $disputeReason,
                    'timestamp' => now()->toISOString(),
                    'event_type' => 'chat_room_reopened',
                ]);
            }

            Log::info('Broadcasted resolution dispute', [
                'concern_id' => $concern['id'],
                'chat_room_id' => $concern['chat_room_id'] ?? null,
                'dispute_reason' => $disputeReason,
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast resolution dispute: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast chat room creation
     */
    public function broadcastChatRoomCreated($chatRoom, $initialMessage)
    {
        if (!$this->isEnabled) {
            Log::info('WebSocket broadcasting disabled, skipping chat room creation broadcast');
            return;
        }

        try {
            // Broadcast to general concerns channel
            $this->pusher->trigger('concerns', 'chat_room_created', [
                'chat_room' => $chatRoom,
                'initial_message' => $initialMessage,
                'timestamp' => now()->toISOString(),
                'event_type' => 'chat_room_created',
            ]);

            // Broadcast to department-specific channel
            if (isset($chatRoom->concern->department_id)) {
                $this->pusher->trigger("private-concerns.department.{$chatRoom->concern->department_id}", 'chat_room_created', [
                    'chat_room' => $chatRoom,
                    'initial_message' => $initialMessage,
                    'timestamp' => now()->toISOString(),
                    'event_type' => 'chat_room_created',
                ]);
            }

            // Broadcast to the specific chat room
            $this->pusher->trigger("private-chat.room.{$chatRoom->id}", 'chat_room_created', [
                'chat_room' => $chatRoom,
                'initial_message' => $initialMessage,
                'timestamp' => now()->toISOString(),
                'event_type' => 'chat_room_created',
            ]);

            Log::info('Broadcasted chat room creation', [
                'chat_room_id' => $chatRoom->id,
                'concern_id' => $chatRoom->concern_id,
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast chat room creation: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast chat room status change
     */
    public function broadcastChatRoomStatusChange($concern, $status, $reason = null)
    {
        if (!$this->isEnabled) {
            Log::info('WebSocket broadcasting disabled, skipping chat room status broadcast');
            return;
        }

        try {
            $eventType = $status === 'closed' ? 'chat_room_closed' : 'chat_room_reopened';
            
            if (isset($concern['chat_room_id'])) {
                $this->pusher->trigger("private-chat.room.{$concern['chat_room_id']}", $eventType, [
                    'concern' => $concern,
                    'status' => $status,
                    'reason' => $reason,
                    'timestamp' => now()->toISOString(),
                    'event_type' => $eventType,
                ]);
            }

            Log::info('Broadcasted chat room status change', [
                'concern_id' => $concern['id'],
                'chat_room_id' => $concern['chat_room_id'] ?? null,
                'status' => $status,
                'reason' => $reason,
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast chat room status change: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast new chat message
     */
    public function broadcastNewMessage($chatRoomId, $message)
    {
        if (!$this->isEnabled) {
            Log::info('WebSocket broadcasting disabled, skipping new message broadcast');
            return;
        }

        try {
            $this->pusher->trigger("private-chat.room.{$chatRoomId}", 'new_message', [
                'message' => $message,
                'timestamp' => now()->toISOString(),
                'event_type' => 'new_message',
            ]);

            Log::info('Broadcasted new message', [
                'chat_room_id' => $chatRoomId,
                'message_id' => $message['id'] ?? null,
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast new message: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast typing status
     */
    public function broadcastTypingStatus($chatRoomId, $userId, $isTyping)
    {
        if (!$this->isEnabled) {
            return;
        }

        try {
            $this->pusher->trigger("chat.room.{$chatRoomId}", 'typing_status', [
                'user_id' => $userId,
                'is_typing' => $isTyping,
                'timestamp' => now()->toISOString(),
                'event_type' => 'typing_status',
            ]);
        } catch (PusherException $e) {
            Log::error('Failed to broadcast typing status: ' . $e->getMessage());
        }
    }

    /**
     * Check if WebSocket broadcasting is enabled
     */
    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }
}
