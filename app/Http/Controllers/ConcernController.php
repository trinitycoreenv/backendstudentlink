<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConcernRequest;
use App\Http\Requests\UpdateConcernRequest;
use App\Models\Concern;
use App\Models\ConcernMessage;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\WebSocketService;
use App\Services\FirebaseService;
use App\Services\SmartAssignmentService;
use App\Services\IntelligentPriorityDetectionService;
use App\Services\AutomatedWorkflowOrchestrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConcernController extends Controller
{
    protected AuditLogService $auditLogService;
    protected WebSocketService $webSocketService;
    protected FirebaseService $firebaseService;
    protected SmartAssignmentService $smartAssignmentService;
    protected IntelligentPriorityDetectionService $priorityDetectionService;
    protected AutomatedWorkflowOrchestrationService $workflowOrchestrationService;

    public function __construct(
        AuditLogService $auditLogService, 
        WebSocketService $webSocketService,
        FirebaseService $firebaseService,
        SmartAssignmentService $smartAssignmentService,
        IntelligentPriorityDetectionService $priorityDetectionService,
        AutomatedWorkflowOrchestrationService $workflowOrchestrationService
    ) {
        $this->auditLogService = $auditLogService;
        $this->webSocketService = $webSocketService;
        $this->firebaseService = $firebaseService;
        $this->smartAssignmentService = $smartAssignmentService;
        $this->priorityDetectionService = $priorityDetectionService;
        $this->workflowOrchestrationService = $workflowOrchestrationService;
    }

    /**
     * Get concerns list
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        try {
            $query = Concern::with(['student', 'department', 'facility', 'assignedTo']);

            // Filter by user role
            if ($user->role === 'student') {
                $query->where('student_id', $user->id);
            } elseif ($user->role === 'department_head') {
                $query->where('department_id', $user->department_id);
            }

            // Filter out archived concerns by default (unless explicitly requested)
            if (!$request->filled('include_archived')) {
                $query->notArchived();
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            // Filter by department
            if ($request->filled('department_id')) {
                $query->where('department_id', $request->input('department_id'));
            }

            // Filter by priority
            if ($request->filled('priority')) {
                $query->where('priority', $request->input('priority'));
            }

            // Filter by type
            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            // Filter by assigned user
            if ($request->filled('assigned_to')) {
                $query->where('assigned_to', $request->input('assigned_to'));
            }

            // Search by subject or description
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('subject', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%");
                });
            }

            $concerns = $query
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $concerns->items(),
                'pagination' => [
                    'current_page' => $concerns->currentPage(),
                    'last_page' => $concerns->lastPage(),
                    'per_page' => $concerns->perPage(),
                    'total' => $concerns->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch concerns',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create new concern
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        try {
            $request->validate([
                'subject' => 'required|string|max:255',
                'description' => 'required|string|max:5000',
                'department_id' => 'required|integer|exists:departments,id',
                'facility_id' => 'nullable|integer|exists:facilities,id',
                'type' => 'required|string|in:academic,administrative,technical,health,safety,other',
                'priority' => 'required|string|in:low,medium,high,urgent',
                'is_anonymous' => 'boolean',
                'attachments' => 'nullable|array',
                'attachments.*' => 'string',
            ]);

            $data = $request->all();
            
            // Only set student_id if user is a student
            if ($user->role === 'student') {
                $data['student_id'] = $user->id;
            } else {
                // For admin testing, we need to create a test student or handle differently
                return response()->json([
                    'success' => false,
                    'message' => 'Only students can submit concerns',
                    'error' => 'Invalid user role for concern submission'
                ], 403);
            }
            
            $data['reference_number'] = $this->generateReferenceNumber();
            $data['status'] = 'pending';

            $concern = Concern::create($data);

            // Step 1: Intelligent Priority Detection
            $priorityAnalysis = $this->priorityDetectionService->analyzePriority($concern);
            
            // Step 2: Smart Assignment
            $assignedUser = $this->smartAssignmentService->assignConcern($concern);
            if ($assignedUser) {
                $concern->update(['assigned_to' => $assignedUser->id]);
                
                // Create chat room for assigned concern
                $this->createChatRoomForAssignedConcern($concern, $assignedUser);
            }

            // Step 3: Auto-escalation if needed
            if ($priorityAnalysis['auto_escalation']) {
                $this->handleAutoEscalation($concern, $priorityAnalysis);
            }

            // Step 4: Automated Workflow Orchestration
            $workflowResult = $this->workflowOrchestrationService->orchestrateConcernWorkflow($concern);

            // Log the creation
            try {
                $this->auditLogService->logCrud('create', 'concern', $concern->id, null, [
                    'subject' => $concern->subject,
                    'type' => $concern->type,
                    'priority' => $concern->priority,
                    'workflow_actions' => $workflowResult['actions_taken'] ?? [],
                ], 'Concern created successfully');
            } catch (\Exception $e) {
                // Log error but don't fail the request
                \Log::error('Audit log failed: ' . $e->getMessage());
            }

            // Create notifications for assigned staff and department head
            $this->createAssignmentNotifications($concern, $assignedUser, $user, $priorityAnalysis);

            return response()->json([
                'success' => true,
                'message' => 'Concern submitted successfully',
                'data' => $concern->load(['student', 'department', 'facility']),
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Concern creation failed: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create concern',
                'error' => app()->environment('local') ? $e->getMessage() : null,
                'debug' => app()->environment('local') ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ] : null,
            ], 500);
        }
    }

    /**
     * Get assignment statistics
     */
    public function getAssignmentStats(): JsonResponse
    {
        try {
            $stats = $this->smartAssignmentService->getAssignmentStats();
            
            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get assignment statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get workflow orchestration statistics
     */
    public function getWorkflowStats(): JsonResponse
    {
        try {
            $stats = $this->workflowOrchestrationService->getWorkflowStats();
            
            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get workflow statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get specific concern
     */
    public function show(Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user can view this concern
            if ($user->role === 'student' && $concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Concern not found',
                ], 404);
            }

            $concern->load(['student', 'department', 'facility', 'assignedTo', 'messages.author']);

            return response()->json([
                'success' => true,
                'data' => $concern,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch concern',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update concern
     */
    public function update(UpdateConcernRequest $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            $data = $request->validated();
            $oldData = $concern->toArray();
            $concern->update($data);

            // Log the update
            $this->auditLogService->logCrud('update', 'concern', $concern->id, $oldData, [
                'subject' => $concern->subject,
                'changes' => array_keys($data),
            ], 'Concern updated successfully');

            return response()->json([
                'success' => true,
                'message' => 'Concern updated successfully',
                'data' => $concern->load(['student', 'department', 'facility', 'assignedTo']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update concern',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete concern
     */
    public function destroy(Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Only students can delete their own concerns
            if ($user->role === 'student' && $concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete this concern',
                ], 403);
            }
            
            // Students can delete their own concerns regardless of status
            // Admin and department heads can delete any concern

            $concernData = $concern->toArray();
            $concern->delete();

            // Log the deletion
            $this->auditLogService->logCrud('delete', 'concern', $concern->id, $concernData, null, json_encode([
                'subject' => $concernData['subject'] ?? 'N/A',
                'reference_number' => $concernData['reference_number'] ?? 'N/A',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Concern deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete concern',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Add message to concern
     */
    public function addMessage(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        $request->validate([
            'message' => 'required|string|max:2000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'is_internal' => 'nullable|boolean',
        ]);

        try {
            $message = ConcernMessage::create([
                'concern_id' => $concern->id,
                'author_id' => $user->id,
                'message' => $request->input('message'),
                'type' => 'message',
                'attachments' => $request->input('attachments', []),
                'is_internal' => $request->boolean('is_internal', false),
            ]);

            // Update concern's last activity
            $concern->touch();

            // Log the message
            $this->auditLogService->logCrud('create', 'concern_message', $message->id, null, [
                'concern_id' => $concern->id,
                'message_length' => strlen($request->input('message')),
                'has_attachments' => !empty($request->input('attachments')),
            ], 'Message added to concern');

            // Send push notifications for new messages
            $this->sendConcernMessageNotifications($concern, $message, $user);

            return response()->json([
                'success' => true,
                'message' => 'Message added successfully',
                'data' => $message->load('author'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add message',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get concern messages
     */
    public function getMessages(Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check access
            if ($user->role === 'student' && $concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied',
                ], 403);
            }

            $messages = $concern->messages()
                ->with('author')
                ->when($user->role === 'student', function ($query) {
                    $query->where('is_internal', false);
                })
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $messages,
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
     * Update concern status
     */
    public function updateStatus(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        $request->validate([
            'status' => 'required|in:pending,in_progress,staff_resolved,student_confirmed,disputed,closed,cancelled',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $oldStatus = $concern->status;
            $newStatus = $request->input('status');

            $concern->update([
                'status' => $newStatus,
                'resolved_at' => $newStatus === 'staff_resolved' ? now() : null,
                'student_resolved_at' => $newStatus === 'student_confirmed' ? now() : null,
                'disputed_at' => $newStatus === 'disputed' ? now() : null,
                'closed_at' => $newStatus === 'closed' ? now() : null,
            ]);

            // Add status change message
            if ($request->filled('note')) {
                ConcernMessage::create([
                    'concern_id' => $concern->id,
                    'author_id' => $user->id,
                    'message' => $request->input('note'),
                    'type' => 'status_change',
                    'metadata' => [
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ],
                ]);
            }

            // Log the status change
            $this->auditLogService->logCrud('update', 'concern', $concern->id, ['status' => $oldStatus], [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'has_note' => $request->filled('note'),
            ], 'Concern status updated');

            // Send push notification to student about status change
            $this->sendConcernStatusNotification($concern, $oldStatus, $newStatus, $request->input('note'));

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
                'data' => $concern->fresh(['student', 'department', 'facility', 'assignedTo']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Student confirms resolution
     */
    public function confirmResolution(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user can confirm this concern
            if ($concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only confirm your own concerns',
                ], 403);
            }

            // Check if concern can be confirmed
            if (!$concern->canBeConfirmedByStudent()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This concern cannot be confirmed at this time',
                ], 400);
            }

            $request->validate([
                'notes' => 'nullable|string|max:1000',
                'rating' => 'nullable|integer|min:1|max:5',
            ]);

            $oldStatus = $concern->status;
            
            $concern->update([
                'status' => 'student_confirmed',
                'student_resolved_at' => now(),
                'student_resolution_notes' => $request->input('notes'),
                'rating' => $request->input('rating'),
            ]);

            // Archive the concern when student confirms resolution
            $concern->archive();

            // Close chat room when student confirms resolution
            $this->closeChatRoomForConcern($concern);

            // Add confirmation message
            ConcernMessage::create([
                'concern_id' => $concern->id,
                'author_id' => $user->id,
                'message' => $request->input('notes') ?: 'Student confirmed that the concern has been resolved',
                'type' => 'resolution_confirmation',
                'metadata' => [
                    'old_status' => $oldStatus,
                    'new_status' => 'student_confirmed',
                    'confirmed_by' => 'student',
                ],
            ]);

            // Log the confirmation
            $this->auditLogService->logCrud('update', 'concern', $concern->id, ['status' => $oldStatus], [
                'old_status' => $oldStatus,
                'new_status' => 'student_confirmed',
                'confirmed_by' => 'student',
                'has_notes' => $request->filled('notes'),
            ], 'Concern resolution confirmed by student');

            // Broadcast resolution confirmation event
            $concernData = $concern->fresh(['student', 'department', 'facility', 'assignedTo', 'chatRoom'])->toArray();
            $this->webSocketService->broadcastResolutionConfirmed($concernData, $request->input('notes'));

            return response()->json([
                'success' => true,
                'message' => 'Resolution confirmed successfully',
                'data' => $concernData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm resolution',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Student disputes resolution
     */
    public function disputeResolution(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user can dispute this concern
            if ($concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only dispute your own concerns',
                ], 403);
            }

            // Check if concern can be disputed
            if (!$concern->canBeDisputedByStudent()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This concern cannot be disputed at this time',
                ], 400);
            }

            $request->validate([
                'reason' => 'required|string|max:1000',
            ]);

            $oldStatus = $concern->status;
            
            $concern->update([
                'status' => 'disputed',
                'disputed_at' => now(),
                'dispute_reason' => $request->input('reason'),
            ]);

            // Keep chat room active for disputed concerns
            $this->reopenChatRoomForConcern($concern);

            // Add dispute message
            ConcernMessage::create([
                'concern_id' => $concern->id,
                'author_id' => $user->id,
                'message' => "Student disputes the resolution: {$request->input('reason')}",
                'type' => 'resolution_dispute',
                'metadata' => [
                    'old_status' => $oldStatus,
                    'new_status' => 'disputed',
                    'disputed_by' => 'student',
                    'dispute_reason' => $request->input('reason'),
                ],
            ]);

            // Log the dispute
            $this->auditLogService->logCrud('update', 'concern', $concern->id, ['status' => $oldStatus], [
                'old_status' => $oldStatus,
                'new_status' => 'disputed',
                'disputed_by' => 'student',
                'dispute_reason' => $request->input('reason'),
            ], 'Concern resolution disputed by student');

            // Broadcast resolution dispute event
            $concernData = $concern->fresh(['student', 'department', 'facility', 'assignedTo', 'chatRoom'])->toArray();
            $this->webSocketService->broadcastResolutionDisputed($concernData, $request->input('reason'));

            return response()->json([
                'success' => true,
                'message' => 'Resolution disputed successfully',
                'data' => $concernData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to dispute resolution',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Assign concern to user
     */
    public function assign(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        try {
            $oldAssignee = $concern->assigned_to;
            $concern->update(['assigned_to' => $request->input('assigned_to')]);

            // Add assignment message
            ConcernMessage::create([
                'concern_id' => $concern->id,
                'author_id' => $user->id,
                'message' => 'Concern assigned to ' . $concern->assignedTo->name,
                'type' => 'assignment',
                'metadata' => [
                    'old_assignee' => $oldAssignee,
                    'new_assignee' => $request->input('assigned_to'),
                ],
            ]);

            // Log the assignment
            $this->auditLogService->logCrud('update', 'concern', $concern->id, ['assigned_to' => $oldAssignee], [
                'old_assignee' => $oldAssignee,
                'new_assignee' => $request->input('assigned_to'),
            ], 'Concern assignment updated');

            return response()->json([
                'success' => true,
                'message' => 'Concern assigned successfully',
                'data' => $concern->fresh(['student', 'department', 'facility', 'assignedTo']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign concern',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get concern history
     */
    public function getHistory(Concern $concern): JsonResponse
    {
        try {
            $history = $concern->messages()
                ->with('author')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'type' => $message->type,
                        'message' => $message->message,
                        'author' => [
                            'id' => $message->author->id,
                            'name' => $message->author->name,
                            'role' => $message->author->role,
                        ],
                        'metadata' => $message->metadata,
                        'created_at' => $message->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $history,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch concern history',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Upload attachment for concern
     */
    public function uploadAttachment(Request $request, Concern $concern): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $user = auth()->user();
            
            // Check if user can upload attachments to this concern
            if ($user->role === 'student' && $concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to upload attachments to this concern',
                ], 403);
            }

            $file = $request->file('file');
            $description = $request->input('description', '');
            
            // Generate unique filename
            $extension = $file->getClientOriginalExtension();
            $filename = 'concern_' . $concern->id . '_' . time() . '_' . \Str::random(8) . '.' . $extension;
            
            // Store file in concern-specific directory
            $path = $file->storeAs("uploads/concerns/{$concern->id}", $filename, 'public');
            
            // Get file URL
            $url = \Storage::url($path);
            
            // Create attachment data
            $attachment = [
                'id' => \Str::uuid(),
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => $url,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'description' => $description,
                'uploaded_by' => $user->id,
                'uploaded_at' => now()->toISOString(),
            ];
            
            // Get existing attachments
            $existingAttachments = $concern->attachments ?? [];
            $existingAttachments[] = $attachment;
            
            // Update concern with new attachment
            $concern->update(['attachments' => $existingAttachments]);
            
            // Log the action
            $this->auditLogService->log(
                'concern_attachment_uploaded',
                'concern',
                $concern->id,
                null,
                ['attachment' => $attachment],
                'Attachment uploaded to concern',
                ['attachment' => $attachment],
                'success'
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Attachment uploaded successfully',
                'data' => $attachment,
            ]);

        } catch (\Exception $e) {
            \Log::error('Concern attachment upload failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload attachment',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete attachment from concern
     */
    public function deleteAttachment(Request $request, Concern $concern, string $attachmentId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Check if user can delete attachments from this concern
            if ($user->role === 'student' && $concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete attachments from this concern',
                ], 403);
            }

            $attachments = $concern->attachments ?? [];
            $attachmentIndex = null;
            $attachmentToDelete = null;
            
            // Find the attachment to delete
            foreach ($attachments as $index => $attachment) {
                if ($attachment['id'] === $attachmentId) {
                    $attachmentIndex = $index;
                    $attachmentToDelete = $attachment;
                    break;
                }
            }
            
            if ($attachmentIndex === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attachment not found',
                ], 404);
            }
            
            // Check if user can delete this specific attachment
            if ($user->role === 'student' && $attachmentToDelete['uploaded_by'] !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this attachment',
                ], 403);
            }
            
            // Delete file from storage
            if (isset($attachmentToDelete['path'])) {
                \Storage::disk('public')->delete($attachmentToDelete['path']);
            }
            
            // Remove attachment from array
            array_splice($attachments, $attachmentIndex, 1);
            
            // Update concern
            $concern->update(['attachments' => $attachments]);
            
            // Log the action
            $this->auditLogService->log(
                'concern_attachment_deleted',
                'concern',
                $concern->id,
                ['attachment' => $attachmentToDelete],
                null,
                'Attachment deleted from concern',
                ['attachment_id' => $attachmentId, 'attachment' => $attachmentToDelete],
                'success'
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Attachment deleted successfully',
            ]);

        } catch (\Exception $e) {
            \Log::error('Concern attachment deletion failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attachment',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Generate unique reference number
     */
    private function generateReferenceNumber(): string
    {
        $prefix = 'CNR';
        $year = date('Y');
        $month = date('m');
        
        $lastConcern = Concern::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastConcern) {
            $lastNumber = (int) substr($lastConcern->reference_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $year . $month . $newNumber;
    }

    /**
     * Approve a concern
     */
    public function approve(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user can approve this concern
            if (!$this->canManageConcern($concern, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to approve this concern'
                ], 403);
            }

            // Check if concern can be approved
            if ($concern->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending concerns can be approved'
                ], 400);
            }

            // Update concern status
            $concern->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);

            // Create chat room for approved concern
            $this->createChatRoomForConcern($concern);

            // Log the approval
            $this->auditLogService->log($user, 'concern_approved', $concern, null, [
                'concern_id' => $concern->id,
                'concern_subject' => $concern->subject,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Concern approved successfully',
                'data' => $concern->fresh(['student', 'department', 'facility', 'approvedBy'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve concern',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Reject a concern
     */
    public function reject(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Validate rejection reason
            $request->validate([
                'rejection_reason' => 'required|string|min:10|max:1000',
            ]);

            // Check if user can reject this concern
            if (!$this->canManageConcern($concern, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to reject this concern'
                ], 403);
            }

            // Check if concern can be rejected
            if ($concern->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending concerns can be rejected'
                ], 400);
            }

            // Update concern status
            $concern->update([
                'status' => 'rejected',
                'rejection_reason' => $request->input('rejection_reason'),
                'rejected_at' => now(),
                'rejected_by' => $user->id,
            ]);

            // Log the rejection
            $this->auditLogService->log($user, 'concern_rejected', $concern, null, [
                'concern_id' => $concern->id,
                'concern_subject' => $concern->subject,
                'rejection_reason' => $request->input('rejection_reason'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Concern rejected successfully',
                'data' => $concern->fresh(['student', 'department', 'facility', 'rejectedBy'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject concern',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Check if user can manage a concern
     */
    private function canManageConcern(Concern $concern, $user): bool
    {
        // Admin can manage all concerns
        if ($user->role === 'admin') {
            return true;
        }

        // Department head can manage concerns in their department
        if ($user->role === 'department_head' && $user->department_id === $concern->department_id) {
            return true;
        }

        // Staff can manage concerns assigned to them
        if ($user->role === 'staff' && $concern->assigned_to === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Create chat room for approved concern
     */
    private function createChatRoomForConcern(Concern $concern): void
    {
        try {
            // Check if chat room already exists
            if ($concern->chatRoom) {
                return;
            }

            // Create chat room
            $participants = [
                $concern->student_id => [
                    'user_id' => $concern->student_id,
                    'role' => 'student',
                    'joined_at' => now()->toISOString(),
                ]
            ];

            // Add assigned staff member if available
            if ($concern->assigned_to) {
                $participants[$concern->assigned_to] = [
                    'user_id' => $concern->assigned_to,
                    'role' => 'staff',
                    'joined_at' => now()->toISOString(),
                ];
            } else {
                // Fallback to department head if no staff assigned
                $participants[$concern->approved_by] = [
                    'user_id' => $concern->approved_by,
                    'role' => 'department_head',
                    'joined_at' => now()->toISOString(),
                ];
            }

            $chatRoom = \App\Models\ChatRoom::create([
                'concern_id' => $concern->id,
                'room_name' => 'Concern #' . $concern->reference_number,
                'status' => 'active',
                'last_activity_at' => now(),
                'participants' => $participants,
                'settings' => [
                    'auto_assign' => true,
                    'notifications' => true,
                ]
            ]);

            // Create initial message from assigned staff or department head
            $messageAuthor = $concern->assigned_to ?: $concern->approved_by;
            $messageText = $concern->assigned_to 
                ? 'Hello! I\'m your assigned staff member and I\'m here to help you with your concern. Please feel free to ask any questions or provide additional information.'
                : 'Your concern has been approved and we are now ready to assist you. Please feel free to ask any questions or provide additional information.';

            $initialMessage = \App\Models\ConcernMessage::create([
                'concern_id' => $concern->id,
                'chat_room_id' => $chatRoom->id,
                'author_id' => $messageAuthor,
                'message' => $messageText,
                'type' => 'system',
                'message_type' => 'system',
                'is_internal' => false,
                'delivered_at' => now(),
            ]);

            // Broadcast chat room creation via WebSocket
            $this->webSocketService->broadcastChatRoomCreated($chatRoom, $initialMessage);

        } catch (\Exception $e) {
            \Log::error('Failed to create chat room for concern: ' . $e->getMessage());
        }
    }

    /**
     * Close chat room when concern is resolved
     */
    private function closeChatRoomForConcern(Concern $concern): void
    {
        try {
            if (!$concern->chatRoom) {
                return;
            }

            $chatRoom = $concern->chatRoom;
            $chatRoom->update([
                'status' => 'closed',
                'last_activity_at' => now(),
            ]);

            // Add closure message
            \App\Models\ConcernMessage::create([
                'concern_id' => $concern->id,
                'chat_room_id' => $chatRoom->id,
                'author_id' => $concern->student_id,
                'message' => 'This concern has been resolved and confirmed by the student. The chat room is now closed.',
                'type' => 'system',
                'message_type' => 'chat_closure',
                'is_internal' => false,
                'delivered_at' => now(),
                'metadata' => [
                    'closure_reason' => 'student_confirmed_resolution',
                    'resolved_at' => $concern->student_resolved_at,
                ],
            ]);

            \Log::info('Chat room closed for resolved concern', [
                'concern_id' => $concern->id,
                'chat_room_id' => $chatRoom->id,
            ]);

            // Broadcast chat room closure
            $concernData = $concern->fresh(['student', 'department', 'facility', 'assignedTo', 'chatRoom'])->toArray();
            $this->webSocketService->broadcastChatRoomStatusChange($concernData, 'closed', 'student_confirmed_resolution');

        } catch (\Exception $e) {
            \Log::error('Failed to close chat room for concern: ' . $e->getMessage());
        }
    }

    /**
     * Reopen chat room for disputed concerns
     */
    private function reopenChatRoomForConcern(Concern $concern): void
    {
        try {
            if (!$concern->chatRoom) {
                return;
            }

            $chatRoom = $concern->chatRoom;
            $chatRoom->update([
                'status' => 'active',
                'last_activity_at' => now(),
            ]);

            // Add reopening message
            \App\Models\ConcernMessage::create([
                'concern_id' => $concern->id,
                'chat_room_id' => $chatRoom->id,
                'author_id' => $concern->student_id,
                'message' => 'This concern has been disputed by the student. The chat room has been reopened for further discussion.',
                'type' => 'system',
                'message_type' => 'chat_reopened',
                'is_internal' => false,
                'delivered_at' => now(),
                'metadata' => [
                    'reopening_reason' => 'student_disputed_resolution',
                    'dispute_reason' => $concern->dispute_reason,
                    'disputed_at' => $concern->disputed_at,
                ],
            ]);

            \Log::info('Chat room reopened for disputed concern', [
                'concern_id' => $concern->id,
                'chat_room_id' => $chatRoom->id,
            ]);

            // Broadcast chat room reopening
            $concernData = $concern->fresh(['student', 'department', 'facility', 'assignedTo', 'chatRoom'])->toArray();
            $this->webSocketService->broadcastChatRoomStatusChange($concernData, 'active', 'student_disputed_resolution');

        } catch (\Exception $e) {
            \Log::error('Failed to reopen chat room for concern: ' . $e->getMessage());
        }
    }

    /**
     * Create chat room for automatically assigned concern
     */
    private function createChatRoomForAssignedConcern(Concern $concern, User $assignedStaff): void
    {
        try {
            // Check if chat room already exists
            if ($concern->chatRoom) {
                return;
            }

            // Prepare participants
            $participants = [
                $concern->student_id => [
                    'role' => 'student',
                    'user_id' => $concern->student_id,
                    'joined_at' => now()->toISOString(),
                ],
                $assignedStaff->id => [
                    'role' => 'staff',
                    'user_id' => $assignedStaff->id,
                    'joined_at' => now()->toISOString(),
                ],
            ];

            // Add department head if different from assigned staff
            if ($concern->department && $concern->department->head_id && $concern->department->head_id !== $assignedStaff->id) {
                $participants[$concern->department->head_id] = [
                    'role' => 'department_head',
                    'user_id' => $concern->department->head_id,
                    'joined_at' => now()->toISOString(),
                ];
            }

            $chatRoom = \App\Models\ChatRoom::create([
                'concern_id' => $concern->id,
                'room_name' => 'Concern #' . $concern->reference_number,
                'status' => 'active',
                'last_activity_at' => now(),
                'participants' => $participants,
            ]);

            // Create initial welcome message from assigned staff
            $initialMessage = \App\Models\ChatMessage::create([
                'concern_id' => $concern->id,
                'chat_room_id' => $chatRoom->id,
                'author_id' => $assignedStaff->id,
                'message' => "Hello! I'm {$assignedStaff->name} and I'll be helping you with your concern. How can I assist you today?",
                'message_type' => 'text',
                'is_internal' => false,
                'delivered_at' => now(),
            ]);

            // Broadcast chat room creation via WebSocket
            $this->webSocketService->broadcastChatRoomCreated($chatRoom, $initialMessage);

        } catch (\Exception $e) {
            \Log::error('Failed to create chat room for assigned concern: ' . $e->getMessage());
        }
    }

    /**
     * Send push notification for concern status change
     */
    private function sendConcernStatusNotification(Concern $concern, string $oldStatus, string $newStatus, ?string $note = null): void
    {
        try {
            $student = $concern->student;
            if (!$student) {
                \Log::warning('No student found for concern notification', [
                    'concern_id' => $concern->id,
                ]);
                return;
            }

            // Create status message
            $statusMessage = $this->getStatusChangeMessage($oldStatus, $newStatus);
            if ($note) {
                $statusMessage .= "\n\nNote: $note";
            }

            // Send push notification
            $result = $this->firebaseService->sendConcernUpdate(
                $student,
                $concern->id,
                $newStatus,
                $statusMessage
            );

            \Log::info('Concern status notification sent', [
                'concern_id' => $concern->id,
                'student_id' => $student->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to send concern status notification', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get human-readable status change message
     */
    private function getStatusChangeMessage(string $oldStatus, string $newStatus): string
    {
        $statusMessages = [
            'pending' => 'Your concern is pending review',
            'in_progress' => 'Your concern is now being processed',
            'staff_resolved' => 'Your concern has been resolved by staff',
            'student_confirmed' => 'You have confirmed the resolution',
            'disputed' => 'You have disputed the resolution',
            'closed' => 'Your concern has been closed',
            'cancelled' => 'Your concern has been cancelled',
        ];

        return $statusMessages[$newStatus] ?? "Your concern status has been updated to: {$newStatus}";
    }

    /**
     * Send push notifications for new concern messages
     */
    private function sendConcernMessageNotifications(Concern $concern, $message, User $sender): void
    {
        try {
            // Determine target users based on who sent the message
            $targetUsers = [];

            if ($sender->role === 'student') {
                // If student sent message, notify department head
                if ($concern->approved_by) {
                    $departmentHead = User::find($concern->approved_by);
                    if ($departmentHead) {
                        $targetUsers[] = $departmentHead;
                    }
                }
            } else {
                // If department head/admin sent message, notify student
                $student = $concern->student;
                if ($student) {
                    $targetUsers[] = $student;
                }
            }

            // Send notifications to target users
            foreach ($targetUsers as $targetUser) {
                $title = 'New message in Concern #' . $concern->reference_number;
                $body = $this->truncateMessage($message->message, 100);

                $result = $this->firebaseService->sendToUser($targetUser, $title, $body, [
                    'type' => 'concern_message',
                    'concern_id' => $concern->id,
                    'message_id' => $message->id,
                    'sender_id' => $sender->id,
                    'sender_name' => $sender->name,
                    'sender_role' => $sender->role,
                    'click_action' => 'OPEN_CONCERN_DETAILS',
                ]);

                \Log::info('Concern message notification sent', [
                    'concern_id' => $concern->id,
                    'target_user_id' => $targetUser->id,
                    'sender_id' => $sender->id,
                    'message_id' => $message->id,
                    'result' => $result,
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('Failed to send concern message notifications', [
                'concern_id' => $concern->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
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

    /**
     * Find the best assignee for a concern using smart assignment algorithms.
     */
    private function findBestAssignee(int $departmentId, string $priority): ?\App\Models\User
    {
        // Create a temporary concern object for the smart assignment service
        $tempConcern = new \App\Models\Concern([
            'department_id' => $departmentId,
            'priority' => $priority,
            'subject' => '',
            'description' => '',
            'type' => 'other'
        ]);
        
        // Use the smart assignment service
        return $this->smartAssignmentService->findBestAssignee($tempConcern);
    }

    /**
     * Trigger AI classification for a concern
     */
    public function triggerAIClassification(Request $request, $id): JsonResponse
    {
        try {
            $concern = Concern::findOrFail($id);
            
            // Call AI classification
            $aiResponse = $this->callAIClassification($concern);
            
            if ($aiResponse['success']) {
                $classification = $aiResponse['data'];
                
                // Update concern with AI classification
                $concern->update([
                    'ai_classification' => json_encode($classification),
                    'priority' => $classification['priority'],
                    'category' => $classification['category']
                ]);
                
                // Reassign if needed based on AI suggestions
                if ($classification['suggested_department'] && 
                    $classification['suggested_department']['id'] !== $concern->department_id) {
                    $this->reassignToSuggestedDepartment($concern, $classification['suggested_department']);
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'AI classification completed',
                    'data' => $classification
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'AI classification failed'
            ], 500);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to trigger AI classification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Call AI classification service via N8N webhook
     */
    private function callAIClassification(Concern $concern): array
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://testuservercel4.app.n8n.cloud/webhook-test/concern-created', [
                'json' => [
                    'id' => $concern->id,
                    'subject' => $concern->subject,
                    'description' => $concern->description,
                    'type' => $concern->type,
                    'department_id' => $concern->department_id,
                    'student_id' => $concern->student_id,
                    'priority' => $concern->priority,
                    'created_at' => $concern->created_at->toISOString()
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'timeout' => 30,
                'verify' => false // Disable SSL verification for development
            ]);
            
            $data = json_decode($response->getBody(), true);
            return $data;
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'N8N workflow unavailable',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Reassign concern to suggested department
     */
    private function reassignToSuggestedDepartment(Concern $concern, array $suggestedDepartment): void
    {
        // This would typically require admin approval
        // For now, we'll just log the suggestion
        \Log::info("AI suggested reassigning concern {$concern->id} to department {$suggestedDepartment['name']}");
    }

    /**
     * Get priority detection statistics
     */
    public function getPriorityDetectionStats(): JsonResponse
    {
        try {
            $stats = $this->priorityDetectionService->getPriorityDetectionStats();
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get priority detection statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Handle auto-escalation for urgent concerns
     */
    private function handleAutoEscalation(Concern $concern, array $priorityAnalysis): void
    {
        try {
            // Get department head
            $departmentHead = User::where('role', 'department_head')
                ->where('department_id', $concern->department_id)
                ->first();

            if ($departmentHead) {
                // Create urgent notification for department head
                \App\Models\Notification::create([
                    'user_id' => $departmentHead->id,
                    'type' => 'emergency',
                    'title' => 'URGENT: Auto-Escalated Concern',
                    'message' => "Concern #{$concern->reference_number} has been auto-escalated to URGENT priority: {$concern->subject}",
                    'data' => [
                        'concern_id' => $concern->id,
                        'priority_analysis' => $priorityAnalysis,
                        'escalation_reasons' => $priorityAnalysis['reasons'],
                    ],
                    'priority' => 'urgent',
                ]);

                // Send push notification
                $this->firebaseService->sendToUser(
                    $departmentHead,
                    'URGENT: Auto-Escalated Concern',
                    "Concern #{$concern->reference_number} requires immediate attention",
                    [
                        'type' => 'emergency',
                        'concern_id' => $concern->id,
                        'priority' => 'urgent'
                    ]
                );
            }

            // Log escalation
            \Log::info("Auto-escalated concern {$concern->id} to urgent priority", [
                'concern_id' => $concern->id,
                'reasons' => $priorityAnalysis['reasons'],
                'confidence' => $priorityAnalysis['confidence_score']
            ]);

        } catch (\Exception $e) {
            \Log::error("Auto-escalation failed for concern {$concern->id}: " . $e->getMessage());
        }
    }

    /**
     * Create assignment notifications
     */
    private function createAssignmentNotifications(Concern $concern, ?User $assignedUser, User $student, array $priorityAnalysis): void
    {
        try {
            // Notification for assigned staff
            if ($assignedUser) {
                \App\Models\Notification::create([
                    'user_id' => $assignedUser->id,
                    'type' => 'concern_assignment',
                    'title' => 'New Concern Assigned',
                    'message' => "You have been assigned a new {$concern->priority} priority concern: {$concern->subject}",
                    'data' => [
                        'concern_id' => $concern->id,
                        'student_name' => $concern->is_anonymous ? 'Anonymous Student' : $student->name,
                        'priority' => $concern->priority,
                        'reference_number' => $concern->reference_number,
                        'priority_analysis' => $priorityAnalysis,
                    ],
                    'priority' => $concern->priority,
                ]);

                // Send push notification to assigned staff
                $this->firebaseService->sendToUser(
                    $assignedUser,
                    'New Concern Assigned',
                    "You have been assigned concern #{$concern->reference_number}: {$concern->subject}",
                    [
                        'type' => 'concern_assignment',
                        'concern_id' => $concern->id,
                        'priority' => $concern->priority
                    ]
                );
            }

            // Notification for department head
            $departmentHead = User::where('role', 'department_head')
                ->where('department_id', $concern->department_id)
                ->first();

            if ($departmentHead) {
                \App\Models\Notification::create([
                    'user_id' => $departmentHead->id,
                    'type' => 'concern_update',
                    'title' => 'New Concern in Department',
                    'message' => "A new {$concern->priority} priority concern has been submitted: {$concern->subject}",
                    'data' => [
                        'concern_id' => $concern->id,
                        'student_name' => $concern->is_anonymous ? 'Anonymous Student' : $student->name,
                        'priority' => $concern->priority,
                        'reference_number' => $concern->reference_number,
                        'assigned_to' => $assignedUser ? $assignedUser->name : 'Unassigned',
                    ],
                    'priority' => $concern->priority === 'urgent' ? 'urgent' : 'medium',
                ]);
            }

            // Notification for student
            \App\Models\Notification::create([
                'user_id' => $student->id,
                'type' => 'concern_update',
                'title' => 'Concern Submitted Successfully',
                'message' => "Your concern #{$concern->reference_number} has been submitted and assigned to staff",
                'data' => [
                    'concern_id' => $concern->id,
                    'reference_number' => $concern->reference_number,
                    'assigned_to' => $assignedUser ? $assignedUser->name : 'Pending Assignment',
                ],
                'priority' => 'medium',
            ]);

        } catch (\Exception $e) {
            \Log::error('Assignment notification creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get concerns for N8N automation (no authentication required)
     */
    public function getConcernsForN8N(Request $request): JsonResponse
    {
        try {
            $query = Concern::with(['student', 'department', 'assignedTo', 'approvedBy']);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            // Filter by priority if provided
            if ($request->has('priority')) {
                $query->where('priority', $request->input('priority'));
            }

            // Filter by department if provided
            if ($request->has('department_id')) {
                $query->where('department_id', $request->input('department_id'));
            }

            // Filter by assigned staff if provided
            if ($request->has('assigned_to')) {
                $query->where('assigned_to', $request->input('assigned_to'));
            }

            // Filter by date range if provided
            if ($request->has('created_after')) {
                $query->where('created_at', '>=', $request->input('created_after'));
            }

            if ($request->has('created_before')) {
                $query->where('created_at', '<=', $request->input('created_before'));
            }

            // Get concerns
            $concerns = $query->orderBy('created_at', 'desc')->get();

            // Format response for N8N
            $formattedConcerns = $concerns->map(function ($concern) {
                return [
                    'id' => $concern->id,
                    'reference_number' => $concern->reference_number,
                    'subject' => $concern->subject,
                    'description' => $concern->description,
                    'status' => $concern->status,
                    'priority' => $concern->priority,
                    'type' => $concern->type,
                    'department_id' => $concern->department_id,
                    'department_name' => $concern->department->name ?? 'Unknown',
                    'student_id' => $concern->student_id,
                    'student_name' => $concern->is_anonymous ? 'Anonymous' : ($concern->student->name ?? 'Unknown'),
                    'assigned_to' => $concern->assigned_to,
                    'assigned_staff_name' => $concern->assignedTo->name ?? null,
                    'approved_by' => $concern->approved_by,
                    'approved_by_name' => $concern->approvedBy->name ?? null,
                    'created_at' => $concern->created_at->toISOString(),
                    'updated_at' => $concern->updated_at->toISOString(),
                    'last_activity_at' => $concern->last_activity_at?->toISOString(),
                    'escalated_at' => $concern->escalated_at?->toISOString(),
                    'escalated_by' => $concern->escalated_by,
                    'escalation_reason' => $concern->escalation_reason,
                    'auto_approved' => $concern->auto_approved,
                    'auto_closed' => $concern->auto_closed,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedConcerns,
                'total' => $concerns->count(),
                'filters_applied' => $request->only(['status', 'priority', 'department_id', 'assigned_to', 'created_after', 'created_before'])
            ]);

        } catch (\Exception $e) {
            \Log::error('N8N concerns fetch failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch concerns for N8N',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
