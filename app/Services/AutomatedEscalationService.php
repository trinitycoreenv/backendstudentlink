<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AutomatedEscalationService
{
    private $escalationThresholds = [
        'urgent' => 2,      // 2 hours
        'high' => 8,        // 8 hours
        'normal' => 24,     // 24 hours
        'low' => 48         // 48 hours
    ];

    private $overdueThresholds = [
        'urgent' => 4,      // 4 hours
        'high' => 12,       // 12 hours
        'normal' => 48,     // 48 hours
        'low' => 72         // 72 hours
    ];

    /**
     * Check and escalate concerns that need attention
     */
    public function checkAndEscalate(): array
    {
        $results = [
            'escalated' => 0,
            'overdue' => 0,
            'notifications_sent' => 0,
            'errors' => []
        ];

        try {
            Log::info('Starting automated escalation check');

            // Get concerns that need escalation
            $concernsToEscalate = $this->getConcernsNeedingEscalation();
            $overdueConcerns = $this->getOverdueConcerns();

            // Process escalations
            foreach ($concernsToEscalate as $concern) {
                try {
                    $this->escalateConcern($concern);
                    $results['escalated']++;
                    $results['notifications_sent']++;
                } catch (\Exception $e) {
                    $results['errors'][] = "Failed to escalate concern {$concern->id}: " . $e->getMessage();
                    Log::error("Escalation failed for concern {$concern->id}: " . $e->getMessage());
                }
            }

            // Process overdue concerns
            foreach ($overdueConcerns as $concern) {
                try {
                    $this->handleOverdueConcern($concern);
                    $results['overdue']++;
                    $results['notifications_sent']++;
                } catch (\Exception $e) {
                    $results['errors'][] = "Failed to handle overdue concern {$concern->id}: " . $e->getMessage();
                    Log::error("Overdue handling failed for concern {$concern->id}: " . $e->getMessage());
                }
            }

            Log::info('Automated escalation check completed', $results);

        } catch (\Exception $e) {
            Log::error('Automated escalation check failed: ' . $e->getMessage());
            $results['errors'][] = 'System error: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Get concerns that need escalation
     */
    private function getConcernsNeedingEscalation(): \Illuminate\Support\Collection
    {
        $concerns = Concern::whereIn('status', ['pending', 'approved', 'in_progress'])
            ->whereNull('archived_at')
            ->whereNotNull('assigned_to')
            ->with(['assignedTo', 'student', 'department'])
            ->get();

        $needingEscalation = collect();

        foreach ($concerns as $concern) {
            $threshold = $this->escalationThresholds[$concern->priority] ?? 24;
            $escalationTime = $concern->created_at->addHours($threshold);

            if (now()->greaterThan($escalationTime) && !$concern->escalated_at) {
                $needingEscalation->push($concern);
            }
        }

        return $needingEscalation;
    }

    /**
     * Get overdue concerns
     */
    private function getOverdueConcerns(): \Illuminate\Support\Collection
    {
        $concerns = Concern::whereIn('status', ['pending', 'approved', 'in_progress'])
            ->whereNull('archived_at')
            ->whereNotNull('assigned_to')
            ->with(['assignedTo', 'student', 'department'])
            ->get();

        $overdue = collect();

        foreach ($concerns as $concern) {
            $threshold = $this->overdueThresholds[$concern->priority] ?? 48;
            $overdueTime = $concern->created_at->addHours($threshold);

            if (now()->greaterThan($overdueTime)) {
                $overdue->push($concern);
            }
        }

        return $overdue;
    }

    /**
     * Escalate a concern
     */
    private function escalateConcern(Concern $concern): void
    {
        Log::info("Escalating concern {$concern->id}", [
            'concern_id' => $concern->id,
            'priority' => $concern->priority,
            'assigned_to' => $concern->assigned_to,
            'created_at' => $concern->created_at
        ]);

        // Mark as escalated
        $concern->update([
            'escalated_at' => now(),
            'escalated_by' => 'system',
            'escalation_reason' => 'Automated escalation due to response time threshold'
        ]);

        // Notify department head
        $this->notifyDepartmentHead($concern);

        // Try to reassign to available staff
        $this->tryReassignment($concern);

        // Create escalation log entry
        $this->createEscalationLog($concern);
    }

    /**
     * Handle overdue concern
     */
    private function handleOverdueConcern(Concern $concern): void
    {
        Log::warning("Handling overdue concern {$concern->id}", [
            'concern_id' => $concern->id,
            'priority' => $concern->priority,
            'assigned_to' => $concern->assigned_to,
            'created_at' => $concern->created_at
        ]);

        // Mark as overdue if not already
        if (!$concern->overdue_at) {
            $concern->update([
                'overdue_at' => now(),
                'overdue_reason' => 'Automated overdue detection'
            ]);
        }

        // Escalate to department head
        $this->notifyDepartmentHead($concern, true);

        // Try cross-department assignment
        $this->tryCrossDepartmentAssignment($concern);

        // Create overdue log entry
        $this->createOverdueLog($concern);
    }

    /**
     * Notify department head about escalation/overdue
     */
    private function notifyDepartmentHead(Concern $concern, bool $isOverdue = false): void
    {
        $departmentHead = User::where('role', 'department_head')
            ->where('department_id', $concern->department_id)
            ->first();

        if (!$departmentHead) {
            Log::warning("No department head found for department {$concern->department_id}");
            return;
        }

        $message = $isOverdue 
            ? "URGENT: Concern #{$concern->reference_number} is overdue and needs immediate attention."
            : "Concern #{$concern->reference_number} has been escalated due to response time threshold.";

        // Create notification (you can integrate with your notification system)
        $this->createNotification($departmentHead, $concern, $message, $isOverdue ? 'overdue' : 'escalated');
    }

    /**
     * Try to reassign to available staff
     */
    private function tryReassignment(Concern $concern): void
    {
        $availableStaff = User::where('role', 'staff')
            ->where('department_id', $concern->department_id)
            ->where('is_active', true)
            ->where('id', '!=', $concern->assigned_to)
            ->withCount(['assignedConcerns' => function($query) {
                $query->whereNull('archived_at')
                      ->whereNotIn('status', ['resolved', 'student_confirmed', 'closed']);
            }])
            ->orderBy('assigned_concerns_count')
            ->first();

        if ($availableStaff && $availableStaff->assigned_concerns_count < 3) {
            $concern->update([
                'assigned_to' => $availableStaff->id,
                'reassigned_at' => now(),
                'reassigned_by' => 'system',
                'reassignment_reason' => 'Automated reassignment due to escalation'
            ]);

            Log::info("Reassigned concern {$concern->id} to staff {$availableStaff->id}");
        }
    }

    /**
     * Try cross-department assignment for overdue concerns
     */
    private function tryCrossDepartmentAssignment(Concern $concern): void
    {
        $crossDepartmentStaff = User::where('role', 'staff')
            ->where('can_handle_cross_department', true)
            ->where('is_active', true)
            ->where('id', '!=', $concern->assigned_to)
            ->withCount(['assignedConcerns' => function($query) {
                $query->whereNull('archived_at')
                      ->whereNotIn('status', ['resolved', 'student_confirmed', 'closed']);
            }])
            ->orderBy('assigned_concerns_count')
            ->first();

        if ($crossDepartmentStaff && $crossDepartmentStaff->assigned_concerns_count < 2) {
            $concern->update([
                'assigned_to' => $crossDepartmentStaff->id,
                'reassigned_at' => now(),
                'reassigned_by' => 'system',
                'reassignment_reason' => 'Cross-department assignment due to overdue status'
            ]);

            // Create cross-department assignment record
            DB::table('cross_department_assignments')->insert([
                'concern_id' => $concern->id,
                'staff_id' => $crossDepartmentStaff->id,
                'requesting_department_id' => $concern->department_id,
                'assignment_type' => 'overdue_escalation',
                'estimated_duration_hours' => 8,
                'status' => 'active',
                'assigned_at' => now(),
                'assigned_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info("Cross-department assignment for concern {$concern->id} to staff {$crossDepartmentStaff->id}");
        }
    }

    /**
     * Create escalation log entry
     */
    private function createEscalationLog(Concern $concern): void
    {
        DB::table('escalation_logs')->insert([
            'concern_id' => $concern->id,
            'escalation_type' => 'automated',
            'escalation_reason' => 'Response time threshold exceeded',
            'escalated_by' => 'system',
            'escalated_at' => now(),
            'previous_assignee' => $concern->assigned_to,
            'new_assignee' => $concern->assigned_to,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Create overdue log entry
     */
    private function createOverdueLog(Concern $concern): void
    {
        DB::table('overdue_logs')->insert([
            'concern_id' => $concern->id,
            'overdue_type' => 'automated',
            'overdue_reason' => 'Resolution time threshold exceeded',
            'detected_by' => 'system',
            'detected_at' => now(),
            'assigned_to' => $concern->assigned_to,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Create notification
     */
    private function createNotification(User $user, Concern $concern, string $message, string $type): void
    {
        // This would integrate with your notification system
        // For now, we'll just log it
        Log::info("Notification sent to {$user->name}", [
            'user_id' => $user->id,
            'concern_id' => $concern->id,
            'message' => $message,
            'type' => $type
        ]);
    }

    /**
     * Get escalation statistics
     */
    public function getEscalationStats(): array
    {
        $totalEscalated = Concern::whereNotNull('escalated_at')->count();
        $totalOverdue = Concern::whereNotNull('overdue_at')->count();
        $escalatedToday = Concern::whereNotNull('escalated_at')
            ->whereDate('escalated_at', today())
            ->count();
        $overdueToday = Concern::whereNotNull('overdue_at')
            ->whereDate('overdue_at', today())
            ->count();

        return [
            'total_escalated' => $totalEscalated,
            'total_overdue' => $totalOverdue,
            'escalated_today' => $escalatedToday,
            'overdue_today' => $overdueToday,
            'escalation_rate' => $totalEscalated > 0 ? ($escalatedToday / $totalEscalated) * 100 : 0,
            'overdue_rate' => $totalOverdue > 0 ? ($overdueToday / $totalOverdue) * 100 : 0,
        ];
    }

    /**
     * Manual escalation by admin/department head
     */
    public function manualEscalate(Concern $concern, User $escalatedBy, string $reason): bool
    {
        try {
            $concern->update([
                'escalated_at' => now(),
                'escalated_by' => $escalatedBy->id,
                'escalation_reason' => $reason
            ]);

            $this->createEscalationLog($concern);
            $this->notifyDepartmentHead($concern);

            Log::info("Manual escalation by {$escalatedBy->name} for concern {$concern->id}");

            return true;
        } catch (\Exception $e) {
            Log::error("Manual escalation failed: " . $e->getMessage());
            return false;
        }
    }
}
