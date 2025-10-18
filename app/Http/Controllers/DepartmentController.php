<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments
     */
    public function index(): JsonResponse
    {
        try {
            $departments = Department::where('is_active', true)
                ->select('id', 'name', 'code', 'description', 'type')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $departments
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch departments: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created department
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:10|unique:departments',
                'description' => 'nullable|string',
                'type' => 'required|in:academic,administrative,support',
                'contact_info' => 'nullable|array'
            ]);

            $department = Department::create($request->all());

            return response()->json([
                'success' => true,
                'data' => $department,
                'message' => 'Department created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create department: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified department
     */
    public function show(Department $department): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $department
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch department: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified department
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'code' => 'sometimes|required|string|max:10|unique:departments,code,' . $department->id,
                'description' => 'nullable|string',
                'type' => 'sometimes|required|in:academic,administrative,support',
                'contact_info' => 'nullable|array',
                'is_active' => 'sometimes|boolean'
            ]);

            $department->update($request->all());

            return response()->json([
                'success' => true,
                'data' => $department,
                'message' => 'Department updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update department: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified department
     */
    public function destroy(Department $department): JsonResponse
    {
        try {
            $department->delete();

            return response()->json([
                'success' => true,
                'message' => 'Department deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete department: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get department statistics
     */
    public function getStats(Department $department): JsonResponse
    {
        try {
            $stats = [
                'total_concerns' => $department->concerns()->count(),
                'pending_concerns' => $department->concerns()->where('status', 'pending')->count(),
                'resolved_concerns' => $department->concerns()->where('status', 'resolved')->count(),
                'total_users' => $department->users()->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch department stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get department concerns
     */
    public function getConcerns(Department $department): JsonResponse
    {
        try {
            $concerns = $department->concerns()
                ->with(['student', 'messages'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $concerns
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch department concerns: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get department users
     */
    public function getUsers(Department $department): JsonResponse
    {
        try {
            $users = $department->users()
                ->select('id', 'name', 'email', 'role', 'is_active')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch department users: ' . $e->getMessage()
            ], 500);
        }
    }
}
