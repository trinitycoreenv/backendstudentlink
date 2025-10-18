<?php

namespace App\Http\Controllers;

use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Models\Concern;
use App\Models\User;
use App\Services\FirebaseService;
use App\Services\WebSocketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    protected FirebaseService $firebaseService;
    protected WebSocketService $webSocketService;

    public function __construct(
        FirebaseService $firebaseService,
        WebSocketService $webSocketService
    ) {
        $this->firebaseService = $firebaseService;
        $this->webSocketService = $webSocketService;
    }

    /**
     * Get active chat rooms for the authenticated user
     */
    public function getActiveChatRooms(Request $request): JsonResponse
    {
        $user = auth()->user();

        try {
            $allChatRooms = ChatRoom::where('status', 'active')
                ->with(['concern:id,subject,status,student_id,department_id,assigned_to,archived_at', 'concern.student:id,name'])
                ->whereHas('concern', function($query) {
                    $query->whereNull('archived_at') // Only show non-archived concerns
                          ->whereNotIn('status', ['resolved', 'student_confirmed', 'closed']); // Also filter out resolved concerns
                })
                ->orderBy('last_activity_at', 'desc')
                ->get();

            // Filter chat rooms where user is a participant
            $chatRooms = $allChatRooms->filter(function ($room) use ($user) {
                // For staff members, check if they are assigned to the concern
                if ($user->role === 'staff' && $room->concern) {
                    return $room->concern->assigned_to === $user->id;
                }
                
                // For students, check if they are the concern owner
                if ($user->role === 'student' && $room->concern) {
                    return $room->concern->student_id === $user->id;
                }
                
                // For department heads and admins, check participants or department access
                $participants = $room->participants ?? [];
                return isset($participants[$user->id]);
            });

            // Add unread message counts
            $chatRooms->each(function ($room) use ($user) {
                $room->unread_count = ChatMessage::where('chat_room_id', $room->id)
                    ->where('author_id', '!=', $user->id)
                    ->where('read_at', null)
                    ->count();
            });

            return response()->json([
                'success' => true,
                'data' => $chatRooms,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch chat rooms',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get or create chat room for a concern
     */
    public function getOrCreateChatRoom(Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user has access to this concern
            if (!$this->canAccessConcern($concern, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this concern',
                ], 403);
            }

            // Check if chat room already exists
            $chatRoom = ChatRoom::where('concern_id', $concern->id)->first();

            if (!$chatRoom) {
                // Create new chat room
                $chatRoom = $this->createChatRoom($concern);
            }

            // Load relationships
            $chatRoom->load(['concern:id,subject,status,student_id,department_id', 'concern.student:id,name']);

            return response()->json([
                'success' => true,
                'data' => $chatRoom,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get or create chat room',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send message in chat room
     */
    public function sendMessage(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $user = auth()->user();

        $request->validate([
            'message' => 'required|string|max:2000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'message_type' => 'nullable|string|in:text,image,file,system',
        ]);

        try {
            // Load the concern relationship for access checking
            $chatRoom->load('concern');
            
            // Check if user has access to this chat room
            if (!$this->canAccessChatRoom($chatRoom, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this chat room',
                ], 403);
            }

            // Create message
            $message = ChatMessage::create([
                'concern_id' => $chatRoom->concern_id,
                'chat_room_id' => $chatRoom->id,
                'author_id' => $user->id,
                'message' => $request->input('message'),
                'message_type' => $request->input('message_type', 'text'),
                'attachments' => $request->input('attachments', []),
                'metadata' => [
                    'sent_at' => now()->toISOString(),
                    'author_name' => $user->name,
                    'author_role' => $user->role,
                ],
            ]);

            // Update chat room last activity
            $chatRoom->update([
                'last_activity_at' => now(),
                'last_message_id' => $message->id,
            ]);

            // Load message with author
            $message->load('author:id,name,role');

            // Send push notifications to other participants
            $this->sendChatMessageNotifications($chatRoom, $message, $user);

            // Broadcast via WebSocket
            $this->webSocketService->broadcastChatMessage($chatRoom, $message);

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => $message,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get messages for a chat room
     */
    public function getMessages(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $user = auth()->user();

        try {
            // Load the concern relationship for access checking
            $chatRoom->load('concern');
            
            // Check if user has access to this chat room
            if (!$this->canAccessChatRoom($chatRoom, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this chat room',
                ], 403);
            }

            $perPage = $request->input('per_page', 50);
            $messages = ChatMessage::where('chat_room_id', $chatRoom->id)
                ->with('author:id,name,role')
                ->orderBy('created_at', 'asc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $messages->items(),
                'pagination' => [
                    'current_page' => $messages->currentPage(),
                    'last_page' => $messages->lastPage(),
                    'per_page' => $messages->perPage(),
                    'total' => $messages->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Mark messages as read in a chat room
     */
    public function markAsRead(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user has access to this chat room
            if (!$this->canAccessChatRoom($chatRoom, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this chat room',
                ], 403);
            }

            // Mark all unread messages as read
            $updated = ChatMessage::where('chat_room_id', $chatRoom->id)
                ->where('author_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Messages marked as read',
                'updated_count' => $updated,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark messages as read',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Close a chat room
     */
    public function closeChatRoom(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user has access to this chat room
            if (!$this->canAccessChatRoom($chatRoom, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this chat room',
                ], 403);
            }

            // Only admin or department head can close chat rooms
            if (!in_array($user->role, ['admin', 'department_head'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only administrators can close chat rooms',
                ], 403);
            }

            // Update chat room status
            $chatRoom->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $user->id,
            ]);

            // Create system message
            ChatMessage::create([
                'chat_room_id' => $chatRoom->id,
                'author_id' => $user->id,
                'message' => 'Chat room has been closed by ' . $user->name,
                'message_type' => 'system',
                'metadata' => [
                    'action' => 'chat_room_closed',
                    'closed_by' => $user->id,
                    'closed_at' => now()->toISOString(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chat room closed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to close chat room',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create a new chat room for a concern
     */
    private function createChatRoom(Concern $concern): ChatRoom
    {
        $participants = [
            $concern->student_id => [
                'user_id' => $concern->student_id,
                'role' => 'student',
                'joined_at' => now()->toISOString(),
            ],
        ];

        // Add department head if concern is approved
        if ($concern->approved_by) {
            $participants[$concern->approved_by] = [
                'user_id' => $concern->approved_by,
                'role' => 'department_head',
                'joined_at' => now()->toISOString(),
            ];
        }

        return ChatRoom::create([
            'concern_id' => $concern->id,
            'room_name' => 'Concern #' . $concern->reference_number,
            'status' => 'active',
            'last_activity_at' => now(),
            'participants' => $participants,
            'settings' => [
                'auto_assign' => true,
                'notifications' => true,
            ],
        ]);
    }

    /**
     * Send push notifications for new chat messages
     */
    private function sendChatMessageNotifications(ChatRoom $chatRoom, ChatMessage $message, User $sender): void
    {
        try {
            $participants = $chatRoom->participants;
            $targetUserIds = [];

            // Get all participants except the sender
            foreach ($participants as $participant) {
                if ($participant['user_id'] != $sender->id) {
                    $targetUserIds[] = $participant['user_id'];
                }
            }

            if (empty($targetUserIds)) {
                return;
            }

            // Get target users
            $targetUsers = User::whereIn('id', $targetUserIds)->get();

            foreach ($targetUsers as $targetUser) {
                // Create notification title and body
                $title = 'New message from ' . $sender->name;
                $body = $this->truncateMessage($message->message, 100);

                // Send push notification
                $this->firebaseService->sendToUser($targetUser, $title, $body, [
                    'type' => 'chat_message',
                    'chat_room_id' => $chatRoom->id,
                    'concern_id' => $chatRoom->concern_id,
                    'message_id' => $message->id,
                    'sender_id' => $sender->id,
                    'sender_name' => $sender->name,
                    'sender_role' => $sender->role,
                    'click_action' => 'OPEN_CHAT_ROOM',
                ]);

                \Log::info('Chat message notification sent', [
                    'chat_room_id' => $chatRoom->id,
                    'target_user_id' => $targetUser->id,
                    'sender_id' => $sender->id,
                    'message_id' => $message->id,
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('Failed to send chat message notifications', [
                'chat_room_id' => $chatRoom->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if user can access a concern
     */
    private function canAccessConcern(Concern $concern, User $user): bool
    {
        // Admin can access all concerns
        if ($user->role === 'admin') {
            return true;
        }

        // Student can access their own concerns, but only if approved
        if ($user->role === 'student' && $concern->student_id === $user->id) {
            // Students can only chat on approved concerns (not pending or cancelled)
            return !in_array($concern->status, ['pending', 'cancelled']);
        }

        // Department head can access concerns in their department, but only if approved
        if ($user->role === 'department_head' && $concern->department_id === $user->department_id) {
            // Department heads can only chat on approved concerns (not pending or cancelled)
            return !in_array($concern->status, ['pending', 'cancelled']);
        }

        // Staff can access concerns assigned to them
        if ($user->role === 'staff' && $concern->assigned_to === $user->id) {
            // Staff can chat on concerns assigned to them (regardless of status)
            return true;
        }

        return false;
    }

    /**
     * Check if user can access a chat room
     */
    private function canAccessChatRoom(ChatRoom $chatRoom, User $user): bool
    {
        $participants = $chatRoom->participants ?? [];
        
        // Check if user is a participant (participants is a JSON object with user_id as keys)
        if (isset($participants[$user->id])) {
            return true;
        }
        
        // For staff members, check if they are assigned to the concern
        if ($user->role === 'staff' && $chatRoom->concern) {
            return $chatRoom->concern->assigned_to === $user->id;
        }
        
        // For students, check if they are the concern owner
        if ($user->role === 'student' && $chatRoom->concern) {
            return $chatRoom->concern->student_id === $user->id;
        }
        
        // For department heads and admins, allow access to concerns in their department
        if (in_array($user->role, ['department_head', 'admin']) && $chatRoom->concern) {
            return $chatRoom->concern->department_id === $user->department_id;
        }
        
        return false;
    }

    /**
     * Truncate message for notification
     */
    private function truncateMessage(string $message, int $length = 100): string
    {
        if (strlen($message) <= $length) {
            return $message;
        }

        return substr($message, 0, $length) . '...';
    }
}
