<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AiController extends Controller
{
    /**
     * Classify concern using AI
     */
    public function classifyConcern(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:5000',
        ]);

        $text = $request->input('text');

        // AI-powered classification
        $classification = $this->analyzeText($text);

        return response()->json([
            'success' => true,
            'data' => $classification,
        ]);
    }

    /**
     * Analyze text for classification
     */
    private function analyzeText(string $text): array
    {
        $text = strtolower($text);
        
        // Priority detection
        $priority = $this->detectPriority($text);
        
        // Category detection
        $category = $this->detectCategory($text);
        
        // Department detection
        $department = $this->detectDepartment($text, $category);
        
        // Sentiment analysis
        $sentiment = $this->analyzeSentiment($text);
        
        return [
            'priority' => $priority,
            'category' => $category,
            'department_id' => $department,
            'sentiment' => $sentiment,
            'keywords' => $this->extractKeywords($text),
            'auto_escalation' => $priority === 'urgent' && $sentiment === 'negative',
        ];
    }

    /**
     * Enhanced keyword extraction with context
     */
    private function extractKeywords(string $text): array
    {
        $enhancedKeywords = [
            'academic' => [
                'grade', 'exam', 'assignment', 'course', 'professor', 'instructor', 'syllabus', 
                'curriculum', 'homework', 'project', 'thesis', 'dissertation', 'research',
                'academic', 'scholarly', 'study', 'learning', 'education', 'student portal',
                'canvas', 'blackboard', 'moodle', 'lms', 'gpa', 'transcript', 'credits'
            ],
            'financial' => [
                'payment', 'tuition', 'fee', 'financial aid', 'scholarship', 'refund', 
                'billing', 'money', 'cost', 'expensive', 'afford', 'loan', 'grant',
                'bursar', 'cashier', 'account', 'balance', 'outstanding', 'due'
            ],
            'administrative' => [
                'enrollment', 'registration', 'transcript', 'diploma', 'graduation', 
                'records', 'document', 'form', 'application', 'admission', 'withdrawal',
                'drop', 'add', 'schedule', 'timetable', 'catalog', 'handbook'
            ],
            'technical' => [
                'login', 'password', 'system', 'website', 'portal', 'error', 'bug', 
                'technical', 'computer', 'internet', 'wifi', 'network', 'server',
                'database', 'software', 'hardware', 'device', 'mobile', 'app'
            ],
            'housing' => [
                'dormitory', 'dorm', 'room', 'housing', 'residence', 'accommodation',
                'roommate', 'facility', 'maintenance', 'repair', 'cleaning', 'laundry'
            ],
            'health' => [
                'health', 'medical', 'doctor', 'nurse', 'clinic', 'hospital', 'medicine',
                'sick', 'illness', 'injury', 'emergency', 'mental health', 'counseling'
            ],
            'safety' => [
                'safety', 'security', 'emergency', 'danger', 'threat', 'harassment',
                'bullying', 'violence', 'theft', 'robbery', 'assault', 'campus police'
            ]
        ];

        $foundKeywords = [];
        
        foreach ($enhancedKeywords as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $foundKeywords[] = [
                        'keyword' => $keyword,
                        'category' => $category,
                        'relevance' => $this->calculateRelevance($text, $keyword)
                    ];
                }
            }
        }

        // Sort by relevance
        usort($foundKeywords, function($a, $b) {
            return $b['relevance'] <=> $a['relevance'];
        });

        return array_slice($foundKeywords, 0, 10); // Return top 10 keywords
    }

    /**
     * Calculate keyword relevance
     */
    private function calculateRelevance(string $text, string $keyword): float
    {
        $count = substr_count($text, $keyword);
        $textLength = strlen($text);
        $keywordLength = strlen($keyword);
        
        // Base relevance from frequency
        $frequency = $count / ($textLength / 1000);
        
        // Boost for longer, more specific keywords
        $specificity = $keywordLength / 10;
        
        return $frequency + $specificity;
    }

    /**
     * Detect priority level
     */
    private function detectPriority(string $text): string
    {
        $urgentKeywords = [
            'urgent', 'emergency', 'asap', 'immediately', 'critical', 'serious',
            'dangerous', 'threat', 'violence', 'harassment', 'bullying', 'assault',
            'medical emergency', 'hospital', 'ambulance', 'police', 'security'
        ];
        
        $highKeywords = [
            'important', 'priority', 'deadline', 'due', 'expired', 'overdue',
            'problem', 'issue', 'broken', 'not working', 'failed', 'error'
        ];
        
        foreach ($urgentKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return 'urgent';
            }
        }
        
        foreach ($highKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return 'high';
            }
        }

        return 'medium';
    }

    /**
     * Detect category
     */
    private function detectCategory(string $text): string
    {
        $categories = [
            'academic' => ['grade', 'exam', 'assignment', 'course', 'professor', 'homework', 'gpa'],
            'financial' => ['payment', 'tuition', 'fee', 'money', 'cost', 'refund', 'scholarship'],
            'administrative' => ['enrollment', 'registration', 'transcript', 'diploma', 'records'],
            'technical' => ['login', 'password', 'system', 'website', 'portal', 'error', 'bug'],
            'housing' => ['dormitory', 'dorm', 'room', 'housing', 'roommate', 'facility'],
            'health' => ['health', 'medical', 'doctor', 'clinic', 'sick', 'medicine'],
            'safety' => ['safety', 'security', 'emergency', 'danger', 'harassment', 'bullying']
        ];

        $scores = [];
        
        foreach ($categories as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score++;
                }
            }
            $scores[$category] = $score;
        }

        $maxScore = max($scores);
        if ($maxScore > 0) {
            return array_search($maxScore, $scores);
        }

        return 'general';
    }

    /**
     * Detect department
     */
    private function detectDepartment(string $text, string $category): int
    {
        $departmentMapping = [
            'academic' => 1, // Academic Affairs
            'financial' => 2, // Finance
            'administrative' => 3, // Registrar
            'technical' => 4, // IT Department
            'housing' => 5, // Student Housing
            'health' => 6, // Health Services
            'safety' => 7, // Campus Security
            'general' => 1, // Default to Academic Affairs
        ];

        return $departmentMapping[$category] ?? 1;
    }

    /**
     * Analyze sentiment
     */
    private function analyzeSentiment(string $text): string
    {
        $positiveWords = [
            'good', 'great', 'excellent', 'amazing', 'wonderful', 'fantastic',
            'happy', 'pleased', 'satisfied', 'thankful', 'grateful', 'helpful'
        ];

        $negativeWords = [
            'bad', 'terrible', 'awful', 'horrible', 'disappointed', 'frustrated',
            'angry', 'upset', 'sad', 'worried', 'concerned', 'problem', 'issue'
        ];

        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($positiveWords as $word) {
            if (strpos($text, $word) !== false) {
                $positiveCount++;
            }
        }

        foreach ($negativeWords as $word) {
            if (strpos($text, $word) !== false) {
                $negativeCount++;
            }
        }

        if ($positiveCount > $negativeCount) {
            return 'positive';
        } elseif ($negativeCount > $positiveCount) {
            return 'negative';
        } else {
            return 'neutral';
        }
    }

    /**
     * Chat with AI
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'context' => 'nullable|string|max:100',
            'user_role' => 'nullable|string|in:student,faculty,staff,department_head,admin',
        ]);

        try {
            $message = $request->input('message');
            $context = $request->input('context', 'general');
            $userRole = $request->input('user_role', 'student');

            // Use the improved HuggingFace service
            $huggingFaceService = new \App\Services\ImprovedHuggingFaceService();
            
            $messages = [
                ['role' => 'user', 'content' => $message]
            ];
            
            $response = $huggingFaceService->getChatCompletion($messages, [
                'context' => $context,
                'user_role' => $userRole
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $response['content'],
                    'source' => $response['source'] ?? 'unknown',
                    'model' => $response['model'] ?? 'unknown',
                    'timestamp' => now()->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('AI Chat Error: ' . $e->getMessage());
            
            // Fallback response
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Thank you for your message. I\'m here to help with college-related questions. You can ask about concerns, academic matters, library services, enrollment, or any other campus-related topics.',
                    'source' => 'error_fallback',
                    'model' => 'fallback',
                    'timestamp' => now()->toISOString(),
                ]
            ]);
        }
    }

    /**
     * Get AI suggestions
     */
    public function getSuggestions(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['suggestions' => []],
        ]);
    }

    /**
     * Transcribe audio
     */
    public function transcribeAudio(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['transcription' => 'Audio transcription coming soon'],
        ]);
    }

    /**
     * Get AI sessions
     */
    public function getSessions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['sessions' => []],
        ]);
    }

    /**
     * Create AI session
     */
    public function createSession(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['session_id' => 'session_' . time()],
        ]);
    }

    /**
     * Get AI session
     */
    public function getSession($sessionId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['session' => ['id' => $sessionId]],
        ]);
    }

    /**
     * Delete AI session
     */
    public function deleteSession($sessionId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Session deleted',
        ]);
    }

    /**
     * Get AI settings
     */
    public function getSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['settings' => []],
        ]);
    }

    /**
     * Update AI settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Settings updated',
        ]);
    }

    /**
     * Train chatbot
     */
    public function trainChatbot(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Chatbot training started',
        ]);
    }

    /**
     * Get AI analytics
     */
    public function getAnalytics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['analytics' => []],
        ]);
    }

    /**
     * Get AI conversations
     */
    public function getConversations(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['conversations' => []],
        ]);
    }

    /**
     * Get Dialogflow config
     */
    public function getDialogflowConfig(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['config' => []],
        ]);
    }

    /**
     * Update Dialogflow config
     */
    public function updateDialogflowConfig(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Dialogflow config updated',
        ]);
    }

    /**
     * Get Hugging Face config
     */
    public function getHuggingFaceConfig(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['config' => []],
        ]);
    }

    /**
     * Update Hugging Face config
     */
    public function updateHuggingFaceConfig(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Hugging Face config updated',
        ]);
    }

    /**
     * Get FAQ items
     */
    public function getFAQItems(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['faq' => []],
        ]);
    }

    /**
     * Update FAQ items
     */
    public function updateFAQItems(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'FAQ items updated',
        ]);
    }

    /**
     * Get chat sessions
     */
    public function getChatSessions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['sessions' => []],
        ]);
    }

    /**
     * Test chatbot
     */
    public function testChatbot(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['response' => 'Test response'],
        ]);
    }

    /**
     * Bulk upload training data
     */
    public function bulkUploadTrainingData(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Training data uploaded',
        ]);
    }

    /**
     * Get training batches
     */
    public function getTrainingBatches(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['batches' => []],
        ]);
    }

    /**
     * Get training stats
     */
    public function getTrainingStats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['stats' => []],
        ]);
    }

    /**
     * Generate AI writing assistance for concern descriptions
     */
    public function generateWritingAssistance(Request $request): JsonResponse
    {
        $request->validate([
            'user_input' => 'required|string|max:2000',
            'concern_type' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
        ]);

        try {
            $userInput = $request->input('user_input');
            $concernType = $request->input('concern_type');
            $department = $request->input('department');
            $priority = $request->input('priority');

            // Validate input content
            $validationResult = $this->validateInputContent($userInput);
            if (!$validationResult['isValid']) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'suggestion' => $validationResult['message'],
                        'source' => 'validation_error'
                    ]
                ]);
            }

            // Check if AI is enabled
            if (!config('services.openrouter.enabled', true)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'suggestion' => $this->generateIntelligentFallback($userInput, $concernType, $department, $priority),
                        'source' => 'fallback'
                    ]
                ]);
            }

            // Try to use OpenRouter API
            $aiSuggestion = $this->callOpenRouterAPI($userInput, $concernType, $department, $priority);
            
            if (!empty($aiSuggestion)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'suggestion' => $aiSuggestion,
                        'source' => 'ai'
                    ]
                ]);
            }

            // Fallback to intelligent template
            return response()->json([
                'success' => true,
                'data' => [
                    'suggestion' => $this->generateIntelligentFallback($userInput, $concernType, $department, $priority),
                    'source' => 'intelligent_fallback'
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('AI Writing Assistance Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => true,
                'data' => [
                    'suggestion' => $this->generateIntelligentFallback($userInput, $concernType, $department, $priority),
                    'source' => 'error_fallback'
                ]
            ]);
        }
    }

    /**
     * Call OpenRouter API for AI writing assistance
     */
    private function callOpenRouterAPI(string $userInput, ?string $concernType, ?string $department, ?string $priority): ?string
    {
        try {
            $apiKey = config('services.openrouter.api_key');
            $baseUrl = config('services.openrouter.base_url');
            $model = config('services.openrouter.default_model', 'deepseek/deepseek-r1:free');

            if (empty($apiKey) || $apiKey === 'sk-or-v1-your_api_key_here') {
                return null;
            }

            $prompt = $this->createWritingPrompt($userInput, $concernType, $department, $priority);

            // Configure HTTP client with SSL options for local development
            $httpClient = \Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'StudentLink AI Assistant',
            ])->timeout(30);

            // For local development, disable SSL verification if needed
            if (config('app.env') === 'local' || config('app.debug')) {
                $httpClient = $httpClient->withOptions([
                    'verify' => false,
                    'curl' => [
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                    ]
                ]);
            }

            $response = $httpClient->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an AI writing assistant helping students improve their concern descriptions for a college administration system. Provide clear, professional, and respectful suggestions that maintain the original intent while improving clarity and professionalism.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => config('services.openrouter.max_tokens', 300),
                'temperature' => config('services.openrouter.temperature', 0.7),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['choices'][0]['message']['content'])) {
                    \Log::info('OpenRouter API call successful', [
                        'model' => $model,
                        'response_length' => strlen($data['choices'][0]['message']['content'])
                    ]);
                    return trim($data['choices'][0]['message']['content']);
                }
            } else {
                \Log::warning('OpenRouter API call failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('OpenRouter API Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create writing improvement prompt
     */
    private function createWritingPrompt(string $userInput, ?string $concernType, ?string $department, ?string $priority): string
    {
        $prompt = "You are helping a student improve their concern description for a college administration system.\n\n";
        $prompt .= "Original student input: \"$userInput\"\n\n";

        if ($concernType) {
            $prompt .= "Concern Type: $concernType\n";
        }
        if ($department) {
            $prompt .= "Department: $department\n";
        }
        if ($priority) {
            $prompt .= "Priority: $priority\n";
        }

        $prompt .= "\nPlease rewrite this as a professional, clear, and respectful concern description that:\n";
        $prompt .= "1. Maintains the original intent and key information\n";
        $prompt .= "2. Uses appropriate academic language\n";
        $prompt .= "3. Is specific and actionable\n";
        $prompt .= "4. Shows respect for the institution\n";
        $prompt .= "5. Includes relevant details if mentioned\n";
        $prompt .= "6. Removes any inappropriate language\n";
        $prompt .= "7. Is between 50-500 characters\n\n";
        $prompt .= "Provide only the improved version without any explanations or prefixes:";

        return $prompt;
    }

    /**
     * Generate intelligent fallback suggestion
     */
    private function generateIntelligentFallback(string $userInput, ?string $concernType, ?string $department, ?string $priority): string
    {
        $input = trim($userInput);
        
        if (empty($input)) {
            return "I am writing to report a concern regarding [specific issue]. This matter has been affecting [who/what is affected] and I believe it requires attention from the appropriate department. I would appreciate your assistance in resolving this matter promptly.";
        }

        // Analyze the input to provide contextual suggestions
        $analysis = $this->analyzeInputForFallback($input, $concernType, $department, $priority);
        
        // Generate contextual template based on analysis
        return $this->generateContextualTemplate($input, $analysis);
    }

    /**
     * Analyze input for intelligent fallback
     */
    private function analyzeInputForFallback(string $input, ?string $concernType, ?string $department, ?string $priority): array
    {
        $text = strtolower($input);
        
        return [
            'word_count' => str_word_count($input),
            'has_urgency' => $this->hasUrgencyKeywords($text),
            'has_emotional_language' => $this->hasEmotionalLanguage($text),
            'has_specific_details' => $this->hasSpecificDetails($text),
            'has_inappropriate_content' => $this->hasInappropriateContent($text),
            'concern_category' => $this->identifyConcernCategory($text),
            'tone' => $this->identifyTone($text),
            'needs_formalization' => $this->needsFormalization($text),
            'original_input' => $input,
            'concern_type' => $concernType,
            'department' => $department,
            'priority' => $priority,
        ];
    }

    /**
     * Generate contextual template based on analysis
     */
    private function generateContextualTemplate(string $input, array $analysis): string
    {
        $category = $analysis['concern_category'];
        $hasUrgency = $analysis['has_urgency'];
        $hasEmotional = $analysis['has_emotional_language'];
        $needsFormalization = $analysis['needs_formalization'];
        $concernType = $analysis['concern_type'];
        $department = $analysis['department'];
        $priority = $analysis['priority'];

        // Clean the input
        $cleanInput = $this->cleanUserInput($input);

        // Generate base template based on category and context
        $templates = $this->getContextualTemplates($category, $concernType, $department);
        
        // Select appropriate template
        $template = $templates['default'];
        if ($hasUrgency && isset($templates['urgent'])) {
            $template = $templates['urgent'];
        } elseif ($hasEmotional && isset($templates['emotional'])) {
            $template = $templates['emotional'];
        } elseif ($needsFormalization && isset($templates['formal'])) {
            $template = $templates['formal'];
        }

        // Replace placeholders
        $template = str_replace('[USER_INPUT]', $cleanInput, $template);
        $template = str_replace('[CONCERN_TYPE]', $concernType ?: 'this matter', $template);
        $template = str_replace('[DEPARTMENT]', $department ?: 'the appropriate department', $template);
        $template = str_replace('[PRIORITY]', $priority ?: 'medium', $template);

        return $template;
    }

    /**
     * Get contextual templates based on category
     */
    private function getContextualTemplates(string $category, ?string $concernType, ?string $department): array
    {
        $templates = [
            'academic' => [
                'default' => "I am writing to report an academic concern regarding [USER_INPUT]. This matter is affecting my studies and I would appreciate assistance from [DEPARTMENT] to resolve this issue promptly.",
                'urgent' => "I am writing to report an urgent academic concern regarding [USER_INPUT]. This matter requires immediate attention as it is significantly impacting my academic progress. I would appreciate prompt assistance from [DEPARTMENT].",
                'formal' => "I respectfully submit this concern regarding [USER_INPUT] for your consideration. This academic matter requires attention from [DEPARTMENT], and I would be grateful for your assistance in resolving it.",
            ],
            'financial' => [
                'default' => "I am writing to report a financial concern regarding [USER_INPUT]. This matter is affecting my ability to continue my education and I would appreciate assistance from [DEPARTMENT] to resolve this issue.",
                'urgent' => "I am writing to report an urgent financial concern regarding [USER_INPUT]. This matter requires immediate attention as it may affect my enrollment status. I would appreciate prompt assistance from [DEPARTMENT].",
                'formal' => "I respectfully submit this financial concern regarding [USER_INPUT] for your consideration. This matter requires attention from [DEPARTMENT], and I would be grateful for your assistance in resolving it.",
            ],
            'technical' => [
                'default' => "I am writing to report a technical issue regarding [USER_INPUT]. This problem is preventing me from accessing necessary systems and I would appreciate assistance from [DEPARTMENT] to resolve this issue.",
                'urgent' => "I am writing to report an urgent technical issue regarding [USER_INPUT]. This problem is preventing me from completing important tasks and requires immediate attention from [DEPARTMENT].",
                'formal' => "I respectfully submit this technical concern regarding [USER_INPUT] for your consideration. This system issue requires attention from [DEPARTMENT], and I would be grateful for your assistance in resolving it.",
            ],
            'general' => [
                'default' => "I am writing to report a concern regarding [USER_INPUT]. This matter requires attention and I would appreciate assistance from [DEPARTMENT] to resolve this issue promptly.",
                'urgent' => "I am writing to report an urgent concern regarding [USER_INPUT]. This matter requires immediate attention and I would appreciate prompt assistance from [DEPARTMENT].",
                'formal' => "I respectfully submit this concern regarding [USER_INPUT] for your consideration. This matter requires attention from [DEPARTMENT], and I would be grateful for your assistance in resolving it.",
            ],
        ];

        return $templates[$category] ?? $templates['general'];
    }

    /**
     * Clean user input for template insertion
     */
    private function cleanUserInput(string $input): string
    {
        // Remove excessive punctuation and clean up
        $cleaned = preg_replace('/[!]{2,}/', '!', $input);
        $cleaned = preg_replace('/[?]{2,}/', '?', $cleaned);
        $cleaned = preg_replace('/[.]{2,}/', '.', $cleaned);
        
        // Capitalize first letter
        $cleaned = ucfirst(trim($cleaned));
        
        return $cleaned;
    }

    /**
     * Check for urgency keywords
     */
    private function hasUrgencyKeywords(string $text): bool
    {
        $urgencyKeywords = [
            'urgent', 'asap', 'immediately', 'emergency', 'critical', 'important',
            'help', 'pls', 'please', 'quickly', 'fast', 'now', 'today'
        ];
        
        foreach ($urgencyKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check for emotional language
     */
    private function hasEmotionalLanguage(string $text): bool
    {
        $emotionalKeywords = [
            'frustrated', 'angry', 'upset', 'worried', 'concerned', 'disappointed',
            'terrible', 'awful', 'horrible', 'annoying', 'frustrating'
        ];
        
        foreach ($emotionalKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check for specific details
     */
    private function hasSpecificDetails(string $text): bool
    {
        $detailIndicators = [
            'date', 'time', 'location', 'room', 'building', 'class', 'teacher',
            'professor', 'student', 'name', 'number', 'id', 'grade', 'assignment'
        ];
        
        foreach ($detailIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check for inappropriate content
     */
    private function hasInappropriateContent(string $text): bool
    {
        $inappropriateWords = [
            'fuck', 'shit', 'damn', 'hell', 'crap', 'bitch', 'ass', 'bastard',
            'fucking', 'shitting', 'damned', 'hellish', 'crappy', 'bitchy', 'asshole'
        ];
        
        // Split text into words for more accurate matching
        $words = preg_split('/\s+/', $text);
        
        foreach ($words as $word) {
            // Remove punctuation for cleaner matching
            $cleanWord = preg_replace('/[^\w]/', '', strtolower($word));
            
            foreach ($inappropriateWords as $inappropriate) {
                if ($cleanWord === $inappropriate) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Identify concern category
     */
    private function identifyConcernCategory(string $text): string
    {
        $categories = [
            'academic' => ['grade', 'exam', 'assignment', 'course', 'professor', 'homework', 'gpa', 'class', 'study'],
            'financial' => ['payment', 'tuition', 'fee', 'money', 'cost', 'refund', 'scholarship', 'billing'],
            'technical' => ['login', 'password', 'system', 'website', 'portal', 'error', 'bug', 'computer', 'wifi'],
            'housing' => ['dormitory', 'dorm', 'room', 'housing', 'roommate', 'facility', 'maintenance'],
            'health' => ['health', 'medical', 'doctor', 'clinic', 'sick', 'medicine', 'hospital'],
            'safety' => ['safety', 'security', 'emergency', 'danger', 'harassment', 'bullying', 'threat'],
        ];

        $scores = [];
        
        foreach ($categories as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score++;
                }
            }
            $scores[$category] = $score;
        }

        $maxScore = max($scores);
        if ($maxScore > 0) {
            return array_search($maxScore, $scores);
        }

        return 'general';
    }

    /**
     * Identify tone
     */
    private function identifyTone(string $text): string
    {
        if ($this->hasUrgencyKeywords($text)) {
            return 'urgent';
        }
        
        if ($this->hasEmotionalLanguage($text)) {
            return 'emotional';
        }
        
        if (strlen($text) < 30 || !strpos($text, '.')) {
            return 'informal';
        }
        
        return 'neutral';
    }

    /**
     * Check if text needs formalization
     */
    private function needsFormalization(string $text): bool
    {
        return $this->hasEmotionalLanguage($text) ||
               $this->hasInappropriateContent($text) ||
               strlen($text) < 30 ||
               !strpos($text, '.') ||
               strpos($text, 'pls') !== false ||
               strpos($text, 'thx') !== false ||
               strpos($text, 'u ') !== false ||
               strpos($text, 'ur ') !== false;
    }

    /**
     * Validate input content before processing
     */
    private function validateInputContent(string $input): array
    {
        $trimmedInput = trim($input);
        
        // Check if input is empty
        if (empty($trimmedInput)) {
            return [
                'isValid' => false,
                'message' => 'Please enter a description of your concern before using the AI assistant.'
            ];
        }
        
        // Check minimum length
        if (strlen($trimmedInput) < 10) {
            return [
                'isValid' => false,
                'message' => 'Please provide a more detailed description (at least 10 characters) for better AI assistance.'
            ];
        }
        
        // Check for random letters or nonsensical input
        if ($this->isRandomLetters($trimmedInput)) {
            return [
                'isValid' => false,
                'message' => 'Please provide a meaningful description of your concern. Random letters or nonsensical text cannot be processed by the AI assistant.'
            ];
        }
        
        // Check for inappropriate content
        if ($this->hasInappropriateContent(strtolower($trimmedInput))) {
            return [
                'isValid' => false,
                'message' => 'Please use appropriate language when describing your concern. The AI assistant cannot process inappropriate content.'
            ];
        }
        
        // Check for excessive repetition
        if ($this->hasExcessiveRepetition($trimmedInput)) {
            return [
                'isValid' => false,
                'message' => 'Please provide a clear, non-repetitive description of your concern for better AI assistance.'
            ];
        }
        
        // Check for only special characters or numbers
        if ($this->isOnlySpecialCharsOrNumbers($trimmedInput)) {
            return [
                'isValid' => false,
                'message' => 'Please provide a text description of your concern. The AI assistant needs meaningful text to help you.'
            ];
        }
        
        return [
            'isValid' => true,
            'message' => ''
        ];
    }

    /**
     * Check if input consists mainly of random letters
     */
    private function isRandomLetters(string $input): bool
    {
        // Remove spaces and convert to lowercase
        $cleanInput = strtolower(str_replace(' ', '', $input));
        
        // Check if input is too short after cleaning
        if (strlen($cleanInput) < 5) return false;
        
        // Count consecutive repeated characters
        $consecutiveCount = 1;
        $maxConsecutive = 1;
        
        for ($i = 1; $i < strlen($cleanInput); $i++) {
            if ($cleanInput[$i] === $cleanInput[$i - 1]) {
                $consecutiveCount++;
                $maxConsecutive = max($maxConsecutive, $consecutiveCount);
            } else {
                $consecutiveCount = 1;
            }
        }
        
        // If more than 50% of characters are consecutive repeats, it's likely random
        if ($maxConsecutive > strlen($cleanInput) * 0.5) return true;
        
        // Check for patterns like "ksksks" or "owowow"
        if (preg_match('/(.{1,3})\1{2,}/', $cleanInput)) return true;
        
        // Check if input has very few unique characters (less than 30% unique)
        $uniqueChars = count(array_unique(str_split($cleanInput)));
        if ($uniqueChars < strlen($cleanInput) * 0.3) return true;
        
        return false;
    }

    /**
     * Check for excessive repetition of words or phrases
     */
    private function hasExcessiveRepetition(string $input): bool
    {
        $words = preg_split('/\s+/', strtolower($input));
        if (count($words) < 3) return false;
        
        // Count word frequency
        $wordCount = [];
        foreach ($words as $word) {
            if (strlen($word) > 2) { // Only count words longer than 2 characters
                $wordCount[$word] = ($wordCount[$word] ?? 0) + 1;
            }
        }
        
        // Check if any word appears more than 50% of the time
        foreach ($wordCount as $count) {
            if ($count > count($words) * 0.5) return true;
        }
        
        return false;
    }

    /**
     * Check if input consists only of special characters or numbers
     */
    private function isOnlySpecialCharsOrNumbers(string $input): bool
    {
        // Remove spaces
        $cleanInput = str_replace(' ', '', $input);
        
        // Check if input is only numbers
        if (preg_match('/^\d+$/', $cleanInput)) return true;
        
        // Check if input is only special characters
        if (preg_match('/^[^a-zA-Z0-9]+$/', $cleanInput)) return true;
        
        // Check if input has very few letters (less than 30% letters)
        $letterCount = preg_match_all('/[a-zA-Z]/', $cleanInput);
        if ($letterCount < strlen($cleanInput) * 0.3) return true;
        
        return false;
    }
}