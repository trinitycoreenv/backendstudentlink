<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    protected FirebaseService $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    /**
     * Get notifications for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $user = auth()->user();
        
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $perPage = $request->input('per_page', 20);
            $page = $request->input('page', 1);
            $unreadOnly = $request->boolean('unread_only', false);
            $type = $request->input('type');
            $priority = $request->input('priority');

            $query = Notification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($unreadOnly) {
            $query->whereNull('read_at');
        }

            if ($type) {
                $query->where('type', $type);
        }

            if ($priority) {
                $query->where('priority', $priority);
        }

            $notifications = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                    'has_more_pages' => $notifications->hasMorePages(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch notifications', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get notification statistics
     */
    public function getStats(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $totalNotifications = Notification::where('user_id', $user->id)->count();
            $unreadNotifications = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();
            $readNotifications = $totalNotifications - $unreadNotifications;

            // Notifications by type
            $notificationsByType = Notification::where('user_id', $user->id)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray();

            // Recent notifications (last 7 days)
            $recentNotifications = Notification::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $totalNotifications,
                    'unread' => $unreadNotifications,
                    'read' => $readNotifications,
                    'recent' => $recentNotifications,
                    'by_type' => $notificationsByType,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch notification stats', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notification statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Mark notifications as read
     */
    public function markAsRead(Request $request): JsonResponse
    {
        try {
        $user = auth()->user();
        
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
            'notification_ids' => 'required|array',
                'notification_ids.*' => 'integer|exists:notifications,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid notification IDs',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $notificationIds = $request->input('notification_ids');

            // Verify that all notifications belong to the authenticated user
            $userNotificationIds = Notification::where('user_id', $user->id)
                ->whereIn('id', $notificationIds)
                ->pluck('id')
                ->toArray();

            if (count($userNotificationIds) !== count($notificationIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some notifications do not belong to you',
                ], 403);
            }

            // Mark notifications as read
            $updated = Notification::whereIn('id', $notificationIds)
            ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);

            Log::info('Notifications marked as read', [
                'user_id' => $user->id,
                'notification_ids' => $notificationIds,
                'updated_count' => $updated,
            ]);

        return response()->json([
            'success' => true,
                'message' => "$updated notification(s) marked as read",
                'data' => [
            'updated_count' => $updated,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to mark notifications as read', [
                'user_id' => auth()->id(),
                'notification_ids' => $request->input('notification_ids'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notifications as read',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(): JsonResponse
    {
        try {
        $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

        $updated = Notification::where('user_id', $user->id)
            ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);

            Log::info('All notifications marked as read', [
                'user_id' => $user->id,
                'updated_count' => $updated,
            ]);

        return response()->json([
            'success' => true,
                'message' => "All notifications marked as read",
                'data' => [
            'updated_count' => $updated,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to mark all notifications as read', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete notifications
     */
    public function delete(Request $request): JsonResponse
    {
        try {
        $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'notification_ids' => 'required|array',
                'notification_ids.*' => 'integer|exists:notifications,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid notification IDs',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $notificationIds = $request->input('notification_ids');

            // Verify that all notifications belong to the authenticated user
            $userNotificationIds = Notification::where('user_id', $user->id)
                ->whereIn('id', $notificationIds)
                ->pluck('id')
                ->toArray();

            if (count($userNotificationIds) !== count($notificationIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some notifications do not belong to you',
                ], 403);
            }

            // Delete notifications
            $deleted = Notification::whereIn('id', $notificationIds)->delete();

            Log::info('Notifications deleted', [
                'user_id' => $user->id,
                'notification_ids' => $notificationIds,
                'deleted_count' => $deleted,
            ]);

            return response()->json([
                'success' => true,
                'message' => "$deleted notification(s) deleted",
                'data' => [
                    'deleted_count' => $deleted,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete notifications', [
                'user_id' => auth()->id(),
                'notification_ids' => $request->input('notification_ids'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notifications',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Store FCM token
     */
    public function storeFcmToken(Request $request): JsonResponse
    {
        try {
        $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'token' => 'required|string|max:255',
                'device_type' => 'required|string|in:android,ios,web',
                'device_id' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid FCM token data',
                    'errors' => $validator->errors(),
                ], 422);
            }

        $success = $this->firebaseService->storeToken(
            $user,
                $request->input('token'),
                $request->input('device_type'),
                $request->input('device_id')
        );

        if ($success) {
                Log::info('FCM token stored successfully', [
                    'user_id' => $user->id,
                    'device_type' => $request->input('device_type'),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'FCM token stored successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to store FCM token',
        ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to store FCM token', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to store FCM token',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Remove FCM token
     */
    public function removeFcmToken(Request $request): JsonResponse
    {
        try {
        $user = auth()->user();
        
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token',
                    'errors' => $validator->errors(),
                ], 422);
            }

        $success = $this->firebaseService->removeToken($user, $request->input('token'));

        if ($success) {
                Log::info('FCM token removed successfully', [
                    'user_id' => $user->id,
                ]);

            return response()->json([
                'success' => true,
                'message' => 'FCM token removed successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to remove FCM token',
        ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to remove FCM token', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove FCM token',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get FCM tokens for user
     */
    public function getFcmTokens(): JsonResponse
    {
        try {
        $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $tokens = $user->fcmTokens()
            ->where('is_active', true)
                ->select(['id', 'device_type', 'device_id', 'created_at', 'last_used_at'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tokens,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get FCM tokens', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get FCM tokens',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Test notification (development only)
     */
    public function sendTestNotification(): JsonResponse
    {
        try {
            if (!app()->environment('local', 'development')) {
            return response()->json([
                'success' => false,
                    'message' => 'Test notifications only available in development',
            ], 403);
        }

        $user = auth()->user();
        
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

        $success = $this->firebaseService->sendToUser(
            $user,
                'Test Notification',
                'This is a test notification from the backend',
                [
                    'type' => 'test',
                    'timestamp' => now()->toISOString(),
                ]
            );

            if ($success) {
        return response()->json([
            'success' => true,
                    'message' => 'Test notification sent successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test notification',
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to send test notification', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test notification',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send email notification (for N8N automation)
     */
    public function sendEmail(Request $request): JsonResponse
    {
        try {
            $userId = $request->input('user_id');
            $subject = $request->input('subject');
            $message = $request->input('message');
            $priority = $request->input('priority', 'normal');

            if (!$userId || !$subject || !$message) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id, subject, and message are required'
                ], 400);
            }

            $user = User::find($userId);
            if (!$user) {
        return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Create notification record
            $notification = Notification::create([
                'user_id' => $userId,
                'type' => 'email',
                'title' => $subject,
                'message' => $message,
                'data' => [
                    'email_sent' => true,
                    'priority' => $priority,
                ],
                'priority' => $priority,
                'email_sent' => true,
            ]);

            // TODO: Implement actual email sending logic here
            // For now, just log the email
            Log::info('Email notification created', [
                'user_id' => $userId,
                'subject' => $subject,
                'notification_id' => $notification->id,
            ]);

        return response()->json([
            'success' => true,
                'message' => 'Email notification sent successfully',
                'data' => $notification,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send email notification', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send email notification',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send SMS notification (for N8N automation)
     */
    public function sendSms(Request $request): JsonResponse
    {
        try {
            $userId = $request->input('user_id');
            $message = $request->input('message');
            $priority = $request->input('priority', 'normal');

            if (!$userId || !$message) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id and message are required'
                ], 400);
            }

            $user = User::find($userId);
            if (!$user) {
        return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Create notification record
            $notification = Notification::create([
                'user_id' => $userId,
                'type' => 'sms',
                'title' => 'SMS Notification',
                'message' => $message,
                'data' => [
                    'sms_sent' => true,
                    'priority' => $priority,
                ],
                'priority' => $priority,
            ]);

            // TODO: Implement actual SMS sending logic here
            // For now, just log the SMS
            Log::info('SMS notification created', [
                'user_id' => $userId,
                'message' => $message,
                'notification_id' => $notification->id,
            ]);

        return response()->json([
            'success' => true,
                'message' => 'SMS notification sent successfully',
                'data' => $notification,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send SMS notification', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send SMS notification',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}