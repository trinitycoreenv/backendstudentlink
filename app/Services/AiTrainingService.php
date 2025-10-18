<?php

namespace App\Services;

use App\Models\FaqItem;
use App\Models\TrainingData;
use App\Models\TrainingBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AiTrainingService
{
    /**
     * Process bulk training data from JSON file
     */
    public function processBulkTrainingData(UploadedFile $file, User $user): array
    {
        $batchId = Str::uuid();
        $filename = $file->getClientOriginalName();
        
        try {
            // Read and parse JSON file
            $content = file_get_contents($file->getPathname());
            $data = json_decode($content, true);
            
            if (!$data || !isset($data['training_data'])) {
                throw new \Exception('Invalid JSON format. Expected "training_data" array.');
            }
            
            $trainingData = $data['training_data'];
            $totalItems = count($trainingData);
            
            // Create batch record
            $batch = TrainingBatch::create([
                'batch_id' => $batchId,
                'filename' => $filename,
                'type' => $this->determineBatchType($trainingData),
                'total_items' => $totalItems,
                'status' => 'processing',
                'created_by' => $user->id,
            ]);
            
            $successfulItems = 0;
            $failedItems = 0;
            $errors = [];
            
            // Process each training item
            foreach ($trainingData as $index => $item) {
                try {
                    $this->processTrainingItem($item, $batchId, $user);
                    $successfulItems++;
                } catch (\Exception $e) {
                    $failedItems++;
                    $errors[] = [
                        'index' => $index,
                        'item' => $item,
                        'error' => $e->getMessage()
                    ];
                    Log::error('Training item processing failed', [
                        'batch_id' => $batchId,
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'item' => $item
                    ]);
                }
            }
            
            // Update batch status
            $batch->update([
                'successful_items' => $successfulItems,
                'failed_items' => $failedItems,
                'status' => $failedItems > 0 ? 'completed' : 'completed',
                'errors' => $errors,
                'processed_at' => now(),
            ]);
            
            // Update FAQ items if any were created
            $this->syncFaqItems($batchId);
            
            return [
                'success' => true,
                'batch_id' => $batchId,
                'total_items' => $totalItems,
                'successful_items' => $successfulItems,
                'failed_items' => $failedItems,
                'errors' => $errors,
            ];
            
        } catch (\Exception $e) {
            Log::error('Bulk training data processing failed', [
                'batch_id' => $batchId,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'batch_id' => $batchId,
            ];
        }
    }
    
    /**
     * Process individual training item
     */
    private function processTrainingItem(array $item, string $batchId, User $user): void
    {
        $type = $item['type'] ?? 'faq';
        
        switch ($type) {
            case 'faq':
                $this->processFaqItem($item, $batchId, $user);
                break;
            case 'conversation':
                $this->processConversationItem($item, $batchId, $user);
                break;
            case 'department_info':
                $this->processDepartmentInfoItem($item, $batchId, $user);
                break;
            default:
                throw new \Exception("Unknown training data type: {$type}");
        }
    }
    
    /**
     * Process FAQ item
     */
    private function processFaqItem(array $item, string $batchId, User $user): void
    {
        $requiredFields = ['question', 'answer'];
        foreach ($requiredFields as $field) {
            if (!isset($item[$field]) || empty($item[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }
        
        TrainingData::create([
            'type' => 'faq',
            'question' => $item['question'],
            'answer' => $item['answer'],
            'category' => $item['category'] ?? 'general',
            'context' => $item['context'] ?? 'general',
            'tags' => $item['tags'] ?? [],
            'priority' => $this->mapPriority($item['priority'] ?? 'medium'),
            'active' => true,
            'source' => 'bulk_import',
            'batch_id' => $batchId,
            'created_by' => $user->id,
        ]);
    }
    
    /**
     * Process conversation item
     */
    private function processConversationItem(array $item, string $batchId, User $user): void
    {
        $requiredFields = ['user_message', 'assistant_response'];
        foreach ($requiredFields as $field) {
            if (!isset($item[$field]) || empty($item[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }
        
        TrainingData::create([
            'type' => 'conversation',
            'user_message' => $item['user_message'],
            'assistant_response' => $item['assistant_response'],
            'category' => $item['category'] ?? 'general',
            'context' => $item['context'] ?? 'general',
            'tags' => $item['tags'] ?? [],
            'priority' => $this->mapPriority($item['priority'] ?? 'medium'),
            'active' => true,
            'source' => 'bulk_import',
            'batch_id' => $batchId,
            'created_by' => $user->id,
        ]);
    }
    
    /**
     * Process department info item
     */
    private function processDepartmentInfoItem(array $item, string $batchId, User $user): void
    {
        $requiredFields = ['department', 'topic', 'information'];
        foreach ($requiredFields as $field) {
            if (!isset($item[$field]) || empty($item[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }
        
        TrainingData::create([
            'type' => 'department_info',
            'department' => $item['department'],
            'topic' => $item['topic'],
            'information' => $item['information'],
            'category' => $item['category'] ?? 'department',
            'context' => $item['context'] ?? 'general',
            'tags' => $item['tags'] ?? [],
            'priority' => $this->mapPriority($item['priority'] ?? 'medium'),
            'active' => true,
            'source' => 'bulk_import',
            'batch_id' => $batchId,
            'created_by' => $user->id,
        ]);
    }
    
    /**
     * Sync training data to FAQ items
     */
    private function syncFaqItems(string $batchId): void
    {
        $faqTrainingData = TrainingData::where('batch_id', $batchId)
            ->where('type', 'faq')
            ->where('active', true)
            ->get();
        
        foreach ($faqTrainingData as $item) {
            // Check if FAQ already exists
            $existingFaq = FaqItem::where('question', $item->question)->first();
            
            if (!$existingFaq) {
                FaqItem::create([
                    'question' => $item->question,
                    'answer' => $item->answer,
                    'category' => $item->category,
                    'intent' => $this->generateIntent($item->question),
                    'confidence' => 0.80,
                    'active' => true,
                    'tags' => $item->tags,
                    'context' => $item->context,
                    'priority' => $item->priority,
                    'created_by' => $item->created_by,
                ]);
            }
        }
    }
    
    /**
     * Determine batch type from training data
     */
    private function determineBatchType(array $trainingData): string
    {
        $types = array_unique(array_column($trainingData, 'type'));
        
        if (count($types) === 1) {
            return $types[0];
        }
        
        return 'mixed';
    }
    
    /**
     * Map priority string to integer
     */
    private function mapPriority(string $priority): int
    {
        return match (strtolower($priority)) {
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'critical' => 4,
            default => 2,
        };
    }
    
    /**
     * Generate intent from question
     */
    private function generateIntent(string $question): string
    {
        $question = strtolower($question);
        
        // Simple intent generation based on keywords
        if (str_contains($question, 'submit') || str_contains($question, 'create')) {
            return 'concern.submit';
        } elseif (str_contains($question, 'track') || str_contains($question, 'status')) {
            return 'concern.track';
        } elseif (str_contains($question, 'emergency') || str_contains($question, 'urgent')) {
            return 'emergency.contact';
        } elseif (str_contains($question, 'password') || str_contains($question, 'login')) {
            return 'account.password';
        } elseif (str_contains($question, 'grade') || str_contains($question, 'academic')) {
            return 'academic.grades';
        } else {
            return 'general.inquiry';
        }
    }
    
    /**
     * Get training data for AI responses
     */
    public function getTrainingDataForContext(string $context = 'general'): array
    {
        $faqItems = FaqItem::active()
            ->byContext($context)
            ->orderBy('priority', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => 'faq',
                    'question' => $item->question,
                    'answer' => $item->answer,
                    'category' => $item->category,
                    'intent' => $item->intent,
                    'confidence' => $item->confidence,
                ];
            });
        
        $conversations = TrainingData::active()
            ->byType('conversation')
            ->byContext($context)
            ->orderBy('priority', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => 'conversation',
                    'user_message' => $item->user_message,
                    'assistant_response' => $item->assistant_response,
                    'category' => $item->category,
                ];
            });
        
        $departmentInfo = TrainingData::active()
            ->byType('department_info')
            ->byContext($context)
            ->orderBy('priority', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => 'department_info',
                    'department' => $item->department,
                    'topic' => $item->topic,
                    'information' => $item->information,
                    'category' => $item->category,
                ];
            });
        
        return [
            'faq_items' => $faqItems,
            'conversations' => $conversations,
            'department_info' => $departmentInfo,
        ];
    }
    
    /**
     * Get training statistics
     */
    public function getTrainingStats(): array
    {
        return [
            'total_faq_items' => FaqItem::count(),
            'active_faq_items' => FaqItem::active()->count(),
            'total_training_data' => TrainingData::count(),
            'active_training_data' => TrainingData::active()->count(),
            'total_batches' => TrainingBatch::count(),
            'completed_batches' => TrainingBatch::completed()->count(),
            'recent_batches' => TrainingBatch::where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }
}
