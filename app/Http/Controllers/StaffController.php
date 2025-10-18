<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Department;
use App\Models\Concern;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Get all staff members for the current department
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAdmin() && !$user->isDepartmentHead()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin or department head role required.'
                ], 403);
            }

            // Department heads can only see their own department staff
            $departmentId = $user->isAdmin() && $request->has('department_id') 
                ? $request->input('department_id') 
                : $user->department_id;

            $query = User::whereIn('role', ['staff', 'department_head'])
                ->where('department_id', $departmentId)
                ->where('is_active', true)
                ->with(['department', 'assignedConcerns' => function($query) {
                    $query->whereIn('status', ['pending', 'in_progress'])
                          ->whereNull('archived_at'); // Exclude archived concerns from workload
                }]);

            // Filter by role
            if ($request->has('role')) {
                $query->where('role', $request->input('role'));
            }

            $staff = $query->get()->map(function($staffMember) {
                $workload = $staffMember->getWorkloadMetrics();
                return [
                    'id' => $staffMember->id,
                    'name' => $staffMember->name,
                    'email' => $staffMember->email,
                    'role' => $staffMember->role,
                    'department' => $staffMember->department,
                    'phone' => $staffMember->phone,
                    'employee_id' => $staffMember->employee_id,
                    'is_active' => $staffMember->is_active,
                    'last_login_at' => $staffMember->last_login_at,
                    'workload' => $workload,
                    'created_at' => $staffMember->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $staff,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch staff members',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create new staff member for the current department
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAdmin() && !$user->isDepartmentHead()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin or department head role required.'
                ], 403);
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
                'role' => ['required', Rule::in(['staff'])], // Only allow staff role, not department_head
                'phone' => 'nullable|string|max:20',
                'employee_id' => 'nullable|string|max:20|unique:users,employee_id',
            ]);

            // Department heads can only create staff in their own department
            $departmentId = $user->isAdmin() && $request->has('department_id') 
                ? $request->input('department_id') 
                : $user->department_id;

            $staffMember = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'role' => $request->input('role'),
                'department_id' => $departmentId,
                'phone' => $request->input('phone'),
                'employee_id' => $request->input('employee_id'),
                'is_active' => true,
                'preferences' => [
                    'theme' => 'light',
                    'language' => 'en',
                    'notifications' => [
                        'email' => true,
                        'push' => true,
                        'sms' => false
                    ]
                ],
            ]);

            // Log the creation
            $this->auditLogService->log($user, 'create', $staffMember, null, [
                'name' => $staffMember->name,
                'email' => $staffMember->email,
                'role' => $staffMember->role,
                'department_id' => $staffMember->department_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Staff member created successfully',
                'data' => $staffMember->load('department'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create staff member',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get staff member details with workload
     */
    public function show(User $staff): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAdmin() && !$user->isDepartmentHead()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin or department head role required.'
                ], 403);
            }

            // Department heads can only view their department staff
            if ($user->isDepartmentHead() && $staff->department_id !== $user->department_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Can only view staff from your department.'
                ], 403);
            }

            if (!$staff->canHandleConcerns()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a staff member or department head.'
                ], 400);
            }

            $workload = $staff->getWorkloadMetrics();
            $recentConcerns = $staff->assignedConcerns()
                ->with(['student', 'department'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'email' => $staff->email,
                    'role' => $staff->role,
                    'department' => $staff->department,
                    'phone' => $staff->phone,
                    'employee_id' => $staff->employee_id,
                    'is_active' => $staff->is_active,
                    'last_login_at' => $staff->last_login_at,
                    'workload' => $workload,
                    'recent_concerns' => $recentConcerns,
                    'created_at' => $staff->created_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch staff member details',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update staff member
     */
    public function update(Request $request, User $staff): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.'
                ], 403);
            }

            if (!$staff->canHandleConcerns()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a staff member or department head.'
                ], 400);
            }

            $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($staff->id)],
                'role' => ['sometimes', 'required', Rule::in(['staff', 'department_head'])],
                'department_id' => 'sometimes|required|exists:departments,id',
                'phone' => 'nullable|string|max:20',
                'employee_id' => ['nullable', 'string', 'max:20', Rule::unique('users', 'employee_id')->ignore($staff->id)],
                'is_active' => 'sometimes|boolean',
            ]);

            $oldData = $staff->toArray();
            $staff->update($request->only([
                'name', 'email', 'role', 'department_id', 'phone', 'employee_id', 'is_active'
            ]));

            // Log the update
            $this->auditLogService->log($user, 'update', $staff, $oldData, $staff->toArray());

            return response()->json([
                'success' => true,
                'message' => 'Staff member updated successfully',
                'data' => $staff->load('department'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update staff member',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get available staff for assignment
     */
    public function getAvailableStaff(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->canHandleConcerns()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Staff, department head, or admin role required.'
                ], 403);
            }

            $departmentId = $request->input('department_id');
            $maxWorkload = $request->input('max_workload', 10);

            $query = User::whereIn('role', ['staff', 'department_head'])
                ->where('is_active', true)
                ->with(['department', 'assignedConcerns' => function($query) {
                    $query->whereIn('status', ['pending', 'in_progress'])
                          ->whereNull('archived_at'); // Exclude archived concerns from workload
                }]);

            // Filter by department if specified
            if ($departmentId) {
                $query->where('department_id', $departmentId);
            }

            $staff = $query->get()->filter(function($staffMember) use ($maxWorkload) {
                $workload = $staffMember->getWorkloadMetrics();
                return $workload['total_assigned'] < $maxWorkload;
            })->map(function($staffMember) {
                $workload = $staffMember->getWorkloadMetrics();
                return [
                    'id' => $staffMember->id,
                    'name' => $staffMember->name,
                    'email' => $staffMember->email,
                    'role' => $staffMember->role,
                    'department' => $staffMember->department,
                    'employee_id' => $staffMember->employee_id,
                    'workload' => $workload,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $staff,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available staff',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get staff workload statistics
     */
    public function getWorkloadStats(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAdmin() && !$user->isDepartmentHead()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin or department head role required.'
                ], 403);
            }

            $departmentId = $request->input('department_id');
            
            $query = User::whereIn('role', ['staff', 'department_head'])
                ->where('is_active', true);

            // Filter by department if user is department head
            if ($user->isDepartmentHead()) {
                $query->where('department_id', $user->department_id);
            } elseif ($departmentId) {
                $query->where('department_id', $departmentId);
            }

            $staff = $query->with(['assignedConcerns'])->get();

            $stats = [
                'total_staff' => $staff->count(),
                'total_concerns' => $staff->sum(function($s) { return $s->assignedConcerns->count(); }),
                'average_workload' => $staff->avg(function($s) { return $s->assignedConcerns->count(); }),
                'overloaded_staff' => $staff->filter(function($s) { 
                    return $s->assignedConcerns->count() > 10; 
                })->count(),
                'available_staff' => $staff->filter(function($s) { 
                    return $s->assignedConcerns->count() < 5; 
                })->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch workload statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get concerns assigned to the current staff member
     */
    public function getMyConcerns(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if ($user->role !== 'staff') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Staff role required.'
                ], 403);
            }
            
            $concerns = Concern::where('assigned_to', $user->id)
                ->notArchived() // Filter out archived concerns
                ->whereNotIn('status', ['student_confirmed', 'closed']) // Only filter out confirmed/closed concerns
                ->with(['student', 'department', 'chatRoom'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $concerns,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get assigned concerns',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get archived concerns assigned to the current staff member
     */
    public function getMyArchivedConcerns(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if ($user->role !== 'staff') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Staff role required.'
                ], 403);
            }
            
            $concerns = Concern::where('assigned_to', $user->id)
                ->archived() // Only show archived concerns
                ->with(['student', 'department', 'chatRoom'])
                ->orderBy('archived_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $concerns,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get archived concerns',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function getMyDashboardStats(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if ($user->role !== 'staff') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Staff role required.'
                ], 403);
            }
            
            $workloadMetrics = $user->getWorkloadMetrics();
            
            return response()->json([
                'success' => true,
                'data' => $workloadMetrics,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get dashboard stats',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update concern status (staff only)
     */
    public function updateConcernStatus(Request $request, $concernId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if ($user->role !== 'staff') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Staff role required.'
                ], 403);
            }
            
            $concern = Concern::findOrFail($concernId);
            
            // Check if staff is assigned to this concern
            if ($concern->assigned_to !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not assigned to this concern'
                ], 403);
            }
            
            $validated = $request->validate([
                'status' => 'required|in:pending,in_progress,resolved',
                'resolution_notes' => 'nullable|string|max:1000'
            ]);
            
            $oldStatus = $concern->status;
            $concern->update([
                'status' => $validated['status'],
                'resolution_notes' => $validated['resolution_notes'] ?? $concern->resolution_notes,
                'resolved_at' => $validated['status'] === 'resolved' ? now() : null,
                'resolved_by' => $validated['status'] === 'resolved' ? $user->id : $concern->resolved_by,
            ]);
            
            // Log the status update
            $this->auditLogService->log(
                'update_concern_status',
                'concern',
                $concern->id,
                ['status' => $oldStatus],
                ['status' => $validated['status']]
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Concern status updated successfully',
                'data' => $concern->fresh(['student', 'department'])
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update concern status',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Add message to concern (staff only)
     */
    public function addConcernMessage(Request $request, $concernId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if ($user->role !== 'staff') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Staff role required.'
                ], 403);
            }
            
            $concern = Concern::findOrFail($concernId);
            
            // Check if staff is assigned to this concern
            if ($concern->assigned_to !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not assigned to this concern'
                ], 403);
            }
            
            $validated = $request->validate([
                'message' => 'required|string|max:2000',
                'is_internal' => 'boolean'
            ]);
            
            $message = \App\Models\ConcernMessage::create([
                'concern_id' => $concern->id,
                'author_id' => $user->id,
                'message' => $validated['message'],
                'type' => 'staff_response',
                'message_type' => 'text',
                'is_internal' => $validated['is_internal'] ?? false,
                'delivered_at' => now(),
            ]);
            
            // Update concern last activity
            $concern->update(['last_activity_at' => now()]);
            
            // Log the message addition
            $this->auditLogService->log($user, 'create', $message, null, [
                'concern_id' => $concern->id,
                'message' => $validated['message'],
                'is_internal' => $validated['is_internal'] ?? false
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Message added successfully',
                'data' => $message->load('author')
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add message',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get cross-department available staff (for N8N automation)
     */
    public function getCrossDepartmentAvailable(): JsonResponse
    {
        try {
            $availableStaff = User::where('role', 'staff')
                ->where('can_handle_cross_department', true)
                ->where('is_active', true)
                ->with(['department'])
                ->get()
                ->map(function ($staff) {
                    return [
                        'id' => $staff->id,
                        'name' => $staff->name,
                        'email' => $staff->email,
                        'department_id' => $staff->department_id,
                        'department_name' => $staff->department->name ?? 'Unknown',
                        'current_workload' => $staff->concerns()->whereNull('archived_at')->count(),
                        'response_time_avg' => $staff->getAverageResponseTime(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $availableStaff
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get cross-department available staff',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
