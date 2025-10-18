<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\SmartAssignmentService;
use App\Models\Concern;
use App\Models\Department;
use Illuminate\Support\Facades\Log;

class SmartAssignmentController extends Controller
{
    protected SmartAssignmentService $smartAssignmentService;

    public function __construct(SmartAssignmentService $smartAssignmentService)
    {
        $this->smartAssignmentService = $smartAssignmentService;
    }

    /**
     * Get assignment analytics
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        try {
            $departmentId = $request->input('department_id');
            $user = auth()->user();

            // If user is department head, limit to their department
            if ($user->role === 'department_head' && !$departmentId) {
                $departmentId = $user->department_id;
            }

            $analytics = $this->smartAssignmentService->getAssignmentAnalytics($departmentId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            Log::error('Smart Assignment Analytics Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get assignment analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rebalance workload
     */
    public function rebalanceWorkload(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'department_id' => 'required|integer|exists:departments,id'
            ]);

            $user = auth()->user();
            $departmentId = $request->input('department_id');

            // Verify user has access to this department
            if ($user->role === 'department_head' && $user->department_id !== $departmentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to rebalance workload for this department'
                ], 403);
            }

            $rebalances = $this->smartAssignmentService->rebalanceWorkload($departmentId);

            return response()->json([
                'success' => true,
                'message' => 'Workload rebalancing suggestions generated',
                'data' => [
                    'suggestions' => $rebalances,
                    'count' => count($rebalances)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Workload Rebalancing Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to rebalance workload',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Suggest best assignee for a concern
     */
    public function suggestAssignee(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'concern_id' => 'required|integer|exists:concerns,id'
            ]);

            $concern = Concern::findOrFail($request->input('concern_id'));
            $user = auth()->user();

            // Verify user has access to this concern
            if ($user->role === 'department_head' && $user->department_id !== $concern->department_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to suggest assignee for this concern'
                ], 403);
            }

            $suggestedAssignee = $this->smartAssignmentService->findBestAssignee($concern);

            if (!$suggestedAssignee) {
                return response()->json([
                    'success' => false,
                    'message' => 'No suitable assignee found'
                ], 404);
            }

            // Get additional context
            $workload = $suggestedAssignee->assignedConcerns()->whereIn('status', ['pending', 'in_progress'])->count();
            $avgResponseTime = $this->getAverageResponseTime($suggestedAssignee);

            return response()->json([
                'success' => true,
                'data' => [
                    'suggested_assignee' => [
                        'id' => $suggestedAssignee->id,
                        'name' => $suggestedAssignee->name,
                        'email' => $suggestedAssignee->email,
                        'employee_id' => $suggestedAssignee->employee_id,
                        'department' => $suggestedAssignee->department,
                        'current_workload' => $workload,
                        'average_response_time_hours' => $avgResponseTime,
                        'skills' => $this->getStaffSkills($suggestedAssignee)
                    ],
                    'concern' => [
                        'id' => $concern->id,
                        'subject' => $concern->subject,
                        'priority' => $concern->priority,
                        'type' => $concern->type
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Suggest Assignee Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to suggest assignee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get average response time for staff
     */
    private function getAverageResponseTime($staff): float
    {
        $concerns = $staff->assignedConcerns()
            ->where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->get();

        if ($concerns->isEmpty()) {
            return 12; // Default 12 hours for new staff
        }

        $totalHours = 0;
        $count = 0;

        foreach ($concerns as $concern) {
            if ($concern->assigned_at && $concern->resolved_at) {
                $hours = $concern->assigned_at->diffInHours($concern->resolved_at);
                $totalHours += $hours;
                $count++;
            }
        }

        return $count > 0 ? round($totalHours / $count, 2) : 12;
    }

    /**
     * Get staff skills (placeholder implementation)
     */
    private function getStaffSkills($staff): array
    {
        $defaultSkills = [
            'BSIT' => ['Technical Support', 'System Administration', 'Programming', 'Database', 'Networking'],
            'BSBA' => ['Business Analysis', 'Project Management', 'Customer Service', 'Marketing', 'Finance'],
            'BSED' => ['Academic Support', 'Student Counseling', 'Educational Planning', 'Curriculum', 'Assessment'],
            'BSN' => ['Health Support', 'Emergency Response', 'Student Wellness', 'Medical', 'Counseling'],
            'BSA' => ['Financial Analysis', 'Administrative Support', 'Documentation', 'Accounting', 'Audit']
        ];

        $departmentName = $staff->department->name ?? '';
        foreach ($defaultSkills as $dept => $skills) {
            if (strpos($departmentName, $dept) !== false) {
                return $skills;
            }
        }

        return ['General Support', 'Administrative', 'Customer Service'];
    }
}
