<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Concern;
use App\Models\User;
use App\Models\Department;
use App\Services\SmartAssignmentService;
use App\Services\AutomatedEscalationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class EscalationController extends Controller
{
    protected SmartAssignmentService $smartAssignmentService;
    protected AutomatedEscalationService $automatedEscalationService;

    public function __construct(
        SmartAssignmentService $smartAssignmentService,
        AutomatedEscalationService $automatedEscalationService
    ) {
        $this->smartAssignmentService = $smartAssignmentService;
        $this->automatedEscalationService = $automatedEscalationService;
    }

    /**
     * Check for overdue concerns and escalate using automated service
     */
    public function checkAndEscalate(): JsonResponse
    {
        try {
            $results = $this->automatedEscalationService->checkAndEscalate();

            return response()->json([
                'success' => true,
                'message' => 'Automated escalation check completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Automated Escalation Check Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to check and escalate concerns',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get escalation thresholds based on priority
     */
    private function getEscalationThresholds(string $priority): array
    {
        return match($priority) {
            'urgent' => [
                'reminder_hours' => 2,
                'escalation_hours' => 6,
                'department_head_hours' => 12,
                'admin_hours' => 24
            ],
            'high' => [
                'reminder_hours' => 6,
                'escalation_hours' => 24,
                'department_head_hours' => 48,
                'admin_hours' => 72
            ],
            default => [
                'reminder_hours' => 24,
                'escalation_hours' => 72,
                'department_head_hours' => 120,
                'admin_hours' => 168
            ]
        };
    }

    /**
     * Check if concern should be escalated
     */
    private function shouldEscalate(Concern $concern, int $hoursSinceCreated, int $hoursSinceAssigned, array $thresholds): bool
    {
        // Don't escalate if already escalated recently
        if ($concern->escalated_at && $concern->escalated_at->diffInHours(now()) < 24) {
            return false;
        }

        // Check escalation thresholds
        if ($hoursSinceAssigned > 0) {
            return $hoursSinceAssigned >= $thresholds['escalation_hours'];
        }

        return $hoursSinceCreated >= $thresholds['escalation_hours'];
    }

    /**
     * Check if concern should receive a reminder
     */
    private function shouldSendReminder(Concern $concern, int $hoursSinceCreated, int $hoursSinceAssigned, array $thresholds): bool
    {
        // Don't send reminder if sent recently
        if ($concern->last_reminder_sent && $concern->last_reminder_sent->diffInHours(now()) < 12) {
            return false;
        }

        // Check reminder thresholds
        if ($hoursSinceAssigned > 0) {
            return $hoursSinceAssigned >= $thresholds['reminder_hours'];
        }

        return $hoursSinceCreated >= $thresholds['reminder_hours'];
    }

    /**
     * Escalate a concern
     */
    private function escalateConcern(Concern $concern): bool
    {
        try {
            DB::beginTransaction();

            $escalationLevel = $this->determineEscalationLevel($concern);
            $newAssignee = $this->findEscalationAssignee($concern, $escalationLevel);

            if ($newAssignee) {
                // Update concern
                $concern->update([
                    'assigned_to' => $newAssignee->id,
                    'escalated_at' => now(),
                    'escalation_level' => $escalationLevel,
                    'escalation_reason' => $this->getEscalationReason($concern, $escalationLevel),
                    'status' => 'in_progress'
                ]);

                // Log escalation
                Log::info("Concern escalated", [
                    'concern_id' => $concern->id,
                    'from_staff' => $concern->assignedTo?->id,
                    'to_staff' => $newAssignee->id,
                    'escalation_level' => $escalationLevel
                ]);

                // Send notification
                $this->sendEscalationNotification($concern, $newAssignee, $escalationLevel);

                DB::commit();
                return true;
            }

            DB::rollBack();
            return false;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Escalate Concern Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send reminder for a concern
     */
    private function sendReminder(Concern $concern): bool
    {
        try {
            // Update last reminder sent
            $concern->update(['last_reminder_sent' => now()]);

            // Send notification to assigned staff
            if ($concern->assignedTo) {
                $this->sendReminderNotification($concern, $concern->assignedTo);
            }

            Log::info("Reminder sent for concern", [
                'concern_id' => $concern->id,
                'assigned_to' => $concern->assignedTo?->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Send Reminder Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Determine escalation level
     */
    private function determineEscalationLevel(Concern $concern): string
    {
        $hoursSinceAssigned = $concern->assigned_at ? $concern->assigned_at->diffInHours(now()) : 0;
        $thresholds = $this->getEscalationThresholds($concern->priority);

        if ($hoursSinceAssigned >= $thresholds['admin_hours']) {
            return 'admin';
        } elseif ($hoursSinceAssigned >= $thresholds['department_head_hours']) {
            return 'department_head';
        } else {
            return 'staff';
        }
    }

    /**
     * Find appropriate assignee for escalation
     */
    private function findEscalationAssignee(Concern $concern, string $escalationLevel): ?User
    {
        switch ($escalationLevel) {
            case 'admin':
                return User::where('role', 'admin')->where('is_active', true)->first();
                
            case 'department_head':
                return User::where('role', 'department_head')
                    ->where('department_id', $concern->department_id)
                    ->where('is_active', true)
                    ->first();
                    
            case 'staff':
                // Find another staff member in the same department
                $availableStaff = User::where('role', 'staff')
                    ->where('department_id', $concern->department_id)
                    ->where('id', '!=', $concern->assigned_to)
                    ->where('is_active', true)
                    ->with(['assignedConcerns' => function($query) {
                        $query->whereIn('status', ['pending', 'in_progress']);
                    }])
                    ->get()
                    ->filter(function($staff) {
                        return $staff->assignedConcerns->count() < 10;
                    });

                return $availableStaff->sortBy(function($staff) {
                    return $staff->assignedConcerns->count();
                })->first();
        }

        return null;
    }

    /**
     * Get escalation reason
     */
    private function getEscalationReason(Concern $concern, string $escalationLevel): string
    {
        $hoursSinceAssigned = $concern->assigned_at ? $concern->assigned_at->diffInHours(now()) : 0;
        
        return match($escalationLevel) {
            'admin' => "Escalated to admin after {$hoursSinceAssigned} hours without resolution",
            'department_head' => "Escalated to department head after {$hoursSinceAssigned} hours without resolution",
            'staff' => "Reassigned to different staff member after {$hoursSinceAssigned} hours without progress"
        };
    }

    /**
     * Send escalation notification
     */
    private function sendEscalationNotification(Concern $concern, User $newAssignee, string $escalationLevel): void
    {
        // This would integrate with your notification system
        Log::info("Escalation notification sent", [
            'concern_id' => $concern->id,
            'new_assignee_id' => $newAssignee->id,
            'escalation_level' => $escalationLevel
        ]);
    }

    /**
     * Send reminder notification
     */
    private function sendReminderNotification(Concern $concern, User $assignee): void
    {
        // This would integrate with your notification system
        Log::info("Reminder notification sent", [
            'concern_id' => $concern->id,
            'assignee_id' => $assignee->id
        ]);
    }

    /**
     * Get escalation statistics
     */
    public function getEscalationStats(Request $request): JsonResponse
    {
        try {
            $stats = $this->automatedEscalationService->getEscalationStats();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Escalation Stats Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get escalation statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manual escalation endpoint
     */
    public function manualEscalate(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'reason' => 'required|string|max:500'
            ]);

            $concern = Concern::findOrFail($id);
            $user = auth()->user();

            // Verify user has permission to escalate
            if ($user->role === 'department_head' && $user->department_id !== $concern->department_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to escalate this concern'
                ], 403);
            }

            $reason = $request->input('reason');
            $success = $this->automatedEscalationService->manualEscalate($concern, $user, $reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Concern escalated successfully',
                    'data' => [
                        'concern_id' => $concern->id,
                        'escalated_by' => $user->name,
                        'reason' => $reason
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to escalate concern'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Manual Escalation Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to escalate concern',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
