<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\User;
use App\Models\Notification;
use App\Services\FirebaseService;
use App\Services\IntelligentPriorityDetectionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AutomatedWorkflowOrchestrationService
{
    protected FirebaseService $firebaseService;
    protected IntelligentPriorityDetectionService $priorityDetectionService;

    // Configuration
    private $autoApprovalConfidenceThreshold = 0.8;
    private $escalationTimeThresholds = [
        'urgent' => 2, // hours
        'high' => 8,   // hours
        'medium' => 24, // hours
        'low' => 72,   // hours
    ];
    private $autoClosureDays = 7; // Auto-close after 7 days of student confirmation

    public function __construct(
        FirebaseService $firebaseService,
        IntelligentPriorityDetectionService $priorityDetectionService
    ) {
        $this->firebaseService = $firebaseService;
        $this->priorityDetectionService = $priorityDetectionService;
    }

    /**
     * Orchestrate the complete concern lifecycle workflow
     */
    public function orchestrateConcernWorkflow(Concern $concern): array
    {
        try {
            Log::info("Starting workflow orchestration for concern {$concern->id}", [
                'concern_id' => $concern->id,
                'status' => $concern->status,
                'priority' => $concern->priority,
                'type' => $concern->type
            ]);

            $workflowResults = [
                'concern_id' => $concern->id,
                'actions_taken' => [],
                'notifications_sent' => [],
                'escalations' => [],
                'auto_approvals' => [],
                'errors' => []
            ];

            // Step 1: Auto-approval for routine concerns
            if ($concern->status === 'pending') {
                $approvalResult = $this->processAutoApproval($concern);
                if ($approvalResult['auto_approved']) {
                    $workflowResults['auto_approvals'][] = $approvalResult;
                    $workflowResults['actions_taken'][] = 'auto_approved';
                }
            }

            // Step 2: Check for escalation triggers
            if (in_array($concern->status, ['pending', 'in_progress', 'approved'])) {
                $escalationResult = $this->checkEscalationTriggers($concern);
                if ($escalationResult['escalated']) {
                    $workflowResults['escalations'][] = $escalationResult;
                    $workflowResults['actions_taken'][] = 'escalated';
                }
            }

            // Step 3: Send smart notifications
            $notificationResult = $this->sendSmartNotifications($concern);
            $workflowResults['notifications_sent'] = $notificationResult;

            // Step 4: Auto-close resolved concerns
            if ($concern->status === 'student_confirmed') {
                $closureResult = $this->processAutoClosure($concern);
                if ($closureResult['auto_closed']) {
                    $workflowResults['actions_taken'][] = 'auto_closed';
                }
            }

            // Step 5: Schedule follow-up tasks
            $this->scheduleFollowUpTasks($concern);

            Log::info("Workflow orchestration completed for concern {$concern->id}", [
                'actions_taken' => $workflowResults['actions_taken'],
                'notifications_count' => count($workflowResults['notifications_sent'])
            ]);

            return $workflowResults;

        } catch (\Exception $e) {
            Log::error("Workflow orchestration failed for concern {$concern->id}: " . $e->getMessage());
            return [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
                'actions_taken' => [],
                'notifications_sent' => [],
                'escalations' => [],
                'auto_approvals' => []
            ];
        }
    }

    /**
     * Process auto-approval for routine concerns
     */
    private function processAutoApproval(Concern $concern): array
    {
        try {
            // Get AI analysis for auto-approval decision
            $priorityAnalysis = $this->priorityDetectionService->analyzePriority($concern);
            
            // Auto-approve if:
            // 1. High confidence in classification
            // 2. Not urgent/high priority
            // 3. Routine concern type
            $shouldAutoApprove = $this->shouldAutoApprove($concern, $priorityAnalysis);

            if ($shouldAutoApprove) {
                // Auto-approve the concern
                $concern->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => null, // System approval
                    'auto_approved' => true,
                ]);

                // Create chat room for auto-approved concern
                $this->createChatRoomForConcern($concern);

                // Send notification to student
                $this->sendAutoApprovalNotification($concern);

                Log::info("Auto-approved concern {$concern->id}", [
                    'confidence' => $priorityAnalysis['confidence_score'],
                    'priority' => $concern->priority,
                    'type' => $concern->type
                ]);

                return [
                    'auto_approved' => true,
                    'confidence' => $priorityAnalysis['confidence_score'],
                    'reason' => 'Routine concern with high AI confidence',
                    'approved_at' => now()->toISOString()
                ];
            }

            return ['auto_approved' => false, 'reason' => 'Does not meet auto-approval criteria'];

        } catch (\Exception $e) {
            Log::error("Auto-approval failed for concern {$concern->id}: " . $e->getMessage());
            return ['auto_approved' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Determine if concern should be auto-approved
     */
    private function shouldAutoApprove(Concern $concern, array $priorityAnalysis): bool
    {
        // Don't auto-approve urgent or high priority concerns
        if (in_array($concern->priority, ['urgent', 'high'])) {
            return false;
        }

        // Don't auto-approve safety or emergency concerns
        if (in_array($concern->type, ['safety', 'emergency'])) {
            return false;
        }

        // Don't auto-approve if AI confidence is low
        if ($priorityAnalysis['confidence_score'] < $this->autoApprovalConfidenceThreshold) {
            return false;
        }

        // Don't auto-approve if there are urgent keywords
        if (!empty($priorityAnalysis['keywords_found'])) {
            $urgentKeywords = ['urgent', 'emergency', 'help', 'broken', 'stuck', 'asap'];
            foreach ($priorityAnalysis['keywords_found'] as $keyword) {
                if (in_array(strtolower($keyword), $urgentKeywords)) {
                    return false;
                }
            }
        }

        // Auto-approve routine concerns with high confidence
        return true;
    }

    /**
     * Check for escalation triggers
     */
    private function checkEscalationTriggers(Concern $concern): array
    {
        try {
            $escalationResult = [
                'escalated' => false,
                'reason' => null,
                'escalated_at' => null,
                'escalated_to' => null
            ];

            // Check time-based escalation
            $timeEscalation = $this->checkTimeBasedEscalation($concern);
            if ($timeEscalation['should_escalate']) {
                $escalationResult = $this->executeEscalation($concern, $timeEscalation);
            }

            // Check priority-based escalation
            if (!$escalationResult['escalated']) {
                $priorityEscalation = $this->checkPriorityBasedEscalation($concern);
                if ($priorityEscalation['should_escalate']) {
                    $escalationResult = $this->executeEscalation($concern, $priorityEscalation);
                }
            }

            return $escalationResult;

        } catch (\Exception $e) {
            Log::error("Escalation check failed for concern {$concern->id}: " . $e->getMessage());
            return ['escalated' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check time-based escalation
     */
    private function checkTimeBasedEscalation(Concern $concern): array
    {
        $thresholdHours = $this->escalationTimeThresholds[$concern->priority] ?? 24;
        $hoursSinceCreated = $concern->created_at->diffInHours(now());

        if ($hoursSinceCreated >= $thresholdHours) {
            return [
                'should_escalate' => true,
                'reason' => "Concern overdue by {$hoursSinceCreated} hours (threshold: {$thresholdHours}h)",
                'escalation_type' => 'time_based',
                'hours_overdue' => $hoursSinceCreated - $thresholdHours
            ];
        }

        return ['should_escalate' => false];
    }

    /**
     * Check priority-based escalation
     */
    private function checkPriorityBasedEscalation(Concern $concern): array
    {
        // Escalate urgent concerns immediately if not assigned
        if ($concern->priority === 'urgent' && !$concern->assigned_to) {
            return [
                'should_escalate' => true,
                'reason' => 'Urgent concern not assigned to staff',
                'escalation_type' => 'priority_based'
            ];
        }

        // Escalate high priority concerns if no response in 4 hours
        if ($concern->priority === 'high' && !$concern->assigned_to) {
            $hoursSinceCreated = $concern->created_at->diffInHours(now());
            if ($hoursSinceCreated >= 4) {
                return [
                    'should_escalate' => true,
                    'reason' => 'High priority concern not assigned within 4 hours',
                    'escalation_type' => 'priority_based'
                ];
            }
        }

        return ['should_escalate' => false];
    }

    /**
     * Execute escalation
     */
    private function executeEscalation(Concern $concern, array $escalationData): array
    {
        try {
            // Get department head
            $departmentHead = User::where('role', 'department_head')
                ->where('department_id', $concern->department_id)
                ->first();

            if (!$departmentHead) {
                return [
                    'escalated' => false,
                    'error' => 'No department head found for escalation'
                ];
            }

            // Update concern status
            $concern->update([
                'escalated_at' => now(),
                'escalated_by' => 'system',
                'escalation_reason' => $escalationData['reason']
            ]);

            // Create escalation notification
            $this->createEscalationNotification($concern, $departmentHead, $escalationData);

            // Send push notification
            $this->firebaseService->sendToUser(
                $departmentHead,
                'URGENT: Concern Escalated',
                "Concern #{$concern->reference_number} has been escalated: {$escalationData['reason']}",
                [
                    'type' => 'escalation',
                    'concern_id' => $concern->id,
                    'priority' => 'urgent'
                ]
            );

            Log::info("Escalated concern {$concern->id} to department head {$departmentHead->id}", [
                'reason' => $escalationData['reason'],
                'escalation_type' => $escalationData['escalation_type']
            ]);

            return [
                'escalated' => true,
                'reason' => $escalationData['reason'],
                'escalated_at' => now()->toISOString(),
                'escalated_to' => $departmentHead->id,
                'escalation_type' => $escalationData['escalation_type']
            ];

        } catch (\Exception $e) {
            Log::error("Escalation execution failed for concern {$concern->id}: " . $e->getMessage());
            return ['escalated' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send smart notifications based on context
     */
    private function sendSmartNotifications(Concern $concern): array
    {
        $notifications = [];

        try {
            // Determine notification timing and channels based on priority and time
            $notificationConfig = $this->getNotificationConfig($concern);

            // Send notifications to relevant parties
            if ($concern->assigned_to) {
                $staff = User::find($concern->assigned_to);
                if ($staff) {
                    $notifications[] = $this->sendStaffNotification($concern, $staff, $notificationConfig);
                }
            }

            // Send notification to student
            $student = $concern->student;
            if ($student) {
                $notifications[] = $this->sendStudentNotification($concern, $student, $notificationConfig);
            }

            // Send notification to department head if needed
            if ($this->shouldNotifyDepartmentHead($concern, $notificationConfig)) {
                $departmentHead = User::where('role', 'department_head')
                    ->where('department_id', $concern->department_id)
                    ->first();
                
                if ($departmentHead) {
                    $notifications[] = $this->sendDepartmentHeadNotification($concern, $departmentHead, $notificationConfig);
                }
            }

        } catch (\Exception $e) {
            Log::error("Smart notification sending failed for concern {$concern->id}: " . $e->getMessage());
        }

        return $notifications;
    }

    /**
     * Get notification configuration based on concern context
     */
    private function getNotificationConfig(Concern $concern): array
    {
        $currentHour = now()->hour;
        $isBusinessHours = $currentHour >= 8 && $currentHour <= 18;
        $isWeekend = now()->isWeekend();

        $config = [
            'immediate' => false,
            'channels' => ['push'],
            'priority' => 'medium',
            'delay_minutes' => 0
        ];

        // Urgent concerns get immediate notifications
        if ($concern->priority === 'urgent') {
            $config['immediate'] = true;
            $config['channels'] = ['push', 'sms'];
            $config['priority'] = 'urgent';
        }

        // High priority concerns get immediate notifications during business hours
        elseif ($concern->priority === 'high' && $isBusinessHours && !$isWeekend) {
            $config['immediate'] = true;
            $config['channels'] = ['push', 'email'];
            $config['priority'] = 'high';
        }

        // Medium priority concerns get delayed notifications outside business hours
        elseif ($concern->priority === 'medium' && (!$isBusinessHours || $isWeekend)) {
            $config['delay_minutes'] = 30;
            $config['channels'] = ['push'];
        }

        // Low priority concerns get batched notifications
        elseif ($concern->priority === 'low') {
            $config['delay_minutes'] = 60;
            $config['channels'] = ['push'];
        }

        return $config;
    }

    /**
     * Send notification to assigned staff
     */
    private function sendStaffNotification(Concern $concern, User $staff, array $config): array
    {
        $title = "New Concern Assigned";
        $message = "You have been assigned concern #{$concern->reference_number}: {$concern->subject}";

        // Create database notification
        Notification::create([
            'user_id' => $staff->id,
            'type' => 'concern_assignment',
            'title' => $title,
            'message' => $message,
            'data' => [
                'concern_id' => $concern->id,
                'priority' => $concern->priority,
                'reference_number' => $concern->reference_number,
            ],
            'priority' => $config['priority'],
        ]);

        // Send push notification
        $this->firebaseService->sendToUser($staff, $title, $message, [
            'type' => 'concern_assignment',
            'concern_id' => $concern->id,
            'priority' => $concern->priority
        ]);

        return [
            'recipient' => $staff->id,
            'type' => 'staff_assignment',
            'channels' => $config['channels'],
            'sent_at' => now()->toISOString()
        ];
    }

    /**
     * Send notification to student
     */
    private function sendStudentNotification(Concern $concern, User $student, array $config): array
    {
        $title = "Concern Update";
        $message = "Your concern #{$concern->reference_number} has been {$concern->status}";

        // Create database notification
        Notification::create([
            'user_id' => $student->id,
            'type' => 'concern_update',
            'title' => $title,
            'message' => $message,
            'data' => [
                'concern_id' => $concern->id,
                'status' => $concern->status,
                'reference_number' => $concern->reference_number,
            ],
            'priority' => $config['priority'],
        ]);

        // Send push notification
        $this->firebaseService->sendToUser($student, $title, $message, [
            'type' => 'concern_update',
            'concern_id' => $concern->id,
            'status' => $concern->status
        ]);

        return [
            'recipient' => $student->id,
            'type' => 'student_update',
            'channels' => $config['channels'],
            'sent_at' => now()->toISOString()
        ];
    }

    /**
     * Send notification to department head
     */
    private function sendDepartmentHeadNotification(Concern $concern, User $departmentHead, array $config): array
    {
        $title = "Department Concern Update";
        $message = "Concern #{$concern->reference_number} in your department has been updated";

        // Create database notification
        Notification::create([
            'user_id' => $departmentHead->id,
            'type' => 'department_update',
            'title' => $title,
            'message' => $message,
            'data' => [
                'concern_id' => $concern->id,
                'department_id' => $concern->department_id,
                'reference_number' => $concern->reference_number,
            ],
            'priority' => $config['priority'],
        ]);

        return [
            'recipient' => $departmentHead->id,
            'type' => 'department_update',
            'channels' => $config['channels'],
            'sent_at' => now()->toISOString()
        ];
    }

    /**
     * Determine if department head should be notified
     */
    private function shouldNotifyDepartmentHead(Concern $concern, array $config): bool
    {
        // Always notify for urgent concerns
        if ($concern->priority === 'urgent') {
            return true;
        }

        // Notify for high priority concerns during business hours
        if ($concern->priority === 'high' && $config['immediate']) {
            return true;
        }

        // Notify for escalated concerns
        if ($concern->escalated_at) {
            return true;
        }

        return false;
    }

    /**
     * Create escalation notification
     */
    private function createEscalationNotification(Concern $concern, User $departmentHead, array $escalationData): void
    {
        Notification::create([
            'user_id' => $departmentHead->id,
            'type' => 'escalation',
            'title' => 'URGENT: Concern Escalated',
            'message' => "Concern #{$concern->reference_number} has been escalated: {$escalationData['reason']}",
            'data' => [
                'concern_id' => $concern->id,
                'escalation_reason' => $escalationData['reason'],
                'escalation_type' => $escalationData['escalation_type'],
                'reference_number' => $concern->reference_number,
            ],
            'priority' => 'urgent',
        ]);
    }

    /**
     * Process auto-closure for resolved concerns
     */
    private function processAutoClosure(Concern $concern): array
    {
        try {
            if (!$concern->student_resolved_at) {
                return ['auto_closed' => false, 'reason' => 'Not student confirmed'];
            }

            $daysSinceConfirmation = $concern->student_resolved_at->diffInDays(now());

            if ($daysSinceConfirmation >= $this->autoClosureDays) {
                $concern->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => 'system',
                    'auto_closed' => true
                ]);

                Log::info("Auto-closed concern {$concern->id} after {$daysSinceConfirmation} days");

                return [
                    'auto_closed' => true,
                    'days_since_confirmation' => $daysSinceConfirmation,
                    'closed_at' => now()->toISOString()
                ];
            }

            return ['auto_closed' => false, 'reason' => 'Not yet time for auto-closure'];

        } catch (\Exception $e) {
            Log::error("Auto-closure failed for concern {$concern->id}: " . $e->getMessage());
            return ['auto_closed' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Schedule follow-up tasks based on concern type
     */
    private function scheduleFollowUpTasks(Concern $concern): void
    {
        try {
            // Schedule follow-up based on concern type and priority
            $followUpDays = $this->getFollowUpSchedule($concern);

            if ($followUpDays > 0) {
                // This would integrate with a task scheduling system
                // For now, we'll log the follow-up task
                Log::info("Scheduled follow-up task for concern {$concern->id}", [
                    'follow_up_days' => $followUpDays,
                    'concern_type' => $concern->type,
                    'priority' => $concern->priority
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Follow-up task scheduling failed for concern {$concern->id}: " . $e->getMessage());
        }
    }

    /**
     * Get follow-up schedule based on concern type
     */
    private function getFollowUpSchedule(Concern $concern): int
    {
        $schedules = [
            'academic' => 7,      // Follow up in 7 days
            'administrative' => 5, // Follow up in 5 days
            'technical' => 3,     // Follow up in 3 days
            'health' => 1,        // Follow up in 1 day
            'safety' => 1,        // Follow up in 1 day
            'other' => 7,         // Follow up in 7 days
        ];

        return $schedules[$concern->type] ?? 7;
    }

    /**
     * Create chat room for auto-approved concern
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
            }

            \App\Models\ChatRoom::create([
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

        } catch (\Exception $e) {
            Log::error("Chat room creation failed for concern {$concern->id}: " . $e->getMessage());
        }
    }

    /**
     * Send auto-approval notification to student
     */
    private function sendAutoApprovalNotification(Concern $concern): void
    {
        try {
            $student = $concern->student;
            if (!$student) return;

            $title = "Concern Auto-Approved";
            $message = "Your concern #{$concern->reference_number} has been automatically approved and is now being processed";

            // Create database notification
            Notification::create([
                'user_id' => $student->id,
                'type' => 'concern_update',
                'title' => $title,
                'message' => $message,
                'data' => [
                    'concern_id' => $concern->id,
                    'status' => 'approved',
                    'reference_number' => $concern->reference_number,
                    'auto_approved' => true,
                ],
                'priority' => 'medium',
            ]);

            // Send push notification
            $this->firebaseService->sendToUser($student, $title, $message, [
                'type' => 'concern_update',
                'concern_id' => $concern->id,
                'status' => 'approved'
            ]);

        } catch (\Exception $e) {
            Log::error("Auto-approval notification failed for concern {$concern->id}: " . $e->getMessage());
        }
    }

    /**
     * Get workflow orchestration statistics
     */
    public function getWorkflowStats(): array
    {
        try {
            $stats = [
                'auto_approvals' => Concern::where('auto_approved', true)->count(),
                'escalations' => Concern::whereNotNull('escalated_at')->count(),
                'auto_closures' => Concern::where('auto_closed', true)->count(),
                'total_workflows_processed' => Concern::count(),
            ];

            // Calculate percentages
            $total = $stats['total_workflows_processed'];
            if ($total > 0) {
                $stats['auto_approval_rate'] = ($stats['auto_approvals'] / $total) * 100;
                $stats['escalation_rate'] = ($stats['escalations'] / $total) * 100;
                $stats['auto_closure_rate'] = ($stats['auto_closures'] / $total) * 100;
            } else {
                $stats['auto_approval_rate'] = 0;
                $stats['escalation_rate'] = 0;
                $stats['auto_closure_rate'] = 0;
            }

            return $stats;

        } catch (\Exception $e) {
            Log::error("Failed to get workflow stats: " . $e->getMessage());
            return [];
        }
    }
}
