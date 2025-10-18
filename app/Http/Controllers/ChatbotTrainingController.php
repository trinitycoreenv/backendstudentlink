<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\AuditLogService;

class ChatbotTrainingController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Get training data
     */
    public function getTrainingData(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $type = $request->get('type', 'all');
        $context = $request->get('context', 'all');

        try {
            // In a real implementation, this would fetch from a knowledge base table
            $trainingData = $this->getStoredTrainingData($type, $context);

            return response()->json([
                'success' => true,
                'data' => $trainingData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch training data',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Add new training data
     */
    public function addTrainingData(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string|max:2000',
            'type' => 'required|string|in:faq,conversation,knowledge_base,department_info',
            'context' => 'nullable|string|max:100',
            'priority' => 'nullable|string|in:low,medium,high',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50'
        ]);

        try {
            $trainingData = [
                'question' => $request->input('question'),
                'answer' => $request->input('answer'),
                'type' => $request->input('type'),
                'context' => $request->input('context', 'general'),
                'priority' => $request->input('priority', 'medium'),
                'tags' => $request->input('tags', []),
                'created_by' => $user->id,
                'created_at' => now()
            ];

            // Store in knowledge base (simulated)
            $this->storeTrainingData($trainingData);

            // Log the activity
            $this->auditLogService->log($user, 'chatbot_training_add', null, null, [
                'question' => $request->input('question'),
                'type' => $request->input('type'),
                'context' => $request->input('context')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Training data added successfully',
                'data' => $trainingData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add training data',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update training data
     */
    public function updateTrainingData(Request $request, $id): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'question' => 'sometimes|string|max:500',
            'answer' => 'sometimes|string|max:2000',
            'type' => 'sometimes|string|in:faq,conversation,knowledge_base,department_info',
            'context' => 'nullable|string|max:100',
            'priority' => 'nullable|string|in:low,medium,high',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50'
        ]);

        try {
            // In a real implementation, this would update the database
            $updatedData = $request->only(['question', 'answer', 'type', 'context', 'priority', 'tags']);
            $updatedData['updated_by'] = $user->id;
            $updatedData['updated_at'] = now();

            $this->updateStoredTrainingData($id, $updatedData);

            // Log the activity
            $this->auditLogService->log($user, 'chatbot_training_update', $id, null, $updatedData);

            return response()->json([
                'success' => true,
                'message' => 'Training data updated successfully',
                'data' => $updatedData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update training data',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Delete training data
     */
    public function deleteTrainingData($id): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            // In a real implementation, this would delete from database
            $this->deleteStoredTrainingData($id);

            // Log the activity
            $this->auditLogService->log($user, 'chatbot_training_delete', $id, null, []);

            return response()->json([
                'success' => true,
                'message' => 'Training data deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete training data',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Bulk import training data
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'training_data' => 'required|array|min:1|max:100',
            'training_data.*.question' => 'required|string|max:500',
            'training_data.*.answer' => 'required|string|max:2000',
            'training_data.*.type' => 'required|string|in:faq,conversation,knowledge_base,department_info',
            'training_data.*.context' => 'nullable|string|max:100',
            'training_data.*.priority' => 'nullable|string|in:low,medium,high',
            'training_data.*.tags' => 'nullable|array',
            'training_data.*.tags.*' => 'string|max:50'
        ]);

        try {
            $importedCount = 0;
            $errors = [];

            foreach ($request->input('training_data') as $index => $data) {
                try {
                    $trainingData = [
                        'question' => $data['question'],
                        'answer' => $data['answer'],
                        'type' => $data['type'],
                        'context' => $data['context'] ?? 'general',
                        'priority' => $data['priority'] ?? 'medium',
                        'tags' => $data['tags'] ?? [],
                        'created_by' => $user->id,
                        'created_at' => now()
                    ];

                    $this->storeTrainingData($trainingData);
                    $importedCount++;

                } catch (\Exception $e) {
                    $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                }
            }

            // Log the activity
            $this->auditLogService->log($user, 'chatbot_training_bulk_import', null, null, [
                'imported_count' => $importedCount,
                'total_count' => count($request->input('training_data')),
                'errors' => $errors
            ]);

            return response()->json([
                'success' => true,
                'message' => "Bulk import completed. {$importedCount} items imported successfully.",
                'data' => [
                    'imported_count' => $importedCount,
                    'total_count' => count($request->input('training_data')),
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import training data',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Test chatbot with training data
     */
    public function testChatbot(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'test_questions' => 'required|array|min:1|max:10',
            'test_questions.*' => 'required|string|max:500'
        ]);

        try {
            $results = [];
            $huggingFaceService = app(\App\Services\HuggingFaceService::class);

            foreach ($request->input('test_questions') as $index => $question) {
                $messages = [
                    ['role' => 'user', 'content' => $question]
                ];

                $startTime = microtime(true);
                $response = $huggingFaceService->getChatCompletion($messages, ['context' => 'student_support']);
                $endTime = microtime(true);

                $results[] = [
                    'question' => $question,
                    'response' => $response['content'],
                    'model' => $response['model'] ?? 'unknown',
                    'response_time' => round(($endTime - $startTime) * 1000, 2),
                    'success' => true
                ];

                // Add delay to avoid rate limiting
                usleep(200000); // 0.2 seconds
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to test chatbot',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get training analytics
     */
    public function getTrainingAnalytics(): JsonResponse
    {
        $user = auth()->user();
        
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            // In a real implementation, this would fetch from database
            $analytics = [
                'total_training_items' => 25, // Simulated
                'items_by_type' => [
                    'faq' => 11,
                    'conversation' => 4,
                    'knowledge_base' => 6,
                    'department_info' => 4
                ],
                'items_by_context' => [
                    'student_support' => 8,
                    'academic_support' => 6,
                    'technical_support' => 4,
                    'enrollment_support' => 3,
                    'general' => 4
                ],
                'recent_activity' => [
                    'last_training_update' => now()->subHours(2)->toISOString(),
                    'items_added_today' => 3,
                    'items_updated_today' => 1
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch training analytics',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Helper methods (simulated database operations)
    private function getStoredTrainingData($type, $context)
    {
        // In a real implementation, this would query the database
        return [
            'items' => [],
            'total' => 0,
            'filters' => ['type' => $type, 'context' => $context]
        ];
    }

    private function storeTrainingData($data)
    {
        // In a real implementation, this would insert into database
        Log::info('Training data stored', $data);
    }

    private function updateStoredTrainingData($id, $data)
    {
        // In a real implementation, this would update database
        Log::info('Training data updated', ['id' => $id, 'data' => $data]);
    }

    private function deleteStoredTrainingData($id)
    {
        // In a real implementation, this would delete from database
        Log::info('Training data deleted', ['id' => $id]);
    }
}
