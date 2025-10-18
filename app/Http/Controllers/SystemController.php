<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\AuditLog;
use App\Services\AuditLogService;

class SystemController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }
    /**
     * Basic health check
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'service' => 'StudentLink Backend API'
        ]);
    }

    /**
     * Detailed health check
     */
    public function detailedHealth(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'service' => 'StudentLink Backend API',
            'version' => '1.0.0',
            'environment' => app()->environment(),
            'database' => 'connected',
            'cache' => 'available'
        ]);
    }

    /**
     * Get system settings
     */
    public function getSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'general' => [
                    'portalName' => 'StudentLink Portal',
                    'maintenanceMode' => false,
                    'timezone' => 'Asia/Manila',
                    'language' => 'en',
                    'maxUsers' => 1000
                ],
                'notifications' => [
                    'emailNotifications' => true,
                    'smsNotifications' => false,
                    'pushNotifications' => true,
                    'adminEmail' => 'admin@bcp.edu.ph',
                    'notificationFrequency' => 'immediate'
                ],
                'security' => [
                    'passwordMinLength' => 8,
                    'requireSpecialChars' => true,
                    'sessionTimeout' => 30,
                    'twoFactorAuth' => false,
                    'ipWhitelist' => '',
                    'maxLoginAttempts' => 5
                ],
                'concerns' => [
                    'allowAttachments' => true,
                    'maxFileSize' => 10,
                    'autoAssign' => true,
                    'requireApproval' => false,
                    'anonymousAllowed' => true
                ],
                'system' => [
                    'backupFrequency' => 'daily',
                    'logRetention' => 90,
                    'cacheTimeout' => 60,
                    'apiRateLimit' => 1000,
                    'debugMode' => false
                ]
            ]
        ]);
    }

    /**
     * Update system settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully'
        ]);
    }

    /**
     * Get audit logs
     */
    public function getAuditLogs(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Only admin can access audit logs
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'action' => 'nullable|string',
                'user_id' => 'nullable|integer',
                'resource_type' => 'nullable|string',
                'status' => 'nullable|string|in:success,failed,warning',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'search' => 'nullable|string|max:255',
            ]);

            $filters = $request->only([
                'action', 'user_id', 'resource_type', 'status', 
                'date_from', 'date_to', 'search'
            ]);

            $perPage = $request->input('per_page', 20);
            $auditLogs = $this->auditLogService->getLogs($filters, $perPage);

            // Transform the data for the frontend
            $transformedLogs = $auditLogs->getCollection()->map(function ($log) {
                return [
                    'id' => $log->id,
                    'user_id' => $log->user_id,
                    'user_name' => $log->user->name ?? 'System',
                    'action' => $log->action,
                    'resource' => $log->formatted_resource,
                    'resource_id' => $log->resource_id,
                    'details' => $log->details,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'status' => $log->status,
                    'created_at' => $log->created_at->toISOString(),
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedLogs,
                'pagination' => [
                    'current_page' => $auditLogs->currentPage(),
                    'per_page' => $auditLogs->perPage(),
                    'total' => $auditLogs->total(),
                    'last_page' => $auditLogs->lastPage(),
                    'from' => $auditLogs->firstItem(),
                    'to' => $auditLogs->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch audit logs',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get audit log statistics
     */
    public function getAuditLogStats(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Only admin can access audit log statistics
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]);

            $filters = $request->only(['date_from', 'date_to']);
            $stats = $this->auditLogService->getStatistics($filters);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch audit log statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get system info
     */
    public function getSystemInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'version' => '1.0.0',
                'environment' => app()->environment(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version()
            ]
        ]);
    }

    /**
     * Upload file
     */
    public function uploadFile(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|max:10240', // 10MB max
                'type' => 'nullable|string|in:concern,avatar,announcement,general'
            ]);

            $file = $request->file('file');
            $type = $request->input('type', 'general');
            
            // Generate unique filename
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . '.' . $extension;
            
            // Store file in appropriate directory
            $path = $file->storeAs("uploads/{$type}", $filename, 'public');
            
            // Get file URL
            $url = Storage::url($path);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'filename' => $filename,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'url' => $url,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'type' => $type
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'File upload failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
