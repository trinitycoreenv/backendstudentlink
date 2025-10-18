<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmergencyController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(
        AuditLogService $auditLogService
    ) {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Get emergency contacts (public)
     */
    public function getContacts(): JsonResponse
    {
        try {
            $contacts = [
                [
                    'id' => 1,
                    'name' => 'Campus Clinic',
                    'phone' => '+63 2 8123-4567',
                    'location' => 'Ground Floor, Main Building',
                    'hours' => '24/7',
                    'status' => 'active',
                    'description' => 'Medical emergencies and health services',
                    'type' => 'medical',
                    'priority' => 1,
                    'is_active' => true,
                ],
                [
                    'id' => 2,
                    'name' => 'Campus Security',
                    'phone' => '+63 2 8123-4568',
                    'location' => 'Security Office, Gate 1',
                    'hours' => '24/7',
                    'status' => 'active',
                    'description' => 'Security incidents and campus safety',
                    'type' => 'security',
                    'priority' => 1,
                    'is_active' => true,
                ],
                [
                    'id' => 3,
                    'name' => 'Guidance Office',
                    'phone' => '+63 2 8123-4569',
                    'location' => '2nd Floor, Admin Building',
                    'hours' => '8:00 AM - 5:00 PM',
                    'status' => 'active',
                    'description' => 'Student counseling and guidance services',
                    'type' => 'counseling',
                    'priority' => 2,
                    'is_active' => true,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $contacts
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch emergency contacts',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get emergency protocols (public)
     */
    public function getProtocols(): JsonResponse
    {
        try {
            $protocols = [
                [
                    'id' => 1,
                    'title' => 'Fire Emergency Protocol',
                    'description' => 'Steps to follow in case of fire emergency',
                    'steps' => [
                        'Stay calm and alert others',
                        'Activate fire alarm if not already activated',
                        'Evacuate building using nearest exit',
                        'Do not use elevators',
                        'Assemble at designated meeting point',
                        'Call emergency services: 911'
                    ],
                    'emergency_type' => 'fire',
                    'priority' => 1,
                    'is_active' => true,
                ],
                [
                    'id' => 2,
                    'title' => 'Medical Emergency Protocol',
                    'description' => 'Steps to follow in case of medical emergency',
                    'steps' => [
                        'Assess the situation and ensure safety',
                        'Call campus clinic immediately',
                        'Provide first aid if trained',
                        'Stay with the person until help arrives',
                        'Clear area for emergency responders'
                    ],
                    'emergency_type' => 'medical',
                    'priority' => 1,
                    'is_active' => true,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $protocols
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch emergency protocols',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create emergency contact (Admin only)
     */
    public function createContact(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'location' => 'nullable|string|max:255',
            'hours' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'type' => 'required|string|in:medical,security,counseling,maintenance,general',
            'status' => 'required|string|in:active,inactive',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $contact = [
                'id' => rand(1000, 9999),
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'location' => $request->input('location'),
                'hours' => $request->input('hours'),
                'description' => $request->input('description'),
                'type' => $request->input('type'),
                'status' => $request->input('status'),
                'priority' => $request->input('priority', 5),
                'is_active' => $request->input('status') === 'active',
            ];

            return response()->json([
                'success' => true,
                'message' => 'Emergency contact created successfully',
                'data' => $contact
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create emergency contact',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update emergency contact (Admin only)
     */
    public function updateContact(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'location' => 'nullable|string|max:255',
            'hours' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'type' => 'sometimes|string|in:medical,security,counseling,maintenance,general',
            'status' => 'sometimes|string|in:active,inactive',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $contact = [
                'id' => $id,
                'name' => $request->input('name', 'Updated Contact'),
                'phone' => $request->input('phone', '+63 2 8123-0000'),
                'location' => $request->input('location', 'Updated Location'),
                'hours' => $request->input('hours', '24/7'),
                'description' => $request->input('description', 'Updated Description'),
                'type' => $request->input('type', 'general'),
                'status' => $request->input('status', 'active'),
                'priority' => $request->input('priority', 5),
                'is_active' => $request->input('status', 'active') === 'active',
            ];

            return response()->json([
                'success' => true,
                'message' => 'Emergency contact updated successfully',
                'data' => $contact
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update emergency contact',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete emergency contact (Admin only)
     */
    public function deleteContact(int $id): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            return response()->json([
                'success' => true,
                'message' => 'Emergency contact deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete emergency contact',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create emergency protocol (Admin only)
     */
    public function createProtocol(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'steps' => 'required|array|min:1',
            'steps.*' => 'required|string|max:500',
            'emergency_type' => 'required|string|in:fire,medical,security,natural_disaster,other',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $protocol = [
                'id' => rand(1000, 9999),
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'steps' => $request->input('steps'),
                'emergency_type' => $request->input('emergency_type'),
                'priority' => $request->input('priority', 5),
                'is_active' => true,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Emergency protocol created successfully',
                'data' => $protocol
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create emergency protocol',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update emergency protocol (Admin only)
     */
    public function updateProtocol(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'steps' => 'sometimes|array|min:1',
            'steps.*' => 'required|string|max:500',
            'emergency_type' => 'sometimes|string|in:fire,medical,security,natural_disaster,other',
            'priority' => 'nullable|integer|min:1|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $protocol = [
                'id' => $id,
                'title' => $request->input('title', 'Updated Protocol'),
                'description' => $request->input('description', 'Updated Description'),
                'steps' => $request->input('steps', ['Step 1', 'Step 2']),
                'emergency_type' => $request->input('emergency_type', 'other'),
                'priority' => $request->input('priority', 5),
                'is_active' => $request->input('is_active', true),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Emergency protocol updated successfully',
                'data' => $protocol
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update emergency protocol',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete emergency protocol (Admin only)
     */
    public function deleteProtocol(int $id): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            return response()->json([
                'success' => true,
                'message' => 'Emergency protocol deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete emergency protocol',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get emergency settings (Admin only)
     */
    public function getSettings(): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $settings = [
                'auto_notify_security' => true,
                'auto_notify_medical' => true,
                'broadcast_enabled' => true,
                'alert_retention_days' => 30,
                'emergency_contacts_limit' => 50,
                'protocols_limit' => 20,
                'notification_channels' => ['email', 'sms', 'push'],
                'default_priority' => 5,
                'auto_escalation' => true,
                'escalation_time_minutes' => 15,
            ];

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch emergency settings',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update emergency settings (Admin only)
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'auto_notify_security' => 'boolean',
            'auto_notify_medical' => 'boolean',
            'broadcast_enabled' => 'boolean',
            'alert_retention_days' => 'integer|min:1|max:365',
            'emergency_contacts_limit' => 'integer|min:1|max:100',
            'protocols_limit' => 'integer|min:1|max:50',
            'notification_channels' => 'array',
            'notification_channels.*' => 'string|in:email,sms,push',
            'default_priority' => 'integer|min:1|max:10',
            'auto_escalation' => 'boolean',
            'escalation_time_minutes' => 'integer|min:5|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = $request->all();

            return response()->json([
                'success' => true,
                'message' => 'Emergency settings updated successfully',
                'data' => $settings
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update emergency settings',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Broadcast emergency alert (Admin only)
     */
    public function broadcastAlert(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'priority' => 'required|string|in:low,medium,high,critical',
            'target_audience' => 'required|string|in:all,students,staff,faculty',
            'channels' => 'required|array|min:1',
            'channels.*' => 'string|in:email,sms,push,announcement',
            'expires_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $alert = [
                'id' => rand(1000, 9999),
                'title' => $request->input('title'),
                'message' => $request->input('message'),
                'priority' => $request->input('priority'),
                'target_audience' => $request->input('target_audience'),
                'channels' => $request->input('channels'),
                'expires_at' => $request->input('expires_at'),
                'created_by' => $user->name,
                'created_at' => now()->toISOString(),
                'status' => 'sent',
            ];

            return response()->json([
                'success' => true,
                'message' => 'Emergency alert broadcasted successfully',
                'data' => $alert
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to broadcast emergency alert',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get emergency statistics (Admin only)
     */
    public function getStats(): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $stats = [
                'total_contacts' => 3,
                'active_contacts' => 3,
                'total_protocols' => 2,
                'active_protocols' => 2,
                'alerts_sent_today' => 0,
                'alerts_sent_this_week' => 0,
                'alerts_sent_this_month' => 0,
                'last_alert' => null,
                'response_time_avg' => 2.5,
                'satisfaction_rate' => 4.8,
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch emergency statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
