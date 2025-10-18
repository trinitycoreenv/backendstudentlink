<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Messaging;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\WebPushConfig;
use Kreait\Firebase\Storage\Storage;
use App\Models\User;
use App\Models\FcmToken;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    protected $messaging;
    protected $storage;
    protected array $config;

    public function __construct()
    {
        $privateKey = config('services.firebase.private_key');
        // Handle different private key formats
        if (str_contains($privateKey, '\\n')) {
            $privateKey = str_replace('\\n', "\n", $privateKey);
        }
        
        $this->config = [
            'type' => 'service_account',
            'project_id' => config('services.firebase.project_id'),
            'private_key' => $privateKey,
            'client_email' => config('services.firebase.client_email'),
            'client_id' => config('services.firebase.client_email'),
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/' . urlencode(config('services.firebase.client_email')),
        ];

        // Only initialize Firebase if configuration is available
        if (!empty($this->config['project_id']) && !empty($this->config['private_key'])) {
            try {
                Log::info('Initializing Firebase with config', [
                    'project_id' => $this->config['project_id'],
                    'client_email' => $this->config['client_email'],
                    'private_key_length' => strlen($this->config['private_key']),
                ]);
                
                $factory = (new Factory)->withServiceAccount($this->config);
                $this->messaging = $factory->createMessaging();
                $this->storage = $factory->createStorage();
                
                Log::info('Firebase initialized successfully');
            } catch (\Exception $e) {
                Log::error('Firebase initialization failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->messaging = null;
                $this->storage = null;
            }
        } else {
            Log::warning('Firebase configuration incomplete', [
                'project_id' => !empty($this->config['project_id']),
                'private_key' => !empty($this->config['private_key']),
            ]);
            $this->messaging = null;
            $this->storage = null;
        }
    }

    /**
     * Send notification to a single user
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        $tokens = $user->fcmTokens()->where('is_active', true)->pluck('token')->toArray();
        
        if (empty($tokens)) {
            Log::warning('No FCM tokens found for user', ['user_id' => $user->id]);
            return false;
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Send notification to multiple users
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): array
    {
        $tokens = FcmToken::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            Log::warning('No FCM tokens found for users', ['user_ids' => $userIds]);
            return ['success' => false, 'message' => 'No tokens found'];
        }

        $result = $this->sendToTokens($tokens, $title, $body, $data);
        
        return [
            'success' => $result,
            'tokens_sent' => count($tokens),
            'users_targeted' => count($userIds),
        ];
    }

    /**
     * Send notification to users by role
     */
    public function sendToRole(string $role, string $title, string $body, array $data = []): array
    {
        $userIds = User::where('role', $role)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        return $this->sendToUsers($userIds, $title, $body, $data);
    }

    /**
     * Send notification to users by department
     */
    public function sendToDepartment(int $departmentId, string $title, string $body, array $data = []): array
    {
        $userIds = User::where('department_id', $departmentId)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        return $this->sendToUsers($userIds, $title, $body, $data);
    }

    /**
     * Send notification to specific tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): bool
    {
        try {
            $notification = Notification::create($title, $body);
            
            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData($data)
                ->withAndroidConfig(
                    AndroidConfig::new()
                        ->withNotification(
                            \Kreait\Firebase\Messaging\AndroidNotification::new()
                                ->withTitle($title)
                                ->withBody($body)
                                ->withIcon('ic_notification')
                                ->withColor('#1E2A78') // Bestlink College primary color
                                ->withSound('default')
                        )
                        ->withPriority('high')
                )
                ->withWebPushConfig(
                    WebPushConfig::new()
                        ->withNotification([
                            'title' => $title,
                            'body' => $body,
                            'icon' => '/icons/notification-icon.png',
                            'badge' => '/icons/badge-icon.png',
                            'requireInteraction' => true,
                        ])
                );

            $response = $this->messaging->sendMulticast($message, $tokens);

            // Log results
            Log::info('FCM notification sent', [
                'success_count' => $response->successes()->count(),
                'failure_count' => $response->failures()->count(),
                'total_tokens' => count($tokens),
            ]);

            // Handle failed tokens
            if ($response->hasFailures()) {
                $this->handleFailedTokens($response->failures());
            }

            return $response->successes()->count() > 0;

        } catch (\Exception $e) {
            Log::error('Failed to send FCM notification', [
                'error' => $e->getMessage(),
                'tokens_count' => count($tokens),
            ]);
            return false;
        }
    }

    /**
     * Send concern update notification
     */
    public function sendConcernUpdate(User $user, string $concernId, string $status, string $message = null): bool
    {
        $title = 'Concern Update';
        $body = $message ?? "Your concern #{$concernId} status has been updated to: {$status}";
        
        $data = [
            'type' => 'concern_update',
            'concern_id' => $concernId,
            'status' => $status,
            'click_action' => 'OPEN_CONCERN_DETAILS',
        ];

        return $this->sendToUser($user, $title, $body, $data);
    }

    /**
     * Send announcement notification
     */
    public function sendAnnouncementNotification(array $userIds, string $announcementId, string $title, string $excerpt): array
    {
        $notificationTitle = 'New Announcement';
        $body = $title . (strlen($excerpt) > 100 ? substr($excerpt, 0, 100) . '...' : $excerpt);
        
        $data = [
            'type' => 'announcement',
            'announcement_id' => $announcementId,
            'click_action' => 'OPEN_ANNOUNCEMENT',
        ];

        return $this->sendToUsers($userIds, $notificationTitle, $body, $data);
    }

    /**
     * Send assignment notification
     */
    public function sendAssignmentNotification(User $assignee, string $concernId, string $concernSubject): bool
    {
        $title = 'New Concern Assignment';
        $body = "You have been assigned a new concern: {$concernSubject}";
        
        $data = [
            'type' => 'concern_assignment',
            'concern_id' => $concernId,
            'click_action' => 'OPEN_CONCERN_DETAILS',
        ];

        return $this->sendToUser($assignee, $title, $body, $data);
    }

    /**
     * Send emergency notification
     */
    public function sendEmergencyNotification(string $title, string $message, string $type = 'emergency'): array
    {
        // Send to all active users for emergency notifications
        $userIds = User::where('is_active', true)->pluck('id')->toArray();
        
        $data = [
            'type' => 'emergency',
            'emergency_type' => $type,
            'priority' => 'high',
            'click_action' => 'OPEN_EMERGENCY_HELP',
        ];

        return $this->sendToUsers($userIds, $title, $message, $data);
    }

    /**
     * Store FCM token for user
     */
    public function storeToken(User $user, string $token, string $deviceType, string $deviceId = null): bool
    {
        try {
            FcmToken::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'token' => $token,
                ],
                [
                    'device_type' => $deviceType,
                    'device_id' => $deviceId,
                    'is_active' => true,
                    'last_used_at' => now(),
                ]
            );

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to store FCM token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove FCM token
     */
    public function removeToken(User $user, string $token): bool
    {
        try {
            FcmToken::where('user_id', $user->id)
                ->where('token', $token)
                ->update(['is_active' => false]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to remove FCM token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Handle failed FCM tokens
     */
    private function handleFailedTokens(array $failures): void
    {
        foreach ($failures as $failure) {
            $token = $failure->target()->value();
            $error = $failure->error();

            Log::warning('FCM token failed', [
                'token' => substr($token, 0, 20) . '...', // Partial token for security
                'error' => $error->getMessage(),
            ]);

            // Deactivate invalid tokens
            if (in_array($error->getCode(), ['registration-token-not-registered', 'invalid-registration-token'])) {
                FcmToken::where('token', $token)->update(['is_active' => false]);
            }
        }
    }

    /**
     * Validate Firebase configuration
     */
    public function validateConfiguration(): bool
    {
        try {
            // Try to create a test message to validate configuration
            $testMessage = CloudMessage::new()
                ->withNotification(Notification::create('Test', 'Test notification'))
                ->withData(['test' => 'true']);

            return true;
        } catch (\Exception $e) {
            Log::error('Firebase configuration validation failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get Firebase project info
     */
    public function getProjectInfo(): array
    {
        return [
            'project_id' => $this->config['project_id'],
            'client_email' => $this->config['client_email'],
            'configured' => !empty($this->config['project_id']) && !empty($this->config['private_key']),
        ];
    }

    /**
     * Upload file to Firebase Storage
     */
    public function uploadFile($file, string $path, array $metadata = []): ?string
    {
        if (!$this->storage) {
            Log::error('Firebase Storage not initialized');
            return null;
        }

        try {
            $bucket = $this->storage->getBucket();
            $object = $bucket->upload(
                $file,
                [
                    'name' => $path,
                    'metadata' => $metadata,
                ]
            );

            // Get the public URL
            $url = $object->signedUrl(new \DateTime('+1 year'));
            return $url;
        } catch (\Exception $e) {
            Log::error('Firebase Storage upload failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Delete file from Firebase Storage
     */
    public function deleteFile(string $path): bool
    {
        if (!$this->storage) {
            Log::error('Firebase Storage not initialized');
            return false;
        }

        try {
            $bucket = $this->storage->getBucket();
            $object = $bucket->object($path);
            $object->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('Firebase Storage delete failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get file URL from Firebase Storage
     */
    public function getFileUrl(string $path): ?string
    {
        if (!$this->storage) {
            Log::error('Firebase Storage not initialized');
            return null;
        }

        try {
            $bucket = $this->storage->getBucket();
            $object = $bucket->object($path);
            return $object->signedUrl(new \DateTime('+1 year'));
        } catch (\Exception $e) {
            Log::error('Firebase Storage get URL failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
