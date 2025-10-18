<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class N8nWorkflowService
{
    protected string $webhookUrl;
    protected ?string $apiKey;
    protected bool $enabled;

    public function __construct()
    {
        $this->webhookUrl = Config::get('n8n.webhook_url');
        $this->apiKey = Config::get('n8n.api_key');
        $this->enabled = Config::get('n8n.enabled', true);
    }

    /**
     * Trigger concern classification workflow
     */
    public function triggerConcernClassification(Concern $concern): bool
    {
        if (!$this->enabled || !Config::get('n8n.workflows.concern_classification.enabled')) {
            return false;
        }

        try {
            $webhookPath = Config::get('n8n.workflows.concern_classification.webhook_path');
            $url = rtrim($this->webhookUrl, '/') . '/' . $webhookPath;

            $payload = [
                'concern_id' => $concern->id,
                'subject' => $concern->subject,
                'description' => $concern->description,
                'type' => $concern->type,
                'department_id' => $concern->department_id,
                'student_id' => $concern->student_id,
                'created_at' => $concern->created_at->toISOString(),
                'metadata' => $concern->metadata ?? [],
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->withOptions([
                'verify' => false, // Disable SSL verification for ngrok
            ])->post($url, $payload);

            if ($response->successful()) {
                Log::info('N8N concern classification triggered successfully', [
                    'concern_id' => $concern->id,
                    'response_status' => $response->status(),
                ]);
                return true;
            } else {
                Log::error('N8N concern classification failed', [
                    'concern_id' => $concern->id,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('N8N concern classification exception', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Trigger auto-reply FAQ workflow
     */
    public function triggerAutoReplyFAQ(Concern $concern): bool
    {
        if (!$this->enabled || !Config::get('n8n.workflows.auto_reply_faq.enabled')) {
            return false;
        }

        try {
            $webhookPath = Config::get('n8n.workflows.auto_reply_faq.webhook_path');
            $url = rtrim($this->webhookUrl, '/') . '/' . $webhookPath;

            $payload = [
                'concern_id' => $concern->id,
                'subject' => $concern->subject,
                'description' => $concern->description,
                'type' => $concern->type,
                'department_id' => $concern->department_id,
                'student_id' => $concern->student_id,
                'student_email' => $concern->student->email ?? null,
                'created_at' => $concern->created_at->toISOString(),
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->withOptions([
                'verify' => false, // Disable SSL verification for ngrok
            ])->post($url, $payload);

            if ($response->successful()) {
                Log::info('N8N auto-reply FAQ triggered successfully', [
                    'concern_id' => $concern->id,
                    'response_status' => $response->status(),
                ]);
                return true;
            } else {
                Log::error('N8N auto-reply FAQ failed', [
                    'concern_id' => $concern->id,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('N8N auto-reply FAQ exception', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Trigger assignment reminder workflow
     */
    public function triggerAssignmentReminder(Concern $concern, string $reminderType = 'deadline_approaching'): bool
    {
        if (!$this->enabled || !Config::get('n8n.workflows.assignment_reminder.enabled')) {
            return false;
        }

        try {
            $webhookPath = Config::get('n8n.workflows.assignment_reminder.webhook_path');
            $url = rtrim($this->webhookUrl, '/') . '/' . $webhookPath;

            $assignedUser = $concern->assignedTo;
            if (!$assignedUser) {
                Log::warning('Cannot send assignment reminder - no assigned user', [
                    'concern_id' => $concern->id,
                ]);
                return false;
            }

            $payload = [
                'concern_id' => $concern->id,
                'subject' => $concern->subject,
                'description' => $concern->description,
                'priority' => $concern->priority,
                'status' => $concern->status,
                'assigned_to' => $assignedUser->id,
                'assigned_user_email' => $assignedUser->email,
                'assigned_user_phone' => $assignedUser->phone ?? null,
                'reminder_type' => $reminderType,
                'created_at' => $concern->created_at->toISOString(),
                'deadline' => $concern->created_at->addDays(7)->toISOString(), // Default 7-day deadline
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->withOptions([
                'verify' => false, // Disable SSL verification for ngrok
            ])->post($url, $payload);

            if ($response->successful()) {
                Log::info('N8N assignment reminder triggered successfully', [
                    'concern_id' => $concern->id,
                    'reminder_type' => $reminderType,
                    'assigned_to' => $assignedUser->id,
                    'response_status' => $response->status(),
                ]);
                return true;
            } else {
                Log::error('N8N assignment reminder failed', [
                    'concern_id' => $concern->id,
                    'reminder_type' => $reminderType,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('N8N assignment reminder exception', [
                'concern_id' => $concern->id,
                'reminder_type' => $reminderType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check n8n health status
     */
    public function checkHealth(): array
    {
        try {
            $url = rtrim($this->webhookUrl, '/') . '/health';
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->withOptions([
                'verify' => false, // Disable SSL verification for ngrok
            ])->get($url);

            return [
                'status' => $response->successful() ? 'healthy' : 'unhealthy',
                'response_code' => $response->status(),
                'response_time' => $response->transferStats?->getHandlerStat('total_time') ?? null,
                'last_check' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'last_check' => now()->toISOString(),
            ];
        }
    }

    /**
     * Get workflow statistics
     */
    public function getWorkflowStats(): array
    {
        // This would typically query your database for workflow execution stats
        return [
            'concern_classifications' => [
                'total_triggered' => 0,
                'successful' => 0,
                'failed' => 0,
                'last_24_hours' => 0,
            ],
            'auto_replies' => [
                'total_triggered' => 0,
                'successful' => 0,
                'failed' => 0,
                'last_24_hours' => 0,
            ],
            'assignment_reminders' => [
                'total_triggered' => 0,
                'successful' => 0,
                'failed' => 0,
                'last_24_hours' => 0,
            ],
        ];
    }
}
