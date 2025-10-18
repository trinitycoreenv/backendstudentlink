<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Concern;
use App\Models\Announcement;
use App\Models\Department;
use App\Services\PerformanceAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class AnalyticsController extends Controller
{
    use ExportMethods;
    
    protected PerformanceAnalyticsService $performanceAnalyticsService;

    public function __construct(PerformanceAnalyticsService $performanceAnalyticsService)
    {
        $this->performanceAnalyticsService = $performanceAnalyticsService;
    }
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(): JsonResponse
    {
        $user = auth()->user();

        try {
            // Get total counts
            $totalUsers = User::where('is_active', true)->count();
            $totalConcerns = Concern::count();
            $activeConcerns = Concern::whereIn('status', ['pending', 'in_progress'])->count();
            $resolvedConcerns = Concern::where('status', 'resolved')->count();
            $pendingConcerns = Concern::where('status', 'pending')->count();

            // Get recent concerns for student dashboard
            $recentConcerns = [];
            if ($user->role === 'student') {
                $recentConcerns = Concern::where('student_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->with(['department', 'facility'])
                    ->get()
                    ->map(function ($concern) {
                        return [
                            'id' => $concern->id,
                            'title' => $concern->subject,
                            'department' => $concern->department ? $concern->department->name : 'General',
                            'facility' => $concern->facility ? $concern->facility->name : 'General',
                            'status' => $concern->status,
                            'submittedAt' => $concern->created_at,
                            'description' => $concern->description,
                        ];
                    });
            }

            // Get recent announcements
            $recentAnnouncements = Announcement::where('status', 'published')
                ->where('published_at', '<=', now())
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->with(['author:id,name,role,department_id', 'author.department:id,name'])
                ->orderBy('published_at', 'desc')
                ->limit(3)
                ->get()
                ->map(function ($announcement) {
                    // Get department name from author's department or target departments
                    $departmentName = 'General';
                    if ($announcement->author && $announcement->author->department) {
                        $departmentName = $announcement->author->department->name ?? 'General';
                    } elseif ($announcement->targetDepartments && $announcement->targetDepartments->isNotEmpty()) {
                        $departmentName = $announcement->targetDepartments->first()->name ?? 'General';
                    }
                    
                    return [
                        'id' => $announcement->id,
                        'title' => $announcement->title,
                        'content' => $announcement->excerpt ?? substr($announcement->content, 0, 150) . '...',
                        'author' => $announcement->author->name ?? 'System',
                        'department' => $departmentName,
                        'publishedAt' => $announcement->published_at,
                        'priority' => $announcement->priority,
                        'category' => $announcement->type,
                        'isBookmarked' => false, // TODO: Implement bookmark check
                    ];
                });

            // Get department statistics
            $departmentStats = Department::withCount([
                'concerns',
                'concerns as resolved_concerns_count' => function ($query) {
                    $query->where('status', 'resolved');
                }
            ])->get()->map(function ($dept) {
                return [
                    'department' => $dept->name,
                    'concernCount' => $dept->concerns_count,
                    'resolvedCount' => $dept->resolved_concerns_count,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'totalUsers' => $totalUsers,
                    'activeConcerns' => $activeConcerns,
                    'resolvedConcerns' => $resolvedConcerns,
                    'pendingConcerns' => $pendingConcerns,
                    'systemHealth' => 95, // TODO: Implement actual health check
                    'aiInteractions' => 0, // TODO: Count AI interactions
                    'departmentStats' => $departmentStats,
                    'recentConcerns' => $recentConcerns,
                    'recentAnnouncements' => $recentAnnouncements,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get dashboard statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get concern statistics
     */
    public function getConcernStats(Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->input('date_from', now()->subDays(30));
            $dateTo = $request->input('date_to', now());

            $stats = Concern::whereBetween('created_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = "pending" THEN 1 END) as pending,
                    COUNT(CASE WHEN status = "in_progress" THEN 1 END) as in_progress,
                    COUNT(CASE WHEN status = "resolved" THEN 1 END) as resolved,
                    COUNT(CASE WHEN status = "closed" THEN 1 END) as closed
                ')
                ->first();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get concern statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get department statistics
     */
    public function getDepartmentStats(): JsonResponse
    {
        try {
            $stats = Department::withCount([
                'users',
                'concerns',
                'concerns as pending_concerns' => function ($query) {
                    $query->where('status', 'pending');
                },
                'concerns as resolved_concerns' => function ($query) {
                    $query->where('status', 'resolved');
                }
            ])->get();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get department statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get user statistics
     */
    public function getUserStats(): JsonResponse
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'by_role' => User::selectRaw('role, COUNT(*) as count')
                    ->groupBy('role')
                    ->pluck('count', 'role'),
                'recent_logins' => User::whereNotNull('last_login_at')
                    ->where('last_login_at', '>=', now()->subDays(7))
                    ->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get concern report
     */
    public function getConcernReport(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'department_id' => 'nullable|exists:departments,id',
                'status' => 'nullable|in:pending,in_progress,resolved,closed',
            ]);

            $query = Concern::with(['student', 'department', 'assignedTo']);

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->input('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->input('date_to'));
            }

            if ($request->filled('department_id')) {
                $query->where('department_id', $request->input('department_id'));
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            $concerns = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $concerns,
                'total' => $concerns->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate concern report',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Export report to CSV/PDF
     */
    public function exportReport(Request $request)
    {
        try {
            $request->validate([
                'format' => 'required|in:csv,pdf,excel',
                'type' => 'required|in:concerns,users,announcements,departments',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'department_id' => 'nullable|exists:departments,id',
                'status' => 'nullable|string',
            ]);

            $format = $request->input('format');
            $type = $request->input('type');
            $filters = $request->only(['date_from', 'date_to', 'department_id', 'status']);

            switch ($type) {
                case 'concerns':
                    return $this->exportConcernsReport($format, $filters);
                case 'users':
                    return $this->exportUsersReport($format, $filters);
                case 'announcements':
                    return $this->exportAnnouncementsReport($format, $filters);
                case 'departments':
                    return $this->exportDepartmentsReport($format, $filters);
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid report type',
                    ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export report: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export concerns report
     */
    private function exportConcernsReport(string $format, array $filters)
    {
        $query = Concern::with(['student', 'department', 'assignedTo', 'facility']);

        // Apply filters
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $concerns = $query->orderBy('created_at', 'desc')->get();

        switch ($format) {
            case 'csv':
                return $this->exportConcernsToCSV($concerns);
            case 'pdf':
                return $this->exportConcernsToPDF($concerns, $filters);
            case 'excel':
                return $this->exportConcernsToExcel($concerns);
            default:
                throw new \InvalidArgumentException('Unsupported format: ' . $format);
        }
    }

    /**
     * Export concerns to CSV
     */
    private function exportConcernsToCSV($concerns)
    {
        $filename = 'concerns_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($concerns) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'Reference Number',
                'Subject',
                'Description',
                'Type',
                'Priority',
                'Status',
                'Student Name',
                'Student ID',
                'Department',
                'Assigned To',
                'Facility',
                'Created At',
                'Updated At',
                'Resolved At'
            ]);

            // CSV data
            foreach ($concerns as $concern) {
                fputcsv($file, [
                    $concern->reference_number,
                    $concern->subject,
                    $concern->description,
                    $concern->type,
                    $concern->priority,
                    $concern->status,
                    $concern->student->name ?? 'N/A',
                    $concern->student->display_id ?? 'N/A',
                    $concern->department->name ?? 'N/A',
                    $concern->assignedTo->name ?? 'Unassigned',
                    $concern->facility->name ?? 'General',
                    $concern->created_at->format('Y-m-d H:i:s'),
                    $concern->updated_at->format('Y-m-d H:i:s'),
                    $concern->resolved_at ? $concern->resolved_at->format('Y-m-d H:i:s') : 'N/A'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export concerns to PDF
     */
    private function exportConcernsToPDF($concerns, $filters)
    {
        $filename = 'concerns_report_' . date('Y-m-d_H-i-s') . '.pdf';
        
        $data = [
            'concerns' => $concerns,
            'filters' => $filters,
            'generated_at' => now(),
            'total_count' => $concerns->count(),
        ];

        $pdf = Pdf::loadView('reports.concerns', $data);
        
        return $pdf->download($filename);
    }

    /**
     * Export concerns to Excel
     */
    private function exportConcernsToExcel($concerns)
    {
        // For now, return CSV format (Excel can open CSV files)
        return $this->exportConcernsToCSV($concerns);
    }

    /**
     * Get comprehensive performance analytics
     */
    public function getPerformanceAnalytics(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get performance analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get staff performance metrics
     */
    public function getStaffPerformanceMetrics(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();
            $staffMetrics = $analytics['staff_performance'];

            return response()->json([
                'success' => true,
                'data' => $staffMetrics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get staff performance metrics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get department performance metrics
     */
    public function getDepartmentPerformanceMetrics(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();
            $departmentMetrics = $analytics['department_performance'];

            return response()->json([
                'success' => true,
                'data' => $departmentMetrics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get department performance metrics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get concern trends
     */
    public function getConcernTrends(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();
            $trends = $analytics['concern_trends'];

            return response()->json([
                'success' => true,
                'data' => $trends
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get concern trends',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get response time analytics
     */
    public function getResponseTimeAnalytics(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();
            $responseTimes = $analytics['response_times'];

            return response()->json([
                'success' => true,
                'data' => $responseTimes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get response time analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get escalation analytics
     */
    public function getEscalationAnalytics(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();
            $escalationAnalytics = $analytics['escalation_analytics'];

            return response()->json([
                'success' => true,
                'data' => $escalationAnalytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get escalation analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get satisfaction metrics
     */
    public function getSatisfactionMetrics(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();
            $satisfactionMetrics = $analytics['satisfaction_metrics'];

            return response()->json([
                'success' => true,
                'data' => $satisfactionMetrics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get satisfaction metrics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get predictive data for N8N automation
     */
    public function getPredictiveData(): JsonResponse
    {
        try {
            $analytics = $this->performanceAnalyticsService->getDashboardAnalytics();
            
            // Extract predictive data
            $predictiveData = [
                'concern_trends' => $analytics['concern_trends'] ?? [],
                'performance_metrics' => $analytics['performance_metrics'] ?? [],
                'workload_distribution' => $analytics['workload_distribution'] ?? [],
                'response_time_trends' => $analytics['response_time_trends'] ?? [],
                'escalation_patterns' => $analytics['escalation_patterns'] ?? [],
                'satisfaction_trends' => $analytics['satisfaction_trends'] ?? [],
            ];

            return response()->json([
                'success' => true,
                'data' => $predictiveData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get predictive data',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Store insights from N8N automation
     */
    public function storeInsights(Request $request): JsonResponse
    {
        try {
            $insights = $request->input('insights', []);
            $type = $request->input('type', 'general');
            $priority = $request->input('priority', 'normal');

            // TODO: Implement insight storage logic
            // For now, just log the insights
            Log::info('Insights stored from N8N automation', [
                'type' => $type,
                'priority' => $priority,
                'insights_count' => count($insights),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Insights stored successfully',
                'data' => [
                    'type' => $type,
                    'priority' => $priority,
                    'insights_count' => count($insights),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to store insights',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update predictions from N8N automation
     */
    public function updatePredictions(Request $request): JsonResponse
    {
        try {
            $predictions = $request->input('predictions', []);
            $model = $request->input('model', 'default');
            $accuracy = $request->input('accuracy', 0);

            // TODO: Implement prediction storage logic
            // For now, just log the predictions
            Log::info('Predictions updated from N8N automation', [
                'model' => $model,
                'accuracy' => $accuracy,
                'predictions_count' => count($predictions),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Predictions updated successfully',
                'data' => [
                    'model' => $model,
                    'accuracy' => $accuracy,
                    'predictions_count' => count($predictions),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update predictions',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
