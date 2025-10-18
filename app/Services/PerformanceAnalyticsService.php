<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PerformanceAnalyticsService
{
    /**
     * Get comprehensive dashboard analytics
     */
    public function getDashboardAnalytics(): array
    {
        return [
            'overview' => $this->getOverviewMetrics(),
            'staff_performance' => $this->getStaffPerformanceMetrics(),
            'department_performance' => $this->getDepartmentPerformanceMetrics(),
            'concern_trends' => $this->getConcernTrends(),
            'response_times' => $this->getResponseTimeAnalytics(),
            'escalation_analytics' => $this->getEscalationAnalytics(),
            'satisfaction_metrics' => $this->getSatisfactionMetrics(),
        ];
    }

    /**
     * Get overview metrics
     */
    private function getOverviewMetrics(): array
    {
        $totalConcerns = Concern::count();
        $activeConcerns = Concern::whereIn('status', ['pending', 'approved', 'in_progress'])->count();
        $resolvedConcerns = Concern::whereIn('status', ['resolved', 'student_confirmed', 'closed'])->count();
        $escalatedConcerns = Concern::whereNotNull('escalated_at')->count();

        $avgResolutionTime = Concern::whereNotNull('resolved_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->value('avg_hours') ?? 0;

        return [
            'total_concerns' => $totalConcerns,
            'active_concerns' => $activeConcerns,
            'resolved_concerns' => $resolvedConcerns,
            'escalated_concerns' => $escalatedConcerns,
            'resolution_rate' => $totalConcerns > 0 ? round(($resolvedConcerns / $totalConcerns) * 100, 2) : 0,
            'escalation_rate' => $totalConcerns > 0 ? round(($escalatedConcerns / $totalConcerns) * 100, 2) : 0,
            'avg_resolution_time_hours' => round($avgResolutionTime, 2),
        ];
    }

    /**
     * Get staff performance metrics
     */
    private function getStaffPerformanceMetrics(): array
    {
        $staff = User::where('role', 'staff')
            ->where('is_active', true)
            ->withCount([
                'assignedConcerns as total_assigned',
                'assignedConcerns as resolved_count' => function($query) {
                    $query->whereIn('status', ['resolved', 'student_confirmed', 'closed']);
                },
                'assignedConcerns as escalated_count' => function($query) {
                    $query->whereNotNull('escalated_at');
                }
            ])
            ->get();

        $staffMetrics = $staff->map(function($member) {
            $avgResponseTime = $this->getStaffAverageResponseTime($member->id);
            $avgResolutionTime = $this->getStaffAverageResolutionTime($member->id);
            $satisfactionScore = $this->getStaffSatisfactionScore($member->id);

            return [
                'staff_id' => $member->id,
                'name' => $member->name,
                'department' => $member->department->name ?? 'Unknown',
                'total_assigned' => $member->total_assigned,
                'resolved_count' => $member->resolved_count,
                'escalated_count' => $member->escalated_count,
                'resolution_rate' => $member->total_assigned > 0 ? 
                    round(($member->resolved_count / $member->total_assigned) * 100, 2) : 0,
                'escalation_rate' => $member->total_assigned > 0 ? 
                    round(($member->escalated_count / $member->total_assigned) * 100, 2) : 0,
                'avg_response_time_hours' => $avgResponseTime,
                'avg_resolution_time_hours' => $avgResolutionTime,
                'satisfaction_score' => $satisfactionScore,
                'performance_score' => $this->calculatePerformanceScore($member, $avgResponseTime, $avgResolutionTime, $satisfactionScore),
            ];
        });

        return [
            'top_performers' => $staffMetrics->sortByDesc('performance_score')->take(5)->values(),
            'needs_improvement' => $staffMetrics->sortBy('performance_score')->take(5)->values(),
            'workload_distribution' => $this->getWorkloadDistribution($staffMetrics),
            'average_metrics' => $this->getAverageStaffMetrics($staffMetrics),
        ];
    }

    /**
     * Get department performance metrics
     */
    private function getDepartmentPerformanceMetrics(): array
    {
        $departments = Department::withCount([
            'concerns as total_concerns',
            'concerns as resolved_concerns' => function($query) {
                $query->whereIn('status', ['resolved', 'student_confirmed', 'closed']);
            },
            'concerns as escalated_concerns' => function($query) {
                $query->whereNotNull('escalated_at');
            }
        ])->get();

        $departmentMetrics = $departments->map(function($dept) {
            $avgResolutionTime = $this->getDepartmentAverageResolutionTime($dept->id);
            $satisfactionScore = $this->getDepartmentSatisfactionScore($dept->id);
            $staffCount = User::where('role', 'staff')
                ->where('department_id', $dept->id)
                ->where('is_active', true)
                ->count();

            return [
                'department_id' => $dept->id,
                'name' => $dept->name,
                'total_concerns' => $dept->total_concerns,
                'resolved_concerns' => $dept->resolved_concerns,
                'escalated_concerns' => $dept->escalated_concerns,
                'resolution_rate' => $dept->total_concerns > 0 ? 
                    round(($dept->resolved_concerns / $dept->total_concerns) * 100, 2) : 0,
                'escalation_rate' => $dept->total_concerns > 0 ? 
                    round(($dept->escalated_concerns / $dept->total_concerns) * 100, 2) : 0,
                'avg_resolution_time_hours' => $avgResolutionTime,
                'satisfaction_score' => $satisfactionScore,
                'staff_count' => $staffCount,
                'concerns_per_staff' => $staffCount > 0 ? round($dept->total_concerns / $staffCount, 2) : 0,
            ];
        });

        return [
            'department_rankings' => $departmentMetrics->sortByDesc('resolution_rate')->values(),
            'workload_analysis' => $departmentMetrics->sortByDesc('concerns_per_staff')->values(),
            'performance_summary' => $this->getDepartmentPerformanceSummary($departmentMetrics),
        ];
    }

    /**
     * Get concern trends
     */
    private function getConcernTrends(): array
    {
        $trends = [];
        
        // Last 30 days trend
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $trends['daily'][] = [
                'date' => $date,
                'total' => Concern::whereDate('created_at', $date)->count(),
                'resolved' => Concern::whereDate('resolved_at', $date)->count(),
                'escalated' => Concern::whereDate('escalated_at', $date)->count(),
            ];
        }

        // Weekly trends (last 12 weeks)
        for ($i = 11; $i >= 0; $i--) {
            $startDate = now()->subWeeks($i)->startOfWeek();
            $endDate = now()->subWeeks($i)->endOfWeek();
            
            $trends['weekly'][] = [
                'week' => $startDate->format('M d') . ' - ' . $endDate->format('M d'),
                'total' => Concern::whereBetween('created_at', [$startDate, $endDate])->count(),
                'resolved' => Concern::whereBetween('resolved_at', [$startDate, $endDate])->count(),
                'escalated' => Concern::whereBetween('escalated_at', [$startDate, $endDate])->count(),
            ];
        }

        // Priority distribution
        $trends['priority_distribution'] = Concern::selectRaw('priority, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('priority')
            ->get()
            ->pluck('count', 'priority')
            ->toArray();

        // Type distribution
        $trends['type_distribution'] = Concern::selectRaw('type, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('type')
            ->get()
            ->pluck('count', 'type')
            ->toArray();

        return $trends;
    }

    /**
     * Get response time analytics
     */
    private function getResponseTimeAnalytics(): array
    {
        $responseTimes = Concern::whereNotNull('assigned_to')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('
                AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_assignment_time,
                AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_resolution_time,
                MIN(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as min_assignment_time,
                MAX(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as max_assignment_time
            ')
            ->first();

        $responseTimeDistribution = [
            'under_1_hour' => Concern::whereNotNull('assigned_to')
                ->where('created_at', '>=', now()->subDays(30))
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, updated_at) < 1')
                ->count(),
            '1_to_4_hours' => Concern::whereNotNull('assigned_to')
                ->where('created_at', '>=', now()->subDays(30))
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, updated_at) BETWEEN 1 AND 4')
                ->count(),
            '4_to_24_hours' => Concern::whereNotNull('assigned_to')
                ->where('created_at', '>=', now()->subDays(30))
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, updated_at) BETWEEN 4 AND 24')
                ->count(),
            'over_24_hours' => Concern::whereNotNull('assigned_to')
                ->where('created_at', '>=', now()->subDays(30))
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, updated_at) > 24')
                ->count(),
        ];

        return [
            'average_assignment_time_hours' => round($responseTimes->avg_assignment_time ?? 0, 2),
            'average_resolution_time_hours' => round($responseTimes->avg_resolution_time ?? 0, 2),
            'min_assignment_time_hours' => $responseTimes->min_assignment_time ?? 0,
            'max_assignment_time_hours' => $responseTimes->max_assignment_time ?? 0,
            'distribution' => $responseTimeDistribution,
        ];
    }

    /**
     * Get escalation analytics
     */
    private function getEscalationAnalytics(): array
    {
        $escalationStats = [
            'total_escalated' => Concern::whereNotNull('escalated_at')->count(),
            'escalated_today' => Concern::whereDate('escalated_at', today())->count(),
            'escalated_this_week' => Concern::where('escalated_at', '>=', now()->startOfWeek())->count(),
            'escalated_this_month' => Concern::where('escalated_at', '>=', now()->startOfMonth())->count(),
        ];

        $escalationReasons = Concern::whereNotNull('escalated_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('escalation_reason, COUNT(*) as count')
            ->groupBy('escalation_reason')
            ->get()
            ->pluck('count', 'escalation_reason')
            ->toArray();

        $escalationByPriority = Concern::whereNotNull('escalated_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->get()
            ->pluck('count', 'priority')
            ->toArray();

        return [
            'statistics' => $escalationStats,
            'reasons' => $escalationReasons,
            'by_priority' => $escalationByPriority,
        ];
    }

    /**
     * Get satisfaction metrics
     */
    private function getSatisfactionMetrics(): array
    {
        $satisfactionStats = Concern::whereNotNull('rating')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('
                AVG(rating) as avg_rating,
                COUNT(*) as total_ratings,
                SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as satisfied_count,
                SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as dissatisfied_count
            ')
            ->first();

        $ratingDistribution = Concern::whereNotNull('rating')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->get()
            ->pluck('count', 'rating')
            ->toArray();

        return [
            'average_rating' => round($satisfactionStats->avg_rating ?? 0, 2),
            'total_ratings' => $satisfactionStats->total_ratings ?? 0,
            'satisfaction_rate' => $satisfactionStats->total_ratings > 0 ? 
                round(($satisfactionStats->satisfied_count / $satisfactionStats->total_ratings) * 100, 2) : 0,
            'dissatisfaction_rate' => $satisfactionStats->total_ratings > 0 ? 
                round(($satisfactionStats->dissatisfied_count / $satisfactionStats->total_ratings) * 100, 2) : 0,
            'rating_distribution' => $ratingDistribution,
        ];
    }

    /**
     * Helper methods
     */
    private function getStaffAverageResponseTime(int $staffId): float
    {
        return Concern::where('assigned_to', $staffId)
            ->whereNotNull('assigned_to')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours')
            ->value('avg_hours') ?? 0;
    }

    private function getStaffAverageResolutionTime(int $staffId): float
    {
        return Concern::where('assigned_to', $staffId)
            ->whereNotNull('resolved_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->value('avg_hours') ?? 0;
    }

    private function getStaffSatisfactionScore(int $staffId): float
    {
        return Concern::where('assigned_to', $staffId)
            ->whereNotNull('rating')
            ->where('created_at', '>=', now()->subDays(30))
            ->avg('rating') ?? 0;
    }

    private function calculatePerformanceScore($staff, float $avgResponseTime, float $avgResolutionTime, float $satisfactionScore): float
    {
        $resolutionScore = $staff->total_assigned > 0 ? ($staff->resolved_count / $staff->total_assigned) * 100 : 0;
        $escalationPenalty = $staff->total_assigned > 0 ? ($staff->escalated_count / $staff->total_assigned) * 100 : 0;
        
        // Response time score (lower is better)
        $responseScore = $avgResponseTime > 0 ? max(0, 100 - ($avgResponseTime * 2)) : 100;
        
        // Resolution time score (lower is better)
        $resolutionTimeScore = $avgResolutionTime > 0 ? max(0, 100 - ($avgResolutionTime * 0.5)) : 100;
        
        // Satisfaction score
        $satisfactionScoreNormalized = $satisfactionScore * 20; // Convert 1-5 to 0-100
        
        // Weighted performance score
        $performanceScore = (
            $resolutionScore * 0.3 +
            $responseScore * 0.2 +
            $resolutionTimeScore * 0.2 +
            $satisfactionScoreNormalized * 0.2 +
            (100 - $escalationPenalty) * 0.1
        );
        
        return round($performanceScore, 2);
    }

    private function getWorkloadDistribution($staffMetrics): array
    {
        $workloads = $staffMetrics->pluck('total_assigned')->toArray();
        
        return [
            'min' => min($workloads),
            'max' => max($workloads),
            'average' => round(array_sum($workloads) / count($workloads), 2),
            'median' => $this->calculateMedian($workloads),
        ];
    }

    private function getAverageStaffMetrics($staffMetrics): array
    {
        return [
            'avg_resolution_rate' => round($staffMetrics->avg('resolution_rate'), 2),
            'avg_escalation_rate' => round($staffMetrics->avg('escalation_rate'), 2),
            'avg_response_time' => round($staffMetrics->avg('avg_response_time_hours'), 2),
            'avg_resolution_time' => round($staffMetrics->avg('avg_resolution_time_hours'), 2),
            'avg_satisfaction' => round($staffMetrics->avg('satisfaction_score'), 2),
            'avg_performance_score' => round($staffMetrics->avg('performance_score'), 2),
        ];
    }

    private function getDepartmentAverageResolutionTime(int $departmentId): float
    {
        return Concern::where('department_id', $departmentId)
            ->whereNotNull('resolved_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->value('avg_hours') ?? 0;
    }

    private function getDepartmentSatisfactionScore(int $departmentId): float
    {
        return Concern::where('department_id', $departmentId)
            ->whereNotNull('rating')
            ->where('created_at', '>=', now()->subDays(30))
            ->avg('rating') ?? 0;
    }

    private function getDepartmentPerformanceSummary($departmentMetrics): array
    {
        return [
            'total_departments' => $departmentMetrics->count(),
            'avg_resolution_rate' => round($departmentMetrics->avg('resolution_rate'), 2),
            'avg_escalation_rate' => round($departmentMetrics->avg('escalation_rate'), 2),
            'avg_satisfaction' => round($departmentMetrics->avg('satisfaction_score'), 2),
            'total_concerns' => $departmentMetrics->sum('total_concerns'),
            'total_resolved' => $departmentMetrics->sum('resolved_concerns'),
        ];
    }

    private function calculateMedian(array $numbers): float
    {
        sort($numbers);
        $count = count($numbers);
        $middle = floor($count / 2);
        
        if ($count % 2 == 0) {
            return ($numbers[$middle - 1] + $numbers[$middle]) / 2;
        } else {
            return $numbers[$middle];
        }
    }
}
