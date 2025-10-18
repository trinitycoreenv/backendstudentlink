<?php

namespace App\Http\Controllers;

use App\Services\AdvancedAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Advanced Analytics Controller
 * 
 * Provides RESTful API endpoints for analytics and reporting
 * Following clean architecture and SOLID principles
 */
class AdvancedAnalyticsController extends Controller
{
    protected AdvancedAnalyticsService $analyticsService;

    public function __construct(AdvancedAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get comprehensive dashboard analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getDashboardAnalytics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Dashboard Analytics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get chart data for specific chart type
     * 
     * @param Request $request
     * @param string $chartType
     * @return JsonResponse
     */
    public function getChartData(Request $request, string $chartType): JsonResponse
    {
        try {
            $validator = Validator::make(['chart_type' => $chartType], [
                'chart_type' => 'required|string|in:concerns_over_time,priority_distribution,department_performance,resolution_times,staff_workload',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid chart type',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $filters = $this->validateAndSanitizeFilters($request);
            $chartData = $this->analyticsService->getChartData($chartType, $filters);

            return response()->json([
                'success' => true,
                'data' => $chartData,
                'chart_type' => $chartType,
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Chart Data Error: ' . $e->getMessage(), [
                'chart_type' => $chartType,
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve chart data',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get overview metrics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getOverviewMetrics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics['overview'],
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Overview Metrics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve overview metrics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get performance metrics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getPerformanceMetrics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics['performance'],
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Performance Metrics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance metrics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get trend analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getTrendAnalytics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics['trends'],
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Trend Analytics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve trend analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get department analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getDepartmentAnalytics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics['department_analytics'],
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Department Analytics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve department analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get staff analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getStaffAnalytics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics['staff_analytics'],
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Staff Analytics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get response time analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getResponseTimeAnalytics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics['response_times'],
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Response Time Analytics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve response time analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get satisfaction metrics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getSatisfactionMetrics(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateAndSanitizeFilters($request);
            $analytics = $this->analyticsService->getDashboardAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics['satisfaction_metrics'],
                'filters_applied' => $filters,
                'generated_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Satisfaction Metrics Error: ' . $e->getMessage(), [
                'filters' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve satisfaction metrics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Clear analytics cache
     * 
     * @return JsonResponse
     */
    public function clearCache(): JsonResponse
    {
        try {
            $this->analyticsService->clearCache();

            return response()->json([
                'success' => true,
                'message' => 'Analytics cache cleared successfully',
                'cleared_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Clear Cache Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to clear analytics cache',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get available chart types
     * 
     * @return JsonResponse
     */
    public function getAvailableCharts(): JsonResponse
    {
        $chartTypes = [
            [
                'type' => 'concerns_over_time',
                'name' => 'Concerns Over Time',
                'description' => 'Shows concern volume trends over time',
                'category' => 'trends',
            ],
            [
                'type' => 'priority_distribution',
                'name' => 'Priority Distribution',
                'description' => 'Shows distribution of concern priorities',
                'category' => 'distribution',
            ],
            [
                'type' => 'department_performance',
                'name' => 'Department Performance',
                'description' => 'Compares performance across departments',
                'category' => 'performance',
            ],
            [
                'type' => 'resolution_times',
                'name' => 'Resolution Times',
                'description' => 'Shows distribution of resolution times',
                'category' => 'performance',
            ],
            [
                'type' => 'staff_workload',
                'name' => 'Staff Workload',
                'description' => 'Shows workload distribution among staff',
                'category' => 'workload',
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $chartTypes,
        ]);
    }

    /**
     * Validate and sanitize filter parameters
     * 
     * @param Request $request
     * @return array
     */
    private function validateAndSanitizeFilters(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date|before_or_equal:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'department_id' => 'nullable|integer|exists:departments,id',
            'period' => 'nullable|string|in:hourly,daily,weekly,monthly',
            'staff_id' => 'nullable|integer|exists:users,id',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'status' => 'nullable|string|in:pending,approved,in_progress,resolved,closed',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid filter parameters: ' . $validator->errors()->first());
        }

        $filters = $validator->validated();

        // Set default date range if not provided
        if (!isset($filters['start_date'])) {
            $filters['start_date'] = now()->subDays(30)->startOfDay();
        }
        if (!isset($filters['end_date'])) {
            $filters['end_date'] = now()->endOfDay();
        }

        // Set default period if not provided
        if (!isset($filters['period'])) {
            $filters['period'] = 'daily';
        }

        return $filters;
    }
}
