<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\User;
use App\Models\Department;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Advanced Analytics Service
 * 
 * Provides comprehensive analytics and reporting capabilities
 * Following clean architecture principles with separation of concerns
 */
class AdvancedAnalyticsService
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CHART_DATA_CACHE_TTL = 600; // 10 minutes

    /**
     * Get comprehensive dashboard analytics
     */
    public function getDashboardAnalytics(array $filters = []): array
    {
        $cacheKey = 'dashboard_analytics_' . md5(serialize($filters));
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($filters) {
            return [
                'overview' => $this->getOverviewMetrics($filters),
                'performance' => $this->getPerformanceMetrics($filters),
                'trends' => $this->getTrendAnalytics($filters),
                'department_analytics' => $this->getDepartmentAnalytics($filters),
                'staff_analytics' => $this->getStaffAnalytics($filters),
                'response_times' => $this->getResponseTimeAnalytics($filters),
                'satisfaction_metrics' => $this->getSatisfactionMetrics($filters),
            ];
        });
    }

    /**
     * Get overview metrics
     */
    private function getOverviewMetrics(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $query = DB::table('concerns')
            ->selectRaw('
                COUNT(*) as total_concerns,
                COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_concerns,
                COUNT(CASE WHEN status = "in_progress" THEN 1 END) as in_progress_concerns,
                COUNT(CASE WHEN status = "resolved" OR status = "student_confirmed" THEN 1 END) as resolved_concerns,
                COUNT(CASE WHEN status = "student_confirmed" THEN 1 END) as confirmed_concerns,
                COUNT(CASE WHEN priority = "urgent" THEN 1 END) as urgent_concerns,
                COUNT(CASE WHEN priority = "high" THEN 1 END) as high_priority_concerns,
                COUNT(CASE WHEN archived_at IS NOT NULL THEN 1 END) as archived_concerns
            ')
            ->whereBetween('created_at', $dateRange);
            
        // Filter by department if specified
        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        
        // Filter by staff if specified
        if (isset($filters['staff_id'])) {
            $query->where('assigned_to', $filters['staff_id']);
        }
        
        $metrics = $query->first();

        return [
            'total_concerns' => (int) $metrics->total_concerns,
            'pending_concerns' => (int) $metrics->pending_concerns,
            'in_progress_concerns' => (int) $metrics->in_progress_concerns,
            'resolved_concerns' => (int) $metrics->resolved_concerns,
            'confirmed_concerns' => (int) $metrics->confirmed_concerns,
            'urgent_concerns' => (int) $metrics->urgent_concerns,
            'high_priority_concerns' => (int) $metrics->high_priority_concerns,
            'archived_concerns' => (int) $metrics->archived_concerns,
            'resolution_rate' => $this->calculateResolutionRate($metrics),
            'avg_resolution_time' => $this->getAverageResolutionTime($dateRange, $filters),
        ];
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $performance = DB::table('concerns')
            ->selectRaw('
                AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_first_response_time,
                AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_resolution_time,
                MIN(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as fastest_resolution,
                MAX(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as slowest_resolution,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 24 THEN 1 END) as resolved_within_24h,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 48 THEN 1 END) as resolved_within_48h
            ')
            ->whereBetween('created_at', $dateRange)
            ->whereIn('status', ['resolved', 'student_confirmed'])
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->when(isset($filters['staff_id']), function ($query) use ($filters) {
                return $query->where('assigned_to', $filters['staff_id']);
            })
            ->first();

        $totalResolved = DB::table('concerns')
            ->whereBetween('created_at', $dateRange)
            ->whereIn('status', ['resolved', 'student_confirmed'])
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->when(isset($filters['staff_id']), function ($query) use ($filters) {
                return $query->where('assigned_to', $filters['staff_id']);
            })
            ->count();

        return [
            'avg_first_response_time_hours' => round($performance->avg_first_response_time ?? 0, 2),
            'avg_resolution_time_hours' => round($performance->avg_resolution_time ?? 0, 2),
            'fastest_resolution_hours' => round($performance->fastest_resolution ?? 0, 2),
            'slowest_resolution_hours' => round($performance->slowest_resolution ?? 0, 2),
            'resolved_within_24h' => (int) $performance->resolved_within_24h,
            'resolved_within_48h' => (int) $performance->resolved_within_48h,
            'resolution_within_24h_rate' => $totalResolved > 0 ? round(($performance->resolved_within_24h / $totalResolved) * 100, 2) : 0,
            'resolution_within_48h_rate' => $totalResolved > 0 ? round(($performance->resolved_within_48h / $totalResolved) * 100, 2) : 0,
        ];
    }

    /**
     * Get trend analytics
     */
    private function getTrendAnalytics(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        $period = $filters['period'] ?? 'daily';
        
        $trends = DB::table('concerns')
            ->selectRaw($this->getDateGrouping($period) . ' as period')
            ->selectRaw('COUNT(*) as total_concerns')
            ->selectRaw('COUNT(CASE WHEN status = "resolved" OR status = "student_confirmed" THEN 1 END) as resolved_concerns')
            ->selectRaw('COUNT(CASE WHEN priority = "urgent" THEN 1 END) as urgent_concerns')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_resolution_time')
            ->whereBetween('created_at', $dateRange)
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return [
            'period' => $period,
            'data' => $trends->map(function ($item) {
                return [
                    'period' => $item->period,
                    'total_concerns' => (int) $item->total_concerns,
                    'resolved_concerns' => (int) $item->resolved_concerns,
                    'urgent_concerns' => (int) $item->urgent_concerns,
                    'avg_resolution_time' => round($item->avg_resolution_time ?? 0, 2),
                    'resolution_rate' => $item->total_concerns > 0 ? round(($item->resolved_concerns / $item->total_concerns) * 100, 2) : 0,
                ];
            })->toArray(),
        ];
    }

    /**
     * Get department analytics
     */
    private function getDepartmentAnalytics(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $departments = DB::table('departments')
            ->leftJoin('concerns', 'departments.id', '=', 'concerns.department_id')
            ->leftJoin('users', function ($join) {
                $join->on('departments.id', '=', 'users.department_id')
                     ->where('users.role', '=', 'staff')
                     ->where('users.is_active', '=', 1);
            })
            ->selectRaw('departments.id, departments.name, departments.code')
            ->selectRaw('COUNT(DISTINCT concerns.id) as total_concerns')
            ->selectRaw('COUNT(CASE WHEN concerns.status = "resolved" THEN 1 END) as resolved_concerns')
            ->selectRaw('COUNT(CASE WHEN concerns.priority = "urgent" THEN 1 END) as urgent_concerns')
            ->selectRaw('COUNT(DISTINCT users.id) as staff_count')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, concerns.created_at, concerns.resolved_at)) as avg_resolution_time')
            ->whereBetween('concerns.created_at', $dateRange)
            ->orWhereNull('concerns.created_at') // Include departments with no concerns
            ->groupBy('departments.id', 'departments.name', 'departments.code')
            ->orderBy('total_concerns', 'desc')
            ->get();

        return $departments->map(function ($dept) {
            return [
                'id' => $dept->id,
                'name' => $dept->name,
                'code' => $dept->code,
                'total_concerns' => (int) $dept->total_concerns,
                'resolved_concerns' => (int) $dept->resolved_concerns,
                'urgent_concerns' => (int) $dept->urgent_concerns,
                'staff_count' => (int) $dept->staff_count,
                'avg_resolution_time' => round($dept->avg_resolution_time ?? 0, 2),
                'resolution_rate' => $dept->total_concerns > 0 ? round(($dept->resolved_concerns / $dept->total_concerns) * 100, 2) : 0,
                'workload_per_staff' => $dept->staff_count > 0 ? round($dept->total_concerns / $dept->staff_count, 2) : 0,
            ];
        })->toArray();
    }

    /**
     * Get staff analytics
     */
    private function getStaffAnalytics(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $query = DB::table('users')
            ->leftJoin('concerns', function($join) use ($dateRange) {
                $join->on('users.id', '=', 'concerns.assigned_to')
                     ->whereBetween('concerns.created_at', $dateRange);
            })
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id')
            ->selectRaw('users.id, users.name, users.email, departments.name as department_name')
            ->selectRaw('COUNT(concerns.id) as total_assigned')
            ->selectRaw('COUNT(CASE WHEN concerns.status = "resolved" OR concerns.status = "student_confirmed" THEN 1 END) as resolved_concerns')
            ->selectRaw('COUNT(CASE WHEN concerns.priority = "urgent" THEN 1 END) as urgent_concerns')
            ->selectRaw('AVG(CASE WHEN concerns.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, concerns.created_at, concerns.resolved_at) END) as avg_resolution_time')
            ->where('users.role', 'staff')
            ->where('users.is_active', 1);
            
        // Filter by department if specified
        if (isset($filters['department_id'])) {
            $query->where('users.department_id', $filters['department_id']);
        }
        
        // Filter by specific staff if specified
        if (isset($filters['staff_id'])) {
            $query->where('users.id', $filters['staff_id']);
        }
        
        $staff = $query->groupBy('users.id', 'users.name', 'users.email', 'departments.name')
            ->orderBy('total_assigned', 'desc')
            ->get();

        return $staff->map(function ($member) {
            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'department' => $member->department_name,
                'total_assigned' => (int) $member->total_assigned,
                'resolved_concerns' => (int) $member->resolved_concerns,
                'urgent_concerns' => (int) $member->urgent_concerns,
                'avg_resolution_time' => round($member->avg_resolution_time ?? 0, 2),
                'resolution_rate' => $member->total_assigned > 0 ? round(($member->resolved_concerns / $member->total_assigned) * 100, 2) : 0,
            ];
        })->toArray();
    }

    /**
     * Get response time analytics
     */
    private function getResponseTimeAnalytics(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $responseTimes = DB::table('concerns')
            ->selectRaw('
                AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_first_response_minutes,
                AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_resolution_hours,
                COUNT(CASE WHEN TIMESTAMPDIFF(MINUTE, created_at, updated_at) <= 30 THEN 1 END) as responded_within_30min,
                COUNT(CASE WHEN TIMESTAMPDIFF(MINUTE, created_at, updated_at) <= 60 THEN 1 END) as responded_within_1hour,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 2 THEN 1 END) as resolved_within_2hours,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 24 THEN 1 END) as resolved_within_24hours
            ')
            ->whereBetween('created_at', $dateRange)
            ->whereNotNull('updated_at')
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->first();

        $totalConcerns = DB::table('concerns')
            ->whereBetween('created_at', $dateRange)
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->count();

        return [
            'avg_first_response_minutes' => round($responseTimes->avg_first_response_minutes ?? 0, 2),
            'avg_resolution_hours' => round($responseTimes->avg_resolution_hours ?? 0, 2),
            'responded_within_30min' => (int) $responseTimes->responded_within_30min,
            'responded_within_1hour' => (int) $responseTimes->responded_within_1hour,
            'resolved_within_2hours' => (int) $responseTimes->resolved_within_2hours,
            'resolved_within_24hours' => (int) $responseTimes->resolved_within_24hours,
            'response_within_30min_rate' => $totalConcerns > 0 ? round(($responseTimes->responded_within_30min / $totalConcerns) * 100, 2) : 0,
            'response_within_1hour_rate' => $totalConcerns > 0 ? round(($responseTimes->responded_within_1hour / $totalConcerns) * 100, 2) : 0,
        ];
    }

    /**
     * Get satisfaction metrics
     */
    private function getSatisfactionMetrics(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $satisfaction = DB::table('concerns')
            ->selectRaw('
                AVG(rating) as avg_rating,
                COUNT(CASE WHEN rating >= 4 THEN 1 END) as high_ratings,
                COUNT(CASE WHEN rating >= 3 THEN 1 END) as good_ratings,
                COUNT(CASE WHEN rating < 3 THEN 1 END) as low_ratings,
                COUNT(CASE WHEN rating IS NOT NULL THEN 1 END) as total_rated
            ')
            ->whereBetween('created_at', $dateRange)
            ->whereNotNull('rating')
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->first();

        return [
            'avg_rating' => round($satisfaction->avg_rating ?? 0, 2),
            'high_ratings' => (int) $satisfaction->high_ratings,
            'good_ratings' => (int) $satisfaction->good_ratings,
            'low_ratings' => (int) $satisfaction->low_ratings,
            'total_rated' => (int) $satisfaction->total_rated,
            'satisfaction_rate' => $satisfaction->total_rated > 0 ? round(($satisfaction->high_ratings / $satisfaction->total_rated) * 100, 2) : 0,
        ];
    }

    /**
     * Get chart data for specific metrics
     */
    public function getChartData(string $chartType, array $filters = []): array
    {
        $cacheKey = "chart_data_{$chartType}_" . md5(serialize($filters));
        
        return Cache::remember($cacheKey, self::CHART_DATA_CACHE_TTL, function () use ($chartType, $filters) {
            switch ($chartType) {
                case 'concerns_over_time':
                    return $this->getConcernsOverTimeChart($filters);
                case 'priority_distribution':
                    return $this->getPriorityDistributionChart($filters);
                case 'department_performance':
                    return $this->getDepartmentPerformanceChart($filters);
                case 'resolution_times':
                    return $this->getResolutionTimesChart($filters);
                case 'staff_workload':
                    return $this->getStaffWorkloadChart($filters);
                default:
                    throw new \InvalidArgumentException("Unknown chart type: {$chartType}");
            }
        });
    }

    /**
     * Get concerns over time chart data
     */
    private function getConcernsOverTimeChart(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        $period = $filters['period'] ?? 'daily';
        
        $data = DB::table('concerns')
            ->selectRaw($this->getDateGrouping($period) . ' as period')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(CASE WHEN status = "resolved" THEN 1 END) as resolved')
            ->selectRaw('COUNT(CASE WHEN priority = "urgent" THEN 1 END) as urgent')
            ->whereBetween('created_at', $dateRange)
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return [
            'labels' => $data->pluck('period')->toArray(),
            'datasets' => [
                [
                    'label' => 'Total Concerns',
                    'data' => $data->pluck('total')->toArray(),
                    'borderColor' => '#3B82F6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ],
                [
                    'label' => 'Resolved',
                    'data' => $data->pluck('resolved')->toArray(),
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                ],
                [
                    'label' => 'Urgent',
                    'data' => $data->pluck('urgent')->toArray(),
                    'borderColor' => '#EF4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                ],
            ],
        ];
    }

    /**
     * Get priority distribution chart data
     */
    private function getPriorityDistributionChart(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $data = DB::table('concerns')
            ->selectRaw('priority, COUNT(*) as count')
            ->whereBetween('created_at', $dateRange)
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->groupBy('priority')
            ->get();

        $colors = [
            'urgent' => '#EF4444',
            'high' => '#F97316',
            'medium' => '#EAB308',
            'low' => '#10B981',
        ];

        return [
            'labels' => $data->pluck('priority')->map('ucfirst')->toArray(),
            'datasets' => [
                [
                    'data' => $data->pluck('count')->toArray(),
                    'backgroundColor' => $data->pluck('priority')->map(function ($priority) use ($colors) {
                        return $colors[$priority] ?? '#6B7280';
                    })->toArray(),
                ],
            ],
        ];
    }

    /**
     * Get department performance chart data
     */
    private function getDepartmentPerformanceChart(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $data = DB::table('departments')
            ->leftJoin('concerns', 'departments.id', '=', 'concerns.department_id')
            ->selectRaw('departments.name')
            ->selectRaw('COUNT(concerns.id) as total_concerns')
            ->selectRaw('COUNT(CASE WHEN concerns.status = "resolved" THEN 1 END) as resolved_concerns')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, concerns.created_at, concerns.resolved_at)) as avg_resolution_time')
            ->whereBetween('concerns.created_at', $dateRange)
            ->orWhereNull('concerns.created_at')
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('total_concerns', 'desc')
            ->limit(10)
            ->get();

        return [
            'labels' => $data->pluck('name')->toArray(),
            'datasets' => [
                [
                    'label' => 'Total Concerns',
                    'data' => $data->pluck('total_concerns')->toArray(),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
                ],
                [
                    'label' => 'Resolved',
                    'data' => $data->pluck('resolved_concerns')->toArray(),
                    'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
                ],
            ],
        ];
    }

    /**
     * Get resolution times chart data
     */
    private function getResolutionTimesChart(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $data = DB::table('concerns')
            ->selectRaw('
                CASE 
                    WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 2 THEN "0-2 hours"
                    WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 24 THEN "2-24 hours"
                    WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 72 THEN "1-3 days"
                    WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 168 THEN "3-7 days"
                    ELSE "7+ days"
                END as time_range,
                COUNT(*) as count
            ')
            ->whereBetween('created_at', $dateRange)
            ->whereNotNull('resolved_at')
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->groupBy('time_range')
            ->orderByRaw('
                CASE time_range
                    WHEN "0-2 hours" THEN 1
                    WHEN "2-24 hours" THEN 2
                    WHEN "1-3 days" THEN 3
                    WHEN "3-7 days" THEN 4
                    ELSE 5
                END
            ')
            ->get();

        return [
            'labels' => $data->pluck('time_range')->toArray(),
            'datasets' => [
                [
                    'data' => $data->pluck('count')->toArray(),
                    'backgroundColor' => [
                        '#10B981', // Green for fast
                        '#3B82F6', // Blue
                        '#EAB308', // Yellow
                        '#F97316', // Orange
                        '#EF4444', // Red for slow
                    ],
                ],
            ],
        ];
    }

    /**
     * Get staff workload chart data
     */
    private function getStaffWorkloadChart(array $filters): array
    {
        $dateRange = $this->getDateRange($filters);
        
        $data = DB::table('users')
            ->leftJoin('concerns', 'users.id', '=', 'concerns.assigned_to')
            ->selectRaw('users.name')
            ->selectRaw('COUNT(concerns.id) as total_assigned')
            ->selectRaw('COUNT(CASE WHEN concerns.status = "resolved" THEN 1 END) as resolved')
            ->where('users.role', 'staff')
            ->where('users.is_active', 1)
            ->whereBetween('concerns.created_at', $dateRange)
            ->orWhereNull('concerns.created_at')
            ->groupBy('users.id', 'users.name')
            ->orderBy('total_assigned', 'desc')
            ->limit(15)
            ->get();

        return [
            'labels' => $data->pluck('name')->toArray(),
            'datasets' => [
                [
                    'label' => 'Total Assigned',
                    'data' => $data->pluck('total_assigned')->toArray(),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
                ],
                [
                    'label' => 'Resolved',
                    'data' => $data->pluck('resolved')->toArray(),
                    'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
                ],
            ],
        ];
    }

    /**
     * Helper methods
     */
    private function getDateRange(array $filters): array
    {
        $startDate = $filters['start_date'] ?? now()->subDays(30)->startOfDay();
        $endDate = $filters['end_date'] ?? now()->endOfDay();
        
        return [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay(),
        ];
    }

    private function getDateGrouping(string $period): string
    {
        return match ($period) {
            'hourly' => 'DATE_FORMAT(created_at, "%Y-%m-%d %H:00")',
            'daily' => 'DATE(created_at)',
            'weekly' => 'DATE_FORMAT(created_at, "%Y-%u")',
            'monthly' => 'DATE_FORMAT(created_at, "%Y-%m")',
            default => 'DATE(created_at)',
        };
    }

    private function calculateResolutionRate($metrics): float
    {
        $total = $metrics->total_concerns;
        $resolved = $metrics->resolved_concerns + $metrics->confirmed_concerns;
        
        return $total > 0 ? round(($resolved / $total) * 100, 2) : 0;
    }

    private function getAverageResolutionTime(array $dateRange, array $filters): float
    {
        $avgTime = DB::table('concerns')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->whereBetween('created_at', $dateRange)
            ->whereNotNull('resolved_at')
            ->when(isset($filters['department_id']), function ($query) use ($filters) {
                return $query->where('department_id', $filters['department_id']);
            })
            ->value('avg_hours');

        return round($avgTime ?? 0, 2);
    }

    /**
     * Clear analytics cache
     */
    public function clearCache(): void
    {
        Cache::tags(['analytics'])->flush();
    }
}
