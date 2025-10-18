<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CrossDepartmentIntelligenceService
{
    private $overloadThreshold = 0.8; // 80% capacity
    private $maxWorkloadPerStaff = 5;
    private $skillMatchingWeight = 0.4;
    private $workloadBalanceWeight = 0.3;
    private $responseTimeWeight = 0.3;

    /**
     * Analyze department workload and suggest cross-department assignments
     */
    public function analyzeWorkloadDistribution(): array
    {
        $departments = Department::withCount([
            'concerns as total_concerns',
            'concerns as active_concerns' => function($query) {
                $query->whereIn('status', ['pending', 'approved', 'in_progress'])
                      ->whereNull('archived_at');
            }
        ])->get();

        $staffCounts = User::where('role', 'staff')
            ->where('is_active', true)
            ->selectRaw('department_id, COUNT(*) as staff_count')
            ->groupBy('department_id')
            ->get()
            ->keyBy('department_id');

        $analysis = $departments->map(function($dept) use ($staffCounts) {
            $staffCount = $staffCounts->get($dept->id)?->staff_count ?? 0;
            $capacity = $staffCount * $this->maxWorkloadPerStaff;
            $utilization = $capacity > 0 ? ($dept->active_concerns / $capacity) : 0;
            $isOverloaded = $utilization >= $this->overloadThreshold;

            return [
                'department_id' => $dept->id,
                'name' => $dept->name,
                'staff_count' => $staffCount,
                'active_concerns' => $dept->active_concerns,
                'total_concerns' => $dept->total_concerns,
                'capacity' => $capacity,
                'utilization_rate' => round($utilization * 100, 2),
                'is_overloaded' => $isOverloaded,
                'available_capacity' => max(0, $capacity - $dept->active_concerns),
            ];
        });

        return [
            'departments' => $analysis,
            'overloaded_departments' => $analysis->where('is_overloaded', true)->values(),
            'available_departments' => $analysis->where('is_overloaded', false)->values(),
            'system_utilization' => $this->calculateSystemUtilization($analysis),
            'recommendations' => $this->generateRecommendations($analysis),
        ];
    }

    /**
     * Find optimal cross-department assignments for overloaded departments
     */
    public function findOptimalCrossDepartmentAssignments(int $overloadedDepartmentId): array
    {
        $overloadedDept = Department::find($overloadedDepartmentId);
        if (!$overloadedDept) {
            return ['error' => 'Department not found'];
        }

        // Get pending concerns from overloaded department
        $pendingConcerns = Concern::where('department_id', $overloadedDepartmentId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('archived_at')
            ->whereNull('assigned_to')
            ->with(['student'])
            ->get();

        if ($pendingConcerns->isEmpty()) {
            return ['message' => 'No pending concerns to reassign'];
        }

        // Get available cross-department staff
        $availableStaff = User::where('role', 'staff')
            ->where('can_handle_cross_department', true)
            ->where('is_active', true)
            ->where('department_id', '!=', $overloadedDepartmentId)
            ->with(['assignedConcerns' => function($query) {
                $query->whereNull('archived_at')
                      ->whereNotIn('status', ['resolved', 'student_confirmed', 'closed']);
            }])
            ->get();

        $assignments = [];
        foreach ($pendingConcerns as $concern) {
            $bestMatch = $this->findBestCrossDepartmentMatch($concern, $availableStaff);
            if ($bestMatch) {
                $assignments[] = [
                    'concern_id' => $concern->id,
                    'concern_subject' => $concern->subject,
                    'concern_priority' => $concern->priority,
                    'concern_type' => $concern->type,
                    'recommended_staff' => [
                        'id' => $bestMatch['staff']->id,
                        'name' => $bestMatch['staff']->name,
                        'department' => $bestMatch['staff']->department->name ?? 'Unknown',
                        'current_workload' => $bestMatch['staff']->assignedConcerns->count(),
                    ],
                    'match_score' => $bestMatch['score'],
                    'match_reasons' => $bestMatch['reasons'],
                    'estimated_impact' => $this->calculateImpact($concern, $bestMatch['staff']),
                ];
            }
        }

        return [
            'overloaded_department' => [
                'id' => $overloadedDept->id,
                'name' => $overloadedDept->name,
            ],
            'pending_concerns' => $pendingConcerns->count(),
            'available_cross_department_staff' => $availableStaff->count(),
            'recommended_assignments' => $assignments,
            'total_impact' => $this->calculateTotalImpact($assignments),
        ];
    }

    /**
     * Execute cross-department assignments
     */
    public function executeCrossDepartmentAssignments(array $assignments): array
    {
        $results = [
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'assignments' => [],
        ];

        foreach ($assignments as $assignment) {
            try {
                DB::beginTransaction();

                $concern = Concern::find($assignment['concern_id']);
                $staff = User::find($assignment['staff_id']);

                if (!$concern || !$staff) {
                    throw new \Exception("Concern or staff not found");
                }

                // Update concern assignment
                $concern->update([
                    'assigned_to' => $staff->id,
                    'reassigned_at' => now(),
                    'reassigned_by' => 'system',
                    'reassignment_reason' => 'Cross-department assignment due to overload',
                ]);

                // Create cross-department assignment record
                DB::table('cross_department_assignments')->insert([
                    'concern_id' => $concern->id,
                    'staff_id' => $staff->id,
                    'requesting_department_id' => $concern->department_id,
                    'assignment_type' => 'overload_balancing',
                    'estimated_duration_hours' => $this->estimateResolutionTime($concern),
                    'status' => 'active',
                    'assigned_at' => now(),
                    'assigned_by' => 'system',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Log the assignment
                Log::info("Cross-department assignment executed", [
                    'concern_id' => $concern->id,
                    'staff_id' => $staff->id,
                    'from_department' => $concern->department_id,
                    'to_department' => $staff->department_id,
                    'reason' => 'overload_balancing'
                ]);

                $results['successful']++;
                $results['assignments'][] = [
                    'concern_id' => $concern->id,
                    'staff_id' => $staff->id,
                    'assigned_at' => now(),
                ];

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                $results['failed']++;
                $results['errors'][] = [
                    'assignment' => $assignment,
                    'error' => $e->getMessage(),
                ];
                Log::error("Cross-department assignment failed", [
                    'assignment' => $assignment,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Get cross-department assignment statistics
     */
    public function getCrossDepartmentStats(): array
    {
        $totalAssignments = DB::table('cross_department_assignments')->count();
        $activeAssignments = DB::table('cross_department_assignments')
            ->where('status', 'active')
            ->count();
        $completedAssignments = DB::table('cross_department_assignments')
            ->where('status', 'completed')
            ->count();

        $avgCompletionTime = DB::table('cross_department_assignments')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, assigned_at, completed_at)) as avg_hours')
            ->value('avg_hours') ?? 0;

        $assignmentsByType = DB::table('cross_department_assignments')
            ->selectRaw('assignment_type, COUNT(*) as count')
            ->groupBy('assignment_type')
            ->get()
            ->pluck('count', 'assignment_type')
            ->toArray();

        $assignmentsByDepartment = DB::table('cross_department_assignments')
            ->join('departments', 'cross_department_assignments.requesting_department_id', '=', 'departments.id')
            ->selectRaw('departments.name, COUNT(*) as count')
            ->groupBy('departments.id', 'departments.name')
            ->get()
            ->pluck('count', 'name')
            ->toArray();

        return [
            'total_assignments' => $totalAssignments,
            'active_assignments' => $activeAssignments,
            'completed_assignments' => $completedAssignments,
            'completion_rate' => $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100, 2) : 0,
            'avg_completion_time_hours' => round($avgCompletionTime, 2),
            'assignments_by_type' => $assignmentsByType,
            'assignments_by_department' => $assignmentsByDepartment,
        ];
    }

    /**
     * Monitor and auto-balance workload
     */
    public function autoBalanceWorkload(): array
    {
        $analysis = $this->analyzeWorkloadDistribution();
        $overloadedDepts = $analysis['overloaded_departments'];
        $availableDepts = $analysis['available_departments'];

        if ($overloadedDepts->isEmpty() || $availableDepts->isEmpty()) {
            return [
                'message' => 'No workload balancing needed',
                'overloaded_departments' => $overloadedDepts->count(),
                'available_departments' => $availableDepts->count(),
            ];
        }

        $totalReassigned = 0;
        $results = [];

        foreach ($overloadedDepts as $overloadedDept) {
            $assignments = $this->findOptimalCrossDepartmentAssignments($overloadedDept['department_id']);
            
            if (!empty($assignments['recommended_assignments'])) {
                // Execute top 3 assignments to avoid overloading
                $topAssignments = array_slice($assignments['recommended_assignments'], 0, 3);
                $executionResults = $this->executeCrossDepartmentAssignments(
                    array_map(function($assignment) {
                        return [
                            'concern_id' => $assignment['concern_id'],
                            'staff_id' => $assignment['recommended_staff']['id'],
                        ];
                    }, $topAssignments)
                );

                $totalReassigned += $executionResults['successful'];
                $results[] = [
                    'department' => $overloadedDept['name'],
                    'reassigned' => $executionResults['successful'],
                    'failed' => $executionResults['failed'],
                ];
            }
        }

        return [
            'total_reassigned' => $totalReassigned,
            'department_results' => $results,
            'system_utilization_before' => $analysis['system_utilization'],
            'system_utilization_after' => $this->calculateSystemUtilization($this->analyzeWorkloadDistribution()['departments']),
        ];
    }

    /**
     * Helper methods
     */
    private function findBestCrossDepartmentMatch(Concern $concern, $availableStaff)
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($availableStaff as $staff) {
            $score = $this->calculateMatchScore($concern, $staff);
            if ($score > $bestScore && $score > 0.5) { // Minimum threshold
                $bestMatch = [
                    'staff' => $staff,
                    'score' => $score,
                    'reasons' => $this->getMatchReasons($concern, $staff),
                ];
                $bestScore = $score;
            }
        }

        return $bestMatch;
    }

    private function calculateMatchScore(Concern $concern, User $staff): float
    {
        $workloadScore = $this->calculateWorkloadScore($staff);
        $skillScore = $this->calculateSkillScore($concern, $staff);
        $responseTimeScore = $this->calculateResponseTimeScore($staff);

        return ($workloadScore * $this->workloadBalanceWeight) +
               ($skillScore * $this->skillMatchingWeight) +
               ($responseTimeScore * $this->responseTimeWeight);
    }

    private function calculateWorkloadScore(User $staff): float
    {
        $currentWorkload = $staff->assignedConcerns->count();
        if ($currentWorkload >= $this->maxWorkloadPerStaff) return 0;
        return 1.0 - ($currentWorkload / $this->maxWorkloadPerStaff);
    }

    private function calculateSkillScore(Concern $concern, User $staff): float
    {
        // Simple skill matching based on concern type and staff title
        $skillMappings = [
            'academic' => ['academic', 'advisor', 'coordinator'],
            'technical' => ['technical', 'it', 'support', 'admin'],
            'administrative' => ['admin', 'assistant', 'coordinator'],
            'health' => ['health', 'medical', 'nurse', 'counselor'],
            'safety' => ['security', 'safety', 'officer'],
        ];

        $concernType = strtolower($concern->type);
        $staffTitle = strtolower($staff->title ?? '');

        if (isset($skillMappings[$concernType])) {
            foreach ($skillMappings[$concernType] as $skill) {
                if (str_contains($staffTitle, $skill)) {
                    return 1.0;
                }
            }
        }

        return 0.5; // Default score for general staff
    }

    private function calculateResponseTimeScore(User $staff): float
    {
        $avgResponseTime = Concern::where('assigned_to', $staff->id)
            ->whereNotNull('resolved_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->value('avg_hours') ?? 24;

        if ($avgResponseTime <= 4) return 1.0;
        if ($avgResponseTime <= 12) return 0.8;
        if ($avgResponseTime <= 24) return 0.6;
        return 0.4;
    }

    private function getMatchReasons(Concern $concern, User $staff): array
    {
        $reasons = [];
        
        if ($staff->assignedConcerns->count() < 3) {
            $reasons[] = 'Low current workload';
        }
        
        if ($this->calculateSkillScore($concern, $staff) > 0.7) {
            $reasons[] = 'Good skill match';
        }
        
        if ($this->calculateResponseTimeScore($staff) > 0.7) {
            $reasons[] = 'Fast response time';
        }

        return $reasons;
    }

    private function calculateImpact(Concern $concern, User $staff): array
    {
        return [
            'workload_reduction' => 1,
            'estimated_resolution_time' => $this->estimateResolutionTime($concern),
            'skill_match_quality' => $this->calculateSkillScore($concern, $staff),
            'priority_impact' => $this->getPriorityImpact($concern->priority),
        ];
    }

    private function calculateTotalImpact(array $assignments): array
    {
        return [
            'total_concerns_reassigned' => count($assignments),
            'total_workload_reduction' => count($assignments),
            'avg_estimated_resolution_time' => array_sum(array_column($assignments, 'estimated_impact')) / count($assignments),
            'high_priority_concerns' => count(array_filter($assignments, fn($a) => in_array($a['concern_priority'], ['high', 'urgent']))),
        ];
    }

    private function estimateResolutionTime(Concern $concern): int
    {
        $baseTime = [
            'urgent' => 2,
            'high' => 4,
            'medium' => 8,
            'low' => 24,
        ];

        return $baseTime[$concern->priority] ?? 8;
    }

    private function getPriorityImpact(string $priority): string
    {
        return match($priority) {
            'urgent' => 'Critical - immediate attention required',
            'high' => 'Important - should be addressed quickly',
            'medium' => 'Moderate - standard processing time',
            'low' => 'Low - can be processed when convenient',
        };
    }

    private function calculateSystemUtilization($departments): float
    {
        $totalCapacity = $departments->sum('capacity');
        $totalActive = $departments->sum('active_concerns');
        
        return $totalCapacity > 0 ? round(($totalActive / $totalCapacity) * 100, 2) : 0;
    }

    private function generateRecommendations($analysis): array
    {
        $recommendations = [];
        
        $overloadedCount = $analysis->where('is_overloaded', true)->count();
        if ($overloadedCount > 0) {
            $recommendations[] = "{$overloadedCount} departments are overloaded and may benefit from cross-department assignments";
        }
        
        $underutilizedCount = $analysis->where('utilization_rate', '<', 50)->count();
        if ($underutilizedCount > 0) {
            $recommendations[] = "{$underutilizedCount} departments have available capacity for cross-department support";
        }
        
        $systemUtilization = $this->calculateSystemUtilization($analysis);
        if ($systemUtilization > 80) {
            $recommendations[] = "System-wide utilization is high ({$systemUtilization}%) - consider hiring additional staff";
        }

        return $recommendations;
    }
}
