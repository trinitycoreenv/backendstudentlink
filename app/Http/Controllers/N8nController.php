<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use App\Models\ConcernMessage;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class N8nController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle concern classification from n8n
     */
    public function classifyConcern(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'concern_id' => 'required|integer|exists:concerns,id',
                'urgency_level' => 'required|string|in:low,medium,high,critical',
                'confidence_score' => 'required|numeric|between:0,1',
                'reasoning' => 'nullable|string',
                'ai_model' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $concern = Concern::findOrFail($request->input('concern_id'));
            $urgencyLevel = $request->input('urgency_level');
            $confidenceScore = $request->input('confidence_score');
            $reasoning = $request->input('reasoning');
            $aiModel = $request->input('ai_model', 'n8n-ai-classifier');

            // Update concern priority based on AI classification
            $priorityMapping = [
                'low' => 'low',
                'medium' => 'medium', 
                'high' => 'high',
                'critical' => 'urgent'
            ];

            $newPriority = $priorityMapping[$urgencyLevel] ?? 'medium';

            // Only update if confidence is high enough (>= 0.7)
            if ($confidenceScore >= 0.7) {
                $concern->update([
                    'priority' => $newPriority,
                    'metadata' => array_merge($concern->metadata ?? [], [
                        'ai_classification' => [
                            'urgency_level' => $urgencyLevel,
                            'confidence_score' => $confidenceScore,
                            'reasoning' => $reasoning,
                            'ai_model' => $aiModel,
                            'classified_at' => now()->toISOString(),
                        ]
                    ])
                ]);

                // Log the classification
                Log::info('Concern classified by AI', [
                    'concern_id' => $concern->id,
                    'urgency_level' => $urgencyLevel,
                    'confidence_score' => $confidenceScore,
                    'new_priority' => $newPriority,
                ]);

                // Send notification to assigned staff if priority is high/urgent
                if (in_array($newPriority, ['high', 'urgent'])) {
                    $this->notifyHighPriorityConcern($concern, $urgencyLevel, $confidenceScore);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Concern classified successfully',
                    'data' => [
                        'concern_id' => $concern->id,
                        'new_priority' => $newPriority,
                        'urgency_level' => $urgencyLevel,
                        'confidence_score' => $confidenceScore,
                    ]
                ]);
            } else {
                // Low confidence - log for manual review
                Log::warning('Low confidence AI classification - manual review needed', [
                    'concern_id' => $concern->id,
                    'urgency_level' => $urgencyLevel,
                    'confidence_score' => $confidenceScore,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Classification received but confidence too low for automatic update',
                    'data' => [
                        'concern_id' => $concern->id,
                        'urgency_level' => $urgencyLevel,
                        'confidence_score' => $confidenceScore,
                        'requires_manual_review' => true,
                    ]
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to process AI concern classification', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process concern classification',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Handle auto-reply to FAQs from n8n
     */
    public function handleAutoReply(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'concern_id' => 'required|integer|exists:concerns,id',
                'auto_reply' => 'required|string',
                'confidence_score' => 'required|numeric|between:0,1',
                'faq_matched' => 'nullable|string',
                'ai_model' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $concern = Concern::findOrFail($request->input('concern_id'));
            $autoReply = $request->input('auto_reply');
            $confidenceScore = $request->input('confidence_score');
            $faqMatched = $request->input('faq_matched');
            $aiModel = $request->input('ai_model', 'n8n-faq-responder');

            // Only send auto-reply if confidence is high enough (>= 0.8)
            if ($confidenceScore >= 0.8) {
                // Create AI-generated message
                $message = ConcernMessage::create([
                    'concern_id' => $concern->id,
                    'author_id' => 1, // System user ID
                    'message' => $autoReply,
                    'message_type' => 'text',
                    'is_ai_generated' => true,
                    'metadata' => [
                        'ai_auto_reply' => [
                            'confidence_score' => $confidenceScore,
                            'faq_matched' => $faqMatched,
                            'ai_model' => $aiModel,
                            'generated_at' => now()->toISOString(),
                        ]
                    ],
                    'delivered_at' => now(),
                ]);

                // Update concern status to indicate AI response
                $concern->update([
                    'status' => 'ai_responded',
                    'metadata' => array_merge($concern->metadata ?? [], [
                        'ai_auto_reply' => [
                            'message_id' => $message->id,
                            'confidence_score' => $confidenceScore,
                            'faq_matched' => $faqMatched,
                            'responded_at' => now()->toISOString(),
                        ]
                    ])
                ]);

                // Send notification to student about AI response
                $this->notifyStudentAboutAutoReply($concern, $message);

                Log::info('Auto-reply sent to concern', [
                    'concern_id' => $concern->id,
                    'message_id' => $message->id,
                    'confidence_score' => $confidenceScore,
                    'faq_matched' => $faqMatched,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Auto-reply sent successfully',
                    'data' => [
                        'concern_id' => $concern->id,
                        'message_id' => $message->id,
                        'confidence_score' => $confidenceScore,
                    ]
                ]);
            } else {
                // Low confidence - log for manual review
                Log::info('Low confidence auto-reply - manual review needed', [
                    'concern_id' => $concern->id,
                    'confidence_score' => $confidenceScore,
                    'faq_matched' => $faqMatched,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Auto-reply generated but confidence too low for automatic sending',
                    'data' => [
                        'concern_id' => $concern->id,
                        'confidence_score' => $confidenceScore,
                        'requires_manual_review' => true,
                    ]
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to process auto-reply', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process auto-reply',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Handle assignment reminders from n8n
     */
    public function handleAssignmentReminder(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'concern_id' => 'required|integer|exists:concerns,id',
                'reminder_type' => 'required|string|in:deadline_approaching,overdue,escalation',
                'message' => 'required|string',
                'recipients' => 'required|array',
                'recipients.*' => 'integer|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $concern = Concern::findOrFail($request->input('concern_id'));
            $reminderType = $request->input('reminder_type');
            $message = $request->input('message');
            $recipients = $request->input('recipients');

            // Send notifications to recipients
            foreach ($recipients as $userId) {
                $user = User::find($userId);
                if ($user) {
                    $this->notificationService->sendNotification($user, [
                        'type' => 'concern_reminder',
                        'title' => 'Concern Reminder',
                        'message' => $message,
                        'data' => [
                            'concern_id' => $concern->id,
                            'reminder_type' => $reminderType,
                            'concern_subject' => $concern->subject,
                        ]
                    ]);
                }
            }

            Log::info('Assignment reminder sent', [
                'concern_id' => $concern->id,
                'reminder_type' => $reminderType,
                'recipients_count' => count($recipients),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Assignment reminder sent successfully',
                'data' => [
                    'concern_id' => $concern->id,
                    'reminder_type' => $reminderType,
                    'recipients_count' => count($recipients),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send assignment reminder', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send assignment reminder',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Notify staff about high priority concern
     */
    private function notifyHighPriorityConcern(Concern $concern, string $urgencyLevel, float $confidenceScore): void
    {
        $assignedUser = $concern->assignedTo;
        if ($assignedUser) {
            $this->notificationService->sendNotification($assignedUser, [
                'type' => 'high_priority_concern',
                'title' => 'High Priority Concern Detected',
                'message' => "Concern #{$concern->reference_number} has been classified as {$urgencyLevel} priority by AI (confidence: " . round($confidenceScore * 100) . "%)",
                'data' => [
                    'concern_id' => $concern->id,
                    'urgency_level' => $urgencyLevel,
                    'confidence_score' => $confidenceScore,
                ]
            ]);
        }
    }

    /**
     * Notify student about auto-reply
     */
    private function notifyStudentAboutAutoReply(Concern $concern, ConcernMessage $message): void
    {
        $student = $concern->student;
        if ($student) {
            $this->notificationService->sendNotification($student, [
                'type' => 'ai_auto_reply',
                'title' => 'AI Assistant Response',
                'message' => "Our AI assistant has responded to your concern #{$concern->reference_number}",
                'data' => [
                    'concern_id' => $concern->id,
                    'message_id' => $message->id,
                ]
            ]);
        }
    }
}
