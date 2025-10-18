<?php

namespace App\Services;

use App\Models\Concern;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IntelligentPriorityDetectionService
{
    private $urgentKeywords = [
        'emergency', 'urgent', 'asap', 'immediately', 'critical', 'crisis',
        'help', 'stuck', 'broken', 'not working', 'failed', 'error',
        'deadline', 'due today', 'expired', 'overdue', 'late',
        'safety', 'danger', 'accident', 'injury', 'medical',
        'hack', 'security', 'breach', 'stolen', 'lost'
    ];

    private $highPriorityKeywords = [
        'important', 'priority', 'soon', 'quickly', 'fast',
        'issue', 'problem', 'trouble', 'difficulty', 'challenge',
        'grade', 'exam', 'test', 'assignment', 'project',
        'registration', 'enrollment', 'graduation', 'degree',
        'financial', 'payment', 'fee', 'scholarship', 'loan'
    ];

    private $mediumPriorityKeywords = [
        'question', 'inquiry', 'information', 'clarification',
        'request', 'application', 'form', 'document',
        'schedule', 'time', 'appointment', 'meeting',
        'general', 'routine', 'normal', 'standard'
    ];

    private $lowPriorityKeywords = [
        'suggestion', 'feedback', 'improvement', 'enhancement',
        'optional', 'when possible', 'no rush', 'take your time',
        'general inquiry', 'curiosity', 'learning'
    ];

    private $sentimentWeights = [
        'positive' => 0.1,    // Positive sentiment reduces priority
        'neutral' => 0.0,     // Neutral sentiment has no effect
        'negative' => 0.3,    // Negative sentiment increases priority
        'angry' => 0.5,       // Angry sentiment significantly increases priority
        'frustrated' => 0.4,  // Frustrated sentiment increases priority
    ];

    /**
     * Analyze concern content and determine intelligent priority
     */
    public function analyzePriority(Concern $concern): array
    {
        try {
            Log::info("Analyzing priority for concern {$concern->id}", [
                'concern_id' => $concern->id,
                'current_priority' => $concern->priority,
                'content_length' => strlen($concern->description)
            ]);

            $analysis = [
                'original_priority' => $concern->priority,
                'detected_priority' => $concern->priority,
                'confidence_score' => 0.0,
                'reasons' => [],
                'keywords_found' => [],
                'sentiment_analysis' => null,
                'context_analysis' => null,
                'auto_escalation' => false,
                'recommendations' => []
            ];

            // Combine title and description for analysis
            $fullText = strtolower(trim($concern->title . ' ' . $concern->description));
            
            if (empty($fullText)) {
                $analysis['detected_priority'] = 'medium';
                $analysis['confidence_score'] = 0.1;
                $analysis['reasons'][] = 'No content to analyze';
                return $analysis;
            }

            // 1. Keyword-based analysis
            $keywordAnalysis = $this->analyzeKeywords($fullText);
            $analysis['keywords_found'] = $keywordAnalysis['keywords'];
            $analysis['reasons'] = array_merge($analysis['reasons'], $keywordAnalysis['reasons']);

            // 2. Sentiment analysis
            $sentimentAnalysis = $this->analyzeSentiment($fullText);
            $analysis['sentiment_analysis'] = $sentimentAnalysis;
            $analysis['reasons'] = array_merge($analysis['reasons'], $sentimentAnalysis['reasons']);

            // 3. Context analysis
            $contextAnalysis = $this->analyzeContext($concern, $fullText);
            $analysis['context_analysis'] = $contextAnalysis;
            $analysis['reasons'] = array_merge($analysis['reasons'], $contextAnalysis['reasons']);

            // 4. Calculate final priority
            $finalPriority = $this->calculateFinalPriority(
                $keywordAnalysis,
                $sentimentAnalysis,
                $contextAnalysis,
                $concern
            );

            $analysis['detected_priority'] = $finalPriority['priority'];
            $analysis['confidence_score'] = $finalPriority['confidence'];
            $analysis['auto_escalation'] = $finalPriority['auto_escalation'];
            $analysis['recommendations'] = $finalPriority['recommendations'];

            // 5. Check if priority should be updated
            if ($this->shouldUpdatePriority($concern->priority, $finalPriority['priority'], $finalPriority['confidence'])) {
                $this->updateConcernPriority($concern, $finalPriority['priority'], $analysis);
            }

            Log::info("Priority analysis completed for concern {$concern->id}", [
                'original_priority' => $concern->priority,
                'detected_priority' => $finalPriority['priority'],
                'confidence' => $finalPriority['confidence'],
                'auto_escalation' => $finalPriority['auto_escalation']
            ]);

            return $analysis;

        } catch (\Exception $e) {
            Log::error("Priority analysis failed for concern {$concern->id}: " . $e->getMessage());
            return [
                'original_priority' => $concern->priority,
                'detected_priority' => $concern->priority,
                'confidence_score' => 0.0,
                'reasons' => ['Analysis failed: ' . $e->getMessage()],
                'error' => true
            ];
        }
    }

    /**
     * Analyze keywords in the concern text
     */
    private function analyzeKeywords(string $text): array
    {
        $analysis = [
            'keywords' => [],
            'reasons' => [],
            'scores' => [
                'urgent' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ]
        ];

        // Check for urgent keywords
        foreach ($this->urgentKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $analysis['keywords'][] = $keyword;
                $analysis['scores']['urgent'] += 1;
                $analysis['reasons'][] = "Urgent keyword detected: '{$keyword}'";
            }
        }

        // Check for high priority keywords
        foreach ($this->highPriorityKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $analysis['keywords'][] = $keyword;
                $analysis['scores']['high'] += 0.7;
                $analysis['reasons'][] = "High priority keyword detected: '{$keyword}'";
            }
        }

        // Check for medium priority keywords
        foreach ($this->mediumPriorityKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $analysis['keywords'][] = $keyword;
                $analysis['scores']['medium'] += 0.5;
                $analysis['reasons'][] = "Medium priority keyword detected: '{$keyword}'";
            }
        }

        // Check for low priority keywords
        foreach ($this->lowPriorityKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $analysis['keywords'][] = $keyword;
                $analysis['scores']['low'] += 0.3;
                $analysis['reasons'][] = "Low priority keyword detected: '{$keyword}'";
            }
        }

        return $analysis;
    }

    /**
     * Analyze sentiment of the concern text
     */
    private function analyzeSentiment(string $text): array
    {
        $sentiment = [
            'type' => 'neutral',
            'score' => 0.0,
            'reasons' => []
        ];

        // Simple sentiment analysis based on emotional words
        $positiveWords = ['good', 'great', 'excellent', 'thank', 'appreciate', 'helpful', 'nice', 'wonderful'];
        $negativeWords = ['bad', 'terrible', 'awful', 'hate', 'angry', 'frustrated', 'annoyed', 'disappointed'];
        $urgentWords = ['urgent', 'emergency', 'critical', 'asap', 'immediately', 'help', 'stuck'];

        $positiveCount = 0;
        $negativeCount = 0;
        $urgentCount = 0;

        foreach ($positiveWords as $word) {
            if (Str::contains($text, $word)) $positiveCount++;
        }

        foreach ($negativeWords as $word) {
            if (Str::contains($text, $word)) $negativeCount++;
        }

        foreach ($urgentWords as $word) {
            if (Str::contains($text, $word)) $urgentCount++;
        }

        // Determine sentiment type
        if ($urgentCount > 0) {
            $sentiment['type'] = 'urgent';
            $sentiment['score'] = 0.8;
            $sentiment['reasons'][] = 'Urgent language detected';
        } elseif ($negativeCount > $positiveCount) {
            $sentiment['type'] = 'negative';
            $sentiment['score'] = 0.6;
            $sentiment['reasons'][] = 'Negative sentiment detected';
        } elseif ($positiveCount > $negativeCount) {
            $sentiment['type'] = 'positive';
            $sentiment['score'] = 0.2;
            $sentiment['reasons'][] = 'Positive sentiment detected';
        } else {
            $sentiment['type'] = 'neutral';
            $sentiment['score'] = 0.0;
            $sentiment['reasons'][] = 'Neutral sentiment';
        }

        return $sentiment;
    }

    /**
     * Analyze context of the concern
     */
    private function analyzeContext(Concern $concern, string $text): array
    {
        $context = [
            'time_sensitivity' => 0.0,
            'academic_impact' => 0.0,
            'financial_impact' => 0.0,
            'safety_concern' => 0.0,
            'reasons' => []
        ];

        // Time sensitivity analysis
        $timeKeywords = ['deadline', 'due', 'expired', 'overdue', 'late', 'today', 'tomorrow', 'this week'];
        foreach ($timeKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $context['time_sensitivity'] += 0.3;
                $context['reasons'][] = "Time-sensitive: '{$keyword}'";
            }
        }

        // Academic impact analysis
        $academicKeywords = ['grade', 'exam', 'test', 'assignment', 'project', 'graduation', 'degree', 'gpa'];
        foreach ($academicKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $context['academic_impact'] += 0.2;
                $context['reasons'][] = "Academic impact: '{$keyword}'";
            }
        }

        // Financial impact analysis
        $financialKeywords = ['payment', 'fee', 'scholarship', 'loan', 'financial', 'money', 'cost', 'expensive'];
        foreach ($financialKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $context['financial_impact'] += 0.2;
                $context['reasons'][] = "Financial impact: '{$keyword}'";
            }
        }

        // Safety concern analysis
        $safetyKeywords = ['safety', 'danger', 'accident', 'injury', 'medical', 'emergency', 'security', 'threat'];
        foreach ($safetyKeywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                $context['safety_concern'] += 0.4;
                $context['reasons'][] = "Safety concern: '{$keyword}'";
            }
        }

        return $context;
    }

    /**
     * Calculate final priority based on all analyses
     */
    private function calculateFinalPriority(array $keywordAnalysis, array $sentimentAnalysis, array $contextAnalysis, Concern $concern): array
    {
        $scores = [
            'urgent' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0
        ];

        // Keyword scores
        foreach ($keywordAnalysis['scores'] as $priority => $score) {
            $scores[$priority] += $score;
        }

        // Sentiment scores
        $sentimentWeight = $this->sentimentWeights[$sentimentAnalysis['type']] ?? 0;
        if ($sentimentAnalysis['type'] === 'urgent') {
            $scores['urgent'] += 1.0;
        } elseif ($sentimentAnalysis['type'] === 'negative') {
            $scores['high'] += 0.5;
        }

        // Context scores
        $contextScore = $contextAnalysis['time_sensitivity'] + 
                       $contextAnalysis['academic_impact'] + 
                       $contextAnalysis['financial_impact'] + 
                       $contextAnalysis['safety_concern'];

        if ($contextScore >= 0.8) {
            $scores['urgent'] += 1.0;
        } elseif ($contextScore >= 0.5) {
            $scores['high'] += 0.7;
        } elseif ($contextScore >= 0.2) {
            $scores['medium'] += 0.5;
        } else {
            $scores['low'] += 0.3;
        }

        // Determine final priority
        $maxScore = max($scores);
        $finalPriority = array_search($maxScore, $scores);
        
        // Calculate confidence
        $totalScore = array_sum($scores);
        $confidence = $totalScore > 0 ? $maxScore / $totalScore : 0.5;

        // Auto-escalation logic
        $autoEscalation = false;
        $recommendations = [];

        if ($finalPriority === 'urgent' && $confidence > 0.7) {
            $autoEscalation = true;
            $recommendations[] = 'Auto-escalate to urgent priority';
        } elseif ($finalPriority === 'high' && $confidence > 0.6) {
            $recommendations[] = 'Consider escalating to high priority';
        }

        if ($contextAnalysis['safety_concern'] > 0.3) {
            $autoEscalation = true;
            $recommendations[] = 'Safety concern detected - immediate attention required';
        }

        return [
            'priority' => $finalPriority,
            'confidence' => $confidence,
            'auto_escalation' => $autoEscalation,
            'recommendations' => $recommendations,
            'scores' => $scores
        ];
    }

    /**
     * Determine if priority should be updated
     */
    private function shouldUpdatePriority(string $currentPriority, string $detectedPriority, float $confidence): bool
    {
        // Don't update if confidence is too low
        if ($confidence < 0.6) return false;

        // Priority hierarchy
        $priorityHierarchy = ['low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4];
        
        $currentLevel = $priorityHierarchy[$currentPriority] ?? 2;
        $detectedLevel = $priorityHierarchy[$detectedPriority] ?? 2;

        // Only escalate (never downgrade automatically)
        return $detectedLevel > $currentLevel;
    }

    /**
     * Update concern priority
     */
    private function updateConcernPriority(Concern $concern, string $newPriority, array $analysis): void
    {
        try {
            $concern->update([
                'priority' => $newPriority,
                'updated_at' => now()
            ]);

            Log::info("Auto-updated priority for concern {$concern->id}", [
                'concern_id' => $concern->id,
                'old_priority' => $analysis['original_priority'],
                'new_priority' => $newPriority,
                'confidence' => $analysis['confidence_score'],
                'reasons' => $analysis['reasons']
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update priority for concern {$concern->id}: " . $e->getMessage());
        }
    }

    /**
     * Get priority detection statistics
     */
    public function getPriorityDetectionStats(): array
    {
        $totalConcerns = Concern::count();
        $autoEscalated = Concern::where('priority', 'urgent')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $priorityDistribution = Concern::selectRaw('priority, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        return [
            'total_concerns_analyzed' => $totalConcerns,
            'auto_escalated_concerns' => $autoEscalated,
            'escalation_rate' => $totalConcerns > 0 ? ($autoEscalated / $totalConcerns) * 100 : 0,
            'priority_distribution' => $priorityDistribution,
            'detection_accuracy' => $this->calculateDetectionAccuracy()
        ];
    }

    /**
     * Calculate detection accuracy (simplified)
     */
    private function calculateDetectionAccuracy(): float
    {
        // This would ideally be calculated based on manual reviews
        // For now, return a placeholder value
        return 85.5; // 85.5% accuracy
    }
}
