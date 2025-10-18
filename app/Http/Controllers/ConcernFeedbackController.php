<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use App\Models\ConcernFeedback;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConcernFeedbackController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Submit feedback for a concern
     */
    public function store(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Validate that the user can provide feedback for this concern
            if ($concern->student_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only provide feedback for your own concerns',
                ], 403);
            }

            // Check if concern can receive feedback
            if (!$concern->canReceiveFeedback()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Feedback can only be provided for resolved concerns',
                ], 400);
            }

            // Check if feedback already exists
            $existingFeedback = ConcernFeedback::where('concern_id', $concern->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingFeedback) {
                return response()->json([
                    'success' => false,
                    'message' => 'Feedback has already been provided for this concern',
                ], 400);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'rating' => 'required|integer|min:1|max:5',
                'response_time_rating' => 'nullable|integer|min:1|max:5',
                'resolution_quality_rating' => 'nullable|integer|min:1|max:5',
                'staff_courtesy_rating' => 'nullable|integer|min:1|max:5',
                'communication_rating' => 'nullable|integer|min:1|max:5',
                'feedback_text' => 'nullable|string|max:2000',
                'suggestions' => 'nullable|string|max:1000',
                'would_recommend' => 'nullable|boolean',
                'is_anonymous' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();
            $data['concern_id'] = $concern->id;
            $data['user_id'] = $user->id;

            $feedback = ConcernFeedback::create($data);

            // Log the feedback submission
            $this->auditLogService->log($user, 'feedback_submitted', $concern, null, [
                'rating' => $feedback->rating,
                'concern_id' => $concern->id,
                'is_anonymous' => $feedback->is_anonymous,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Feedback submitted successfully',
                'data' => $feedback->load('user'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit feedback',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get feedback for a specific concern
     */
    public function show(Request $request, Concern $concern): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check permissions
            $canView = false;
            if ($concern->student_id === $user->id) {
                $canView = true; // Student can view their own feedback
            } elseif (in_array($user->role, ['admin', 'department_head']) && $concern->department_id === $user->department_id) {
                $canView = true; // Admin/Department head can view feedback for their department
            } elseif ($user->role === 'admin') {
                $canView = true; // Admin can view all feedback
            }

            if (!$canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view feedback',
                ], 403);
            }

            $feedback = $concern->feedback()->with('user')->get();

            return response()->json([
                'success' => true,
                'data' => $feedback,
                'summary' => [
                    'total_feedback' => $feedback->count(),
                    'average_rating' => $feedback->avg('rating'),
                    'rating_distribution' => $feedback->groupBy('rating')->map->count(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch feedback',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update feedback (only by the original submitter)
     */
    public function update(Request $request, Concern $concern, ConcernFeedback $feedback): JsonResponse
    {
        $user = auth()->user();

        try {
            // Check if user can update this feedback
            if ($feedback->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only update your own feedback',
                ], 403);
            }

            // Check if feedback is not too old (e.g., within 7 days)
            if ($feedback->created_at->diffInDays(now()) > 7) {
                return response()->json([
                    'success' => false,
                    'message' => 'Feedback cannot be updated after 7 days',
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'rating' => 'sometimes|integer|min:1|max:5',
                'response_time_rating' => 'nullable|integer|min:1|max:5',
                'resolution_quality_rating' => 'nullable|integer|min:1|max:5',
                'staff_courtesy_rating' => 'nullable|integer|min:1|max:5',
                'communication_rating' => 'nullable|integer|min:1|max:5',
                'feedback_text' => 'nullable|string|max:2000',
                'suggestions' => 'nullable|string|max:1000',
                'would_recommend' => 'nullable|boolean',
                'is_anonymous' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $oldData = $feedback->toArray();
            $feedback->update($validator->validated());

            // Log the feedback update
            $this->auditLogService->log($user, 'feedback_updated', $concern, $oldData, [
                'feedback_id' => $feedback->id,
                'rating' => $feedback->rating,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Feedback updated successfully',
                'data' => $feedback->load('user'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update feedback',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get feedback statistics for admin/department head
     */
    public function getStats(Request $request): JsonResponse
    {
        $user = auth()->user();

        try {
            // Only admin and department heads can view stats
            if (!in_array($user->role, ['admin', 'department_head'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view feedback statistics',
                ], 403);
            }

            $query = ConcernFeedback::query();

            // Filter by department if user is department head
            if ($user->role === 'department_head') {
                $query->whereHas('concern', function ($q) use ($user) {
                    $q->where('department_id', $user->department_id);
                });
            }

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->input('date_to'));
            }

            $feedback = $query->get();

            $stats = [
                'total_feedback' => $feedback->count(),
                'average_rating' => $feedback->avg('rating'),
                'rating_distribution' => $feedback->groupBy('rating')->map->count(),
                'category_ratings' => [
                    'response_time' => $feedback->avg('response_time_rating'),
                    'resolution_quality' => $feedback->avg('resolution_quality_rating'),
                    'staff_courtesy' => $feedback->avg('staff_courtesy_rating'),
                    'communication' => $feedback->avg('communication_rating'),
                ],
                'recommendation_rate' => $feedback->where('would_recommend', true)->count() / max($feedback->count(), 1) * 100,
                'feedback_with_text' => $feedback->whereNotNull('feedback_text')->count(),
                'anonymous_feedback' => $feedback->where('is_anonymous', true)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch feedback statistics',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get recent feedback for dashboard
     */
    public function getRecentFeedback(Request $request): JsonResponse
    {
        $user = auth()->user();

        try {
            // Only admin and department heads can view recent feedback
            if (!in_array($user->role, ['admin', 'department_head'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view recent feedback',
                ], 403);
            }

            $query = ConcernFeedback::with(['concern', 'user'])
                ->orderBy('created_at', 'desc')
                ->limit($request->input('limit', 10));

            // Filter by department if user is department head
            if ($user->role === 'department_head') {
                $query->whereHas('concern', function ($q) use ($user) {
                    $q->where('department_id', $user->department_id);
                });
            }

            $recentFeedback = $query->get();

            return response()->json([
                'success' => true,
                'data' => $recentFeedback,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent feedback',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
