<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use App\Models\User;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DepartmentDashboardController extends Controller
{
    /**
     * Get department dashboard statistics
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || $user->role !== 'department_head') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Department head role required.'
                ], 403);
            }

            $departmentId = $user->department_id;
            $department = Department::find($departmentId);

            if (!$department) {
                return response()->json([
                    'success' => false,
                    'message' => 'Department not found'
                ], 404);
            }

            // Get date range (default: last 30 days)
            $startDate = $request->input('start_date', Carbon::now()->subDays(30)->format('Y-m-d'));
            $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));

            // Total concerns for this department
            $totalConcerns = Concern::where('department_id', $departmentId)->count();

            // Concerns by status
            $concernsByStatus = Concern::where('department_id', $departmentId)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Concerns by priority
            $concernsByPriority = Concern::where('department_id', $departmentId)
                ->select('priority', DB::raw('count(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            // Concerns by type
            $concernsByType = Concern::where('department_id', $departmentId)
                ->select('type', DB::raw('count(*) as count'))
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray();

            // Recent concerns (last 7 days)
            $recentConcerns = Concern::where('department_id', $departmentId)
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->count();

            // Average resolution time (in days)
            $avgResolutionTime = Concern::where('department_id', $departmentId)
                ->where('status', 'resolved')
                ->whereNotNull('resolved_at')
                ->selectRaw('AVG(DATEDIFF(resolved_at, created_at)) as avg_days')
                ->value('avg_days');

            // Monthly trend (last 6 months)
            $monthlyTrend = Concern::where('department_id', $departmentId)
                ->where('created_at', '>=', Carbon::now()->subMonths(6))
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->pluck('count', 'month')
                ->toArray();

            // Department users performance (if any users assigned to this department)
            $departmentUsersPerformance = User::where('department_id', $departmentId)
                ->where('role', 'department_head')
                ->withCount(['assignedConcerns as total_concerns', 'assignedConcerns as resolved_concerns' => function($query) {
                    $query->where('status', 'resolved');
                }])
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'total_concerns' => $user->total_concerns,
                        'resolved_concerns' => $user->resolved_concerns,
                        'resolution_rate' => $user->total_concerns > 0 
                            ? round(($user->resolved_concerns / $user->total_concerns) * 100, 1) 
                            : 0
                    ];
                });

            // Recent activity (last 10 concerns)
            $recentActivity = Concern::where('department_id', $departmentId)
                ->with(['student', 'assignedTo'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function($concern) {
                    return [
                        'id' => $concern->id,
                        'reference_number' => $concern->reference_number,
                        'subject' => $concern->subject,
                        'type' => $concern->type,
                        'priority' => $concern->priority,
                        'status' => $concern->status,
                        'student_name' => $concern->is_anonymous ? 'Anonymous Student' : $concern->student->name,
                        'assigned_to' => $concern->assignedTo ? $concern->assignedTo->name : null,
                        'created_at' => $concern->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $concern->updated_at->format('Y-m-d H:i:s'),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'department' => [
                        'id' => $department->id,
                        'name' => $department->name,
                        'description' => $department->description,
                    ],
                    'overview' => [
                        'total_concerns' => $totalConcerns,
                        'recent_concerns' => $recentConcerns,
                        'avg_resolution_time_days' => round($avgResolutionTime ?? 0, 1),
                    ],
                    'statistics' => [
                        'by_status' => $concernsByStatus,
                        'by_priority' => $concernsByPriority,
                        'by_type' => $concernsByType,
                    ],
                    'trends' => [
                        'monthly' => $monthlyTrend,
                    ],
                    'department_users_performance' => $departmentUsersPerformance,
                    'recent_activity' => $recentActivity,
                    'date_range' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get department concerns with filtering
     */
    public function getDepartmentConcerns(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || $user->role !== 'department_head') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Department head role required.'
                ], 403);
            }

            $departmentId = $user->department_id;
            $perPage = $request->input('per_page', 15);
            $page = $request->input('page', 1);

            $query = Concern::where('department_id', $departmentId)
                ->with(['student', 'assignedTo']);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('priority')) {
                $query->where('priority', $request->input('priority'));
            }

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            if ($request->filled('assigned_to')) {
                $query->where('assigned_to', $request->input('assigned_to'));
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            }

            // Search
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->where('subject', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('reference_number', 'like', "%{$search}%");
                });
            }

            // Sort
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $concerns = $query->paginate($perPage, ['*'], 'page', $page);

            // Transform the data
            $concerns->getCollection()->transform(function($concern) {
                return [
                    'id' => $concern->id,
                    'reference_number' => $concern->reference_number,
                    'subject' => $concern->subject,
                    'description' => $concern->description,
                    'type' => $concern->type,
                    'priority' => $concern->priority,
                    'status' => $concern->status,
                    'is_anonymous' => $concern->is_anonymous,
                    'student_name' => $concern->is_anonymous ? 'Anonymous Student' : $concern->student->name,
                    'assigned_to' => $concern->assignedTo ? [
                        'id' => $concern->assignedTo->id,
                        'name' => $concern->assignedTo->name,
                    ] : null,
                    'created_at' => $concern->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $concern->updated_at->format('Y-m-d H:i:s'),
                    'resolved_at' => $concern->resolved_at ? $concern->resolved_at->format('Y-m-d H:i:s') : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $concerns,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch department concerns',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get department users
     */
    public function getDepartmentUsers(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || $user->role !== 'department_head') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Department head role required.'
                ], 403);
            }

            $departmentId = $user->department_id;

            $departmentUsers = User::where('department_id', $departmentId)
                ->whereIn('role', ['student', 'department_head'])
                ->where('is_active', true)
                ->select('id', 'name', 'email', 'role', 'created_at')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $departmentUsers,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch department users',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}