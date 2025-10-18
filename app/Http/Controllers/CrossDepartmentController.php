<?php

namespace App\Http\Controllers;

use App\Services\CrossDepartmentIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CrossDepartmentController extends Controller
{
    protected CrossDepartmentIntelligenceService $crossDepartmentService;

    public function __construct(CrossDepartmentIntelligenceService $crossDepartmentService)
    {
        $this->crossDepartmentService = $crossDepartmentService;
    }

    /**
     * Analyze workload distribution across departments
     */
    public function analyzeWorkloadDistribution(): JsonResponse
    {
        try {
            $analysis = $this->crossDepartmentService->analyzeWorkloadDistribution();

            return response()->json([
                'success' => true,
                'data' => $analysis,
            ]);

        } catch (\Exception $e) {
            Log::error('Workload Analysis Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to analyze workload distribution',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Find optimal cross-department assignments for a specific department
     */
    public function findOptimalAssignments(Request $request, int $departmentId): JsonResponse
    {
        try {
            $assignments = $this->crossDepartmentService->findOptimalCrossDepartmentAssignments($departmentId);

            return response()->json([
                'success' => true,
                'data' => $assignments,
            ]);

        } catch (\Exception $e) {
            Log::error('Cross-Department Assignment Analysis Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to find optimal cross-department assignments',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Execute cross-department assignments
     */
    public function executeAssignments(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'assignments' => 'required|array',
                'assignments.*.concern_id' => 'required|integer|exists:concerns,id',
                'assignments.*.staff_id' => 'required|integer|exists:users,id',
            ]);

            $results = $this->crossDepartmentService->executeCrossDepartmentAssignments($request->assignments);

            return response()->json([
                'success' => true,
                'message' => 'Cross-department assignments processed',
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Cross-Department Assignment Execution Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to execute cross-department assignments',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get cross-department assignment statistics
     */
    public function getStats(): JsonResponse
    {
        try {
            $stats = $this->crossDepartmentService->getCrossDepartmentStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Cross-Department Stats Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get cross-department statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Auto-balance workload across departments
     */
    public function autoBalanceWorkload(): JsonResponse
    {
        try {
            $results = $this->crossDepartmentService->autoBalanceWorkload();

            return response()->json([
                'success' => true,
                'message' => 'Workload auto-balancing completed',
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-Balance Workload Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to auto-balance workload',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get cross-department assignments for a specific staff member
     */
    public function getStaffCrossDepartmentAssignments(Request $request, int $staffId): JsonResponse
    {
        try {
            $assignments = \DB::table('cross_department_assignments')
                ->join('concerns', 'cross_department_assignments.concern_id', '=', 'concerns.id')
                ->join('departments', 'cross_department_assignments.requesting_department_id', '=', 'departments.id')
                ->join('users', 'concerns.student_id', '=', 'users.id')
                ->where('cross_department_assignments.staff_id', $staffId)
                ->select([
                    'cross_department_assignments.*',
                    'concerns.subject',
                    'concerns.description',
                    'concerns.priority',
                    'concerns.status',
                    'departments.name as requesting_department',
                    'users.name as student_name',
                    'users.email as student_email',
                ])
                ->orderBy('cross_department_assignments.assigned_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $assignments,
            ]);

        } catch (\Exception $e) {
            Log::error('Staff Cross-Department Assignments Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get staff cross-department assignments',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Complete a cross-department assignment
     */
    public function completeAssignment(Request $request, int $assignmentId): JsonResponse
    {
        try {
            $request->validate([
                'completion_notes' => 'nullable|string|max:1000',
                'actual_duration_hours' => 'nullable|numeric|min:0',
            ]);

            $assignment = \DB::table('cross_department_assignments')
                ->where('id', $assignmentId)
                ->first();

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Assignment not found',
                ], 404);
            }

            \DB::table('cross_department_assignments')
                ->where('id', $assignmentId)
                ->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'notes' => $request->input('completion_notes'),
                    'actual_duration_hours' => $request->input('actual_duration_hours'),
                    'updated_at' => now(),
                ]);

            Log::info("Cross-department assignment completed", [
                'assignment_id' => $assignmentId,
                'concern_id' => $assignment->concern_id,
                'staff_id' => $assignment->staff_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cross-department assignment completed successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Complete Cross-Department Assignment Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete cross-department assignment',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Activate emergency cross-department mode (for N8N automation)
     */
    public function activateEmergency(Request $request): JsonResponse
    {
        try {
            $concernId = $request->input('concern_id');
            $reason = $request->input('reason', 'Emergency escalation');
            $priority = $request->input('priority', 'urgent');

            if (!$concernId) {
                return response()->json([
                    'success' => false,
                    'message' => 'concern_id is required'
                ], 400);
            }

            $concern = \App\Models\Concern::find($concernId);
            if (!$concern) {
                return response()->json([
                    'success' => false,
                    'message' => 'Concern not found'
                ], 404);
            }

            // Find available cross-department staff
            $availableStaff = \App\Models\User::where('role', 'staff')
                ->where('can_handle_cross_department', true)
                ->where('is_active', true)
                ->where('department_id', '!=', $concern->department_id)
                ->get();

            if ($availableStaff->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No cross-department staff available for emergency'
                ], 404);
            }

            // Select staff with lowest workload
            $selectedStaff = $availableStaff->sortBy(function ($staff) {
                return $staff->concerns()->whereNull('archived_at')->count();
            })->first();

            // Create emergency assignment
            $assignmentId = \DB::table('cross_department_assignments')->insertGetId([
                'concern_id' => $concernId,
                'staff_id' => $selectedStaff->id,
                'requesting_department_id' => $concern->department_id,
                'assignment_type' => 'emergency',
                'estimated_duration_hours' => 2, // Emergency assignments are typically 2 hours
                'status' => 'active',
                'assigned_at' => now(),
                'assigned_by' => $selectedStaff->id, // Use the assigned staff as the assigner for system assignments
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update concern
            $concern->update([
                'assigned_to' => $selectedStaff->id,
                'status' => 'in_progress',
                'priority' => $priority,
            ]);

            Log::info('Emergency cross-department mode activated', [
                'concern_id' => $concernId,
                'staff_id' => $selectedStaff->id,
                'assignment_id' => $assignmentId,
                'reason' => $reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Emergency cross-department mode activated',
                'data' => [
                    'concern_id' => $concernId,
                    'assigned_staff_id' => $selectedStaff->id,
                    'assigned_staff_name' => $selectedStaff->name,
                    'assignment_id' => $assignmentId,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Activate Emergency Cross-Department Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate emergency cross-department mode',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}