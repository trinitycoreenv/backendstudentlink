<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Models\Concern;
use App\Models\Department;
use App\Models\CrossDepartmentAssignment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PerformanceAnalyticsController extends Controller
{
    /**
     * Get comprehensive performance analytics
     */
    public function getPerformanceAnalytics(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $departmentId = $request->input('department_id');
            $dateRange = $request->input('date_range', '30'); // days

            // If user is department head, limit to their department
            if ($user->role === 'department_head' && !$departmentId) {
                $departmentId = $user->department_id;
            }

            $analytics = [
                'overview' => $this->getOverviewStats($departmentId, $dateRange),
                'staff_performance' => $this->getStaffPerformance($departmentId, $dateRange),
                'department_performance' => $this->getDepartmentPerformance($departmentId, $dateRange),
                'concern_analytics' => $this->getConcernAnalytics($departmentId, $dateRange),
                'escalation_analytics' => $this->getEscalationAnalytics($departmentId, $dateRange),
                'cross_department_analytics' => $this->getCrossDepartmentAnalytics($departmentId, $dateRange),
                'trends' => $this->getTrends($departmentId, $dateRange)
            ];

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            Log::error('Performance Analytics Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get performance analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get overview statistics
     */
    private function getOverviewStats(?int $departmentId, string $dateRange): array
    {
        $query = Concern::query();
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $dateFrom = now()->subDays((int)$dateRange);
        $concerns = $query->where('created_at', '>=', $dateFrom)->get();

        return [
            'total_concerns' => $concerns->count(),
            'resolved_concerns' => $concerns->where('status', 'resolved')->count(),
            'pending_concerns' => $concerns->where('status', 'pending')->count(),
            'in_progress_concerns' => $concerns->where('status', 'in_progress')->count(),
            'resolution_rate' => $concerns->count() > 0 ? round(($concerns->where('status', 'resolved')->count() / $concerns->count()) * 100, 2) : 0,
            'average_resolution_time_hours' => $this->getAverageResolutionTime($concerns),
            'escalation_rate' => $concerns->count() > 0 ? round(($concerns->whereNotNull('escalated_at')->count() / $concerns->count()) * 100, 2) : 0
        ];
    }

    /**
     * Get staff performance metrics
     */
    private function getStaffPerformance(?int $departmentId, string $dateRange): array
    {
        $query = User::where('role', 'staff')->where('is_active', true);
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $staff = $query->with(['assignedConcerns' => function($q) use ($dateRange) {
            $q->where('created_at', '>=', now()->subDays((int)$dateRange));
        }])->get();

        return $staff->map(function($member) {
            $concerns = $member->assignedConcerns;
            $resolvedConcerns = $concerns->where('status', 'resolved');
            
            return [
                'staff_id' => $member->id,
                'name' => $member->name,
                'employee_id' => $member->employee_id,
                'department' => $member->department,
                'total_assigned' => $concerns->count(),
                'resolved' => $resolvedConcerns->count(),
                'pending' => $concerns->where('status', 'pending')->count(),
                'in_progress' => $concerns->where('status', 'in_progress')->count(),
                'resolution_rate' => $concerns->count() > 0 ? round(($resolvedConcerns->count() / $concerns->count()) * 100, 2) : 0,
                'average_resolution_time_hours' => $this->getAverageResolutionTime($resolvedConcerns),
                'escalated_concerns' => $concerns->whereNotNull('escalated_at')->count(),
                'escalation_rate' => $concerns->count() > 0 ? round(($concerns->whereNotNull('escalated_at')->count() / $concerns->count()) * 100, 2) : 0,
                'current_workload' => $concerns->whereIn('status', ['pending', 'in_progress'])->count(),
                'last_active' => $member->last_login_at
            ];
        })->sortByDesc('resolution_rate')->values()->toArray();
    }

    /**
     * Get department performance metrics
     */
    private function getDepartmentPerformance(?int $departmentId, string $dateRange): array
    {
        if ($departmentId) {
            $departments = Department::where('id', $departmentId)->get();
        } else {
            $departments = Department::all();
        }

        return $departments->map(function($dept) use ($dateRange) {
            $concerns = Concern::where('department_id', $dept->id)
                ->where('created_at', '>=', now()->subDays((int)$dateRange))
                ->get();

            $staff = User::where('department_id', $dept->id)
                ->where('role', 'staff')
                ->where('is_active', true)
                ->count();

            return [
                'department_id' => $dept->id,
                'department_name' => $dept->name,
                'total_concerns' => $concerns->count(),
                'resolved_concerns' => $concerns->where('status', 'resolved')->count(),
                'resolution_rate' => $concerns->count() > 0 ? round(($concerns->where('status', 'resolved')->count() / $concerns->count()) * 100, 2) : 0,
                'average_resolution_time_hours' => $this->getAverageResolutionTime($concerns->where('status', 'resolved')),
                'escalation_rate' => $concerns->count() > 0 ? round(($concerns->whereNotNull('escalated_at')->count() / $concerns->count()) * 100, 2) : 0,
                'staff_count' => $staff,
                'concerns_per_staff' => $staff > 0 ? round($concerns->count() / $staff, 2) : 0
            ];
        })->sortByDesc('resolution_rate')->values()->toArray();
    }

    /**
     * Get concern analytics
     */
    private function getConcernAnalytics(?int $departmentId, string $dateRange): array
    {
        $query = Concern::query();
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $concerns = $query->where('created_at', '>=', now()->subDays((int)$dateRange))->get();

        return [
            'by_priority' => [
                'urgent' => $concerns->where('priority', 'urgent')->count(),
                'high' => $concerns->where('priority', 'high')->count(),
                'normal' => $concerns->where('priority', 'normal')->count()
            ],
            'by_type' => $concerns->groupBy('type')->map->count(),
            'by_status' => [
                'pending' => $concerns->where('status', 'pending')->count(),
                'in_progress' => $concerns->where('status', 'in_progress')->count(),
                'resolved' => $concerns->where('status', 'resolved')->count()
            ],
            'resolution_time_distribution' => $this->getResolutionTimeDistribution($concerns->where('status', 'resolved')),
            'ai_classification_accuracy' => $this->getAIClassificationAccuracy($concerns)
        ];
    }

    /**
     * Get escalation analytics
     */
    private function getEscalationAnalytics(?int $departmentId, string $dateRange): array
    {
        $query = Concern::query();
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $concerns = $query->where('created_at', '>=', now()->subDays((int)$dateRange))->get();
        $escalatedConcerns = $concerns->whereNotNull('escalated_at');

        return [
            'total_escalated' => $escalatedConcerns->count(),
            'escalation_rate' => $concerns->count() > 0 ? round(($escalatedConcerns->count() / $concerns->count()) * 100, 2) : 0,
            'by_level' => [
                'staff' => $escalatedConcerns->where('escalation_level', 'staff')->count(),
                'department_head' => $escalatedConcerns->where('escalation_level', 'department_head')->count(),
                'admin' => $escalatedConcerns->where('escalation_level', 'admin')->count()
            ],
            'average_escalation_time_hours' => $this->getAverageEscalationTime($escalatedConcerns),
            'escalation_reasons' => $escalatedConcerns->groupBy('escalation_reason')->map->count()
        ];
    }

    /**
     * Get cross-department analytics
     */
    private function getCrossDepartmentAnalytics(?int $departmentId, string $dateRange): array
    {
        $query = CrossDepartmentAssignment::query();
        if ($departmentId) {
            $query->where('requesting_department_id', $departmentId);
        }

        $assignments = $query->where('assigned_at', '>=', now()->subDays((int)$dateRange))->get();

        return [
            'total_assignments' => $assignments->count(),
            'completed_assignments' => $assignments->where('status', 'completed')->count(),
            'active_assignments' => $assignments->where('status', 'active')->count(),
            'completion_rate' => $assignments->count() > 0 ? round(($assignments->where('status', 'completed')->count() / $assignments->count()) * 100, 2) : 0,
            'average_duration_hours' => $assignments->where('status', 'completed')->avg('actual_duration_hours') ?? 0,
            'by_type' => $assignments->groupBy('assignment_type')->map->count()
        ];
    }

    /**
     * Get trends over time
     */
    private function getTrends(?int $departmentId, string $dateRange): array
    {
        $query = Concern::query();
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $days = min((int)$dateRange, 30); // Limit to 30 days for trends
        $trends = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayConcerns = $query->whereDate('created_at', $date)->get();
            $dayResolved = $query->whereDate('resolved_at', $date)->get();

            $trends[] = [
                'date' => $date,
                'concerns_created' => $dayConcerns->count(),
                'concerns_resolved' => $dayResolved->count(),
                'escalations' => $query->whereDate('escalated_at', $date)->count()
            ];
        }

        return $trends;
    }

    /**
     * Get average resolution time
     */
    private function getAverageResolutionTime($concerns): float
    {
        if ($concerns->isEmpty()) {
            return 0;
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

        return $count > 0 ? round($totalHours / $count, 2) : 0;
    }

    /**
     * Get average escalation time
     */
    private function getAverageEscalationTime($concerns): float
    {
        if ($concerns->isEmpty()) {
            return 0;
        }

        $totalHours = 0;
        $count = 0;

        foreach ($concerns as $concern) {
            if ($concern->assigned_at && $concern->escalated_at) {
                $hours = $concern->assigned_at->diffInHours($concern->escalated_at);
                $totalHours += $hours;
                $count++;
            }
        }

        return $count > 0 ? round($totalHours / $count, 2) : 0;
    }

    /**
     * Get resolution time distribution
     */
    private function getResolutionTimeDistribution($concerns): array
    {
        $distribution = [
            'under_1_hour' => 0,
            '1_to_6_hours' => 0,
            '6_to_24_hours' => 0,
            '1_to_3_days' => 0,
            'over_3_days' => 0
        ];

        foreach ($concerns as $concern) {
            if ($concern->assigned_at && $concern->resolved_at) {
                $hours = $concern->assigned_at->diffInHours($concern->resolved_at);
                
                if ($hours < 1) {
                    $distribution['under_1_hour']++;
                } elseif ($hours <= 6) {
                    $distribution['1_to_6_hours']++;
                } elseif ($hours <= 24) {
                    $distribution['6_to_24_hours']++;
                } elseif ($hours <= 72) {
                    $distribution['1_to_3_days']++;
                } else {
                    $distribution['over_3_days']++;
                }
            }
        }

        return $distribution;
    }

    /**
     * Get AI classification accuracy (placeholder)
     */
    private function getAIClassificationAccuracy($concerns): array
    {
        $aiClassified = $concerns->whereNotNull('ai_classification');
        
        return [
            'total_classified' => $aiClassified->count(),
            'classification_rate' => $concerns->count() > 0 ? round(($aiClassified->count() / $concerns->count()) * 100, 2) : 0,
            'accuracy_by_category' => [
                'academic' => 85,
                'financial' => 78,
                'administrative' => 82,
                'technical' => 90,
                'personal' => 75
            ]
        ];
    }

    /**
     * Get staff workload distribution
     */
    public function getStaffWorkloadDistribution(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $departmentId = $request->input('department_id');

            if ($user->role === 'department_head' && !$departmentId) {
                $departmentId = $user->department_id;
            }

            $query = User::where('role', 'staff')->where('is_active', true);
            if ($departmentId) {
                $query->where('department_id', $departmentId);
            }

            $staff = $query->with(['assignedConcerns' => function($q) {
                $q->whereIn('status', ['pending', 'in_progress']);
            }])->get();

            $distribution = [
                'light_workload' => $staff->filter(fn($s) => $s->assignedConcerns->count() <= 3)->count(),
                'moderate_workload' => $staff->filter(fn($s) => $s->assignedConcerns->count() > 3 && $s->assignedConcerns->count() <= 7)->count(),
                'heavy_workload' => $staff->filter(fn($s) => $s->assignedConcerns->count() > 7 && $s->assignedConcerns->count() <= 10)->count(),
                'overloaded' => $staff->filter(fn($s) => $s->assignedConcerns->count() > 10)->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $distribution
            ]);

        } catch (\Exception $e) {
            Log::error('Staff Workload Distribution Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get staff workload distribution',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export analytics data
     */
    public function exportAnalytics(Request $request): JsonResponse
    {
        try {
            $format = $request->input('format', 'json'); // json, csv
            $analytics = $this->getPerformanceAnalytics($request);

            if ($format === 'csv') {
                // This would generate CSV data
                return response()->json([
                    'success' => true,
                    'message' => 'CSV export not implemented yet',
                    'data' => $analytics->getData()
                ]);
            }

            return $analytics;

        } catch (\Exception $e) {
            Log::error('Export Analytics Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to export analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
