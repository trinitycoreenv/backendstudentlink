<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HuggingFaceService
{
    protected ?string $apiKey;
    protected string $baseUrl;
    protected string $model;
    protected array $freeModels;

    public function __construct()
    {
        $this->apiKey = config('services.huggingface.api_key');
        $this->baseUrl = config('services.huggingface.base_url', 'https://api-inference.huggingface.co/models');
        $this->model = config('services.huggingface.model', 'microsoft/DialoGPT-medium');
        
        // Free models that don't require API key
        $this->freeModels = [
            'microsoft/DialoGPT-small',
            'microsoft/DialoGPT-medium',
            'microsoft/DialoGPT-large',
            'facebook/blenderbot-400M-distill',
            'microsoft/DialoGPT-medium'
        ];
    }

    /**
     * Get chat completion from Hugging Face
     */
    public function getChatCompletion(array $messages, array $context = []): array
    {
        try {
            // Get the last user message
            $userMessage = $this->extractUserMessage($messages);
            
            // Try Hugging Face API first
            if (!empty($this->apiKey)) {
                $response = $this->callHuggingFaceAPI($userMessage);
                if ($response) {
                    return $response;
                }
            }
            
            // Fallback to enhanced keyword-based responses
            return $this->getEnhancedResponse($userMessage, $context);
            
        } catch (\Exception $e) {
            Log::error('Hugging Face API Error', [
                'error' => $e->getMessage(),
                'messages' => $messages,
            ]);
            
            // Fallback to enhanced responses
            $userMessage = $this->extractUserMessage($messages);
            return $this->getEnhancedResponse($userMessage, $context);
        }
    }

    /**
     * Get AI suggestions for message composition
     */
    public function getSuggestions(string $context, string $type, string $existingText = '', array $options = []): array
    {
        $suggestions = $this->getContextualSuggestions($context, $type, $existingText, $options);
        
        // Try to enhance with Hugging Face if available
        if (!empty($this->apiKey) && !empty($existingText)) {
            try {
                $enhancedSuggestions = $this->getHuggingFaceSuggestions($existingText, $context);
                $suggestions = array_merge($suggestions, $enhancedSuggestions);
            } catch (\Exception $e) {
                Log::warning('Failed to get Hugging Face suggestions, using fallback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return array_slice(array_unique($suggestions), 0, 5); // Limit to 5 suggestions
    }

    /**
     * Transcribe audio using Hugging Face (fallback to basic implementation)
     */
    public function transcribeAudio(UploadedFile $audioFile, array $options = []): array
    {
        // For now, return a basic implementation
        // In a real scenario, you would integrate with Hugging Face's audio models
        return [
            'text' => 'Audio transcription not available with current Hugging Face setup. Please use text input.',
            'language' => $options['language'] ?? 'en',
            'duration' => null,
            'confidence' => null,
        ];
    }

    /**
     * Call Hugging Face API
     */
    private function callHuggingFaceAPI(string $message): ?array
    {
        try {
            $headers = [];
            if (!empty($this->apiKey)) {
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            }

            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/' . $this->model, [
                    'inputs' => $message,
                    'parameters' => [
                        'max_length' => 150,
                        'temperature' => 0.7,
                        'do_sample' => true,
                        'top_p' => 0.9,
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data[0]['generated_text'])) {
                    $generatedText = $data[0]['generated_text'];
                    $cleanResponse = $this->cleanResponse($generatedText, $message);
                    
                    return [
                        'content' => $cleanResponse,
                        'model' => $this->model,
                        'tokens_used' => null, // Hugging Face doesn't provide token count
                        'finish_reason' => 'stop',
                    ];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Hugging Face API call failed', [
                'error' => $e->getMessage(),
                'message' => $message,
            ]);
            return null;
        }
    }

    /**
     * Get enhanced keyword-based responses
     */
    private function getEnhancedResponse(string $message, array $context): array
    {
        $userRole = $context['user_role'] ?? 'student';
        $contextType = $context['context'] ?? 'general';
        
        $response = $this->buildContextualResponse($message, $userRole, $contextType);
        
        return [
            'content' => $response,
            'model' => 'enhanced-keyword-based',
            'tokens_used' => null,
            'finish_reason' => 'stop',
        ];
    }

    /**
     * Build contextual response based on keywords and context
     */
    private function buildContextualResponse(string $message, string $userRole, string $contextType): string
    {
        $message = strtolower(trim($message));
        
        // Role-specific responses
        $roleResponses = [
            'student' => $this->getStudentResponses($message),
            'faculty' => $this->getFacultyResponses($message),
            'staff' => $this->getStaffResponses($message),
            'department_head' => $this->getDepartmentHeadResponses($message),
            'admin' => $this->getAdminResponses($message),
        ];
        
        $response = $roleResponses[$userRole] ?? $roleResponses['student'];
        
        // Add context-specific enhancements
        return $this->enhanceWithContext($response, $contextType);
    }

    /**
     * Get student-specific responses
     */
    private function getStudentResponses(string $message): string
    {
        $responses = [
            'concern' => 'I can help you with concern submission. You can submit concerns through the mobile app or web portal. Make sure to provide details about the department and describe the issue clearly.',
            'announcement' => 'Check the announcements section for the latest campus updates and important information. You can also bookmark important announcements for easy access.',
            'emergency' => 'For emergencies, use the Emergency Help section in the app or call campus security at (02) 8000-0000. For immediate danger, call 911.',
            'grade' => 'For grade inquiries, contact the Registrar\'s Office or your academic advisor. You can also check your student portal for grade updates.',
            'library' => 'The library is open Monday to Friday, 8:00 AM to 8:00 PM. For book availability or library services, you can visit the library or contact them directly.',
            'enrollment' => 'Enrollment periods are announced through official announcements. Check the announcements section for enrollment schedules and requirements.',
            'scholarship' => 'For scholarship information, contact the Student Affairs Office or check the announcements section for scholarship opportunities.',
            'uniform' => 'Uniform policies are available in the student handbook. For specific questions, contact the Student Affairs Office.',
            'facility' => 'For facility-related concerns, submit a concern through the app or contact the Maintenance Department.',
            'academic' => 'For academic matters, contact your department head or academic advisor for assistance.',
            'hello' => 'Hello! I\'m your support assistant for Bestlink College. I can help you with questions about college procedures, academic requirements, and concern resolution. How can I assist you today?',
            'help' => 'I\'m here to help! You can ask me about concerns, academic matters, library services, enrollment, or any other campus-related topics. What would you like to know?',
            'registration' => 'For registration assistance, check the announcements section for enrollment schedules or contact the Registrar\'s Office directly.',
            'course' => 'For course-related questions, contact your academic advisor or department head. You can also check the course catalog in the announcements section.',
            'exam' => 'For exam schedules and information, check the announcements section or contact your department for specific exam details.',
            'fee' => 'For fee-related questions, contact the Finance Office or check the announcements section for payment schedules and procedures.',
        ];
        
        foreach ($responses as $keyword => $response) {
            if (strpos($message, $keyword) !== false) {
                return $response;
            }
        }
        
        return "Thank you for your message. I'm here to help with college-related questions. You can ask about concerns, academic matters, library services, enrollment, or any other campus-related topics.";
    }

    /**
     * Get faculty-specific responses
     */
    private function getFacultyResponses(string $message): string
    {
        $responses = [
            'concern' => 'As a faculty member, you can help students by guiding them to submit concerns through the proper channels. You can also monitor concern resolution through the web portal.',
            'student' => 'For student-related matters, you can access the concern management system through the web portal to track and respond to student issues.',
            'grade' => 'For grade management, use the academic portal. For grade-related concerns from students, guide them to submit formal concerns through the system.',
            'announcement' => 'You can create and manage announcements through the web portal. Make sure to target the appropriate audience and schedule them effectively.',
            'help' => 'I\'m here to assist you with faculty-related tasks. You can manage student concerns, create announcements, and access analytics through the web portal.',
        ];
        
        foreach ($responses as $keyword => $response) {
            if (strpos($message, $keyword) !== false) {
                return $response;
            }
        }
        
        return "I'm here to help you with faculty-related tasks. You can manage student concerns, create announcements, and access system analytics through the web portal.";
    }

    /**
     * Get staff-specific responses
     */
    private function getStaffResponses(string $message): string
    {
        $responses = [
            'concern' => 'As staff, you can efficiently manage and resolve student concerns through the web portal. Use the analytics dashboard to track resolution times and effectiveness.',
            'announcement' => 'Create targeted announcements for specific departments or the entire campus through the web portal. Schedule them for optimal visibility.',
            'analytics' => 'Access the analytics dashboard to monitor concern volume, resolution times, and system performance. Use this data to improve services.',
            'help' => 'I\'m here to help you with administrative tasks. You can manage concerns, create announcements, and monitor system performance through the web portal.',
        ];
        
        foreach ($responses as $keyword => $response) {
            if (strpos($message, $keyword) !== false) {
                return $response;
            }
        }
        
        return "I'm here to help you with administrative tasks. You can manage concerns, create announcements, and monitor system performance through the web portal.";
    }

    /**
     * Get department head-specific responses
     */
    private function getDepartmentHeadResponses(string $message): string
    {
        $responses = [
            'concern' => 'As a department head, you can oversee concern resolution in your department. Use the analytics dashboard to monitor performance and identify areas for improvement.',
            'analytics' => 'Access department-specific analytics to track concern volume, resolution times, and student satisfaction. Use this data for strategic planning.',
            'staff' => 'Manage your department staff through the user management system. Assign roles and permissions as needed for efficient concern resolution.',
            'help' => 'I\'m here to help you with department management. You can oversee concerns, manage staff, and access detailed analytics for your department.',
        ];
        
        foreach ($responses as $keyword => $response) {
            if (strpos($message, $keyword) !== false) {
                return $response;
            }
        }
        
        return "I'm here to help you with department management. You can oversee concerns, manage staff, and access detailed analytics for your department.";
    }

    /**
     * Get admin-specific responses
     */
    private function getAdminResponses(string $message): string
    {
        $responses = [
            'system' => 'As an administrator, you have full access to system settings, user management, and comprehensive analytics. Use the admin dashboard for system oversight.',
            'user' => 'Manage all users, roles, and permissions through the user management interface. Monitor system usage and performance.',
            'analytics' => 'Access comprehensive system analytics including concern volume, resolution times, user engagement, and system performance metrics.',
            'settings' => 'Configure system settings, AI parameters, and notification preferences through the admin settings panel.',
            'help' => 'I\'m here to help you with system administration. You have full access to all system features, analytics, and management tools.',
        ];
        
        foreach ($responses as $keyword => $response) {
            if (strpos($message, $keyword) !== false) {
                return $response;
            }
        }
        
        return "I'm here to help you with system administration. You have full access to all system features, analytics, and management tools.";
    }

    /**
     * Enhance response with context
     */
    private function enhanceWithContext(string $response, string $contextType): string
    {
        $contextEnhancements = [
            'concern' => 'For concern-related assistance, ',
            'academic' => 'For academic matters, ',
            'emergency' => 'For emergencies, ',
            'general' => 'I\'m here to help with college-related questions. '
        ];
        
        $enhancement = $contextEnhancements[$contextType] ?? $contextEnhancements['general'];
        
        // Only add enhancement if response doesn't already start with it
        if (!str_starts_with(strtolower($response), strtolower($enhancement))) {
            return $enhancement . strtolower($response);
        }
        
        return $response;
    }

    /**
     * Get contextual suggestions
     */
    private function getContextualSuggestions(string $context, string $type, string $existingText, array $options): array
    {
        $suggestionMap = [
            'concern' => [
                'How to submit a concern?',
                'What information should I include?',
                'Can I submit anonymously?',
                'How long does it take to resolve?',
                'How to check concern status?'
            ],
            'academic' => [
                'Grade inquiry process',
                'Academic calendar',
                'Course requirements',
                'Scholarship information',
                'Exam schedules'
            ],
            'emergency' => [
                'Emergency contacts',
                'Campus security',
                'Medical emergency',
                'Safety protocols',
                'Emergency procedures'
            ],
            'announcement' => [
                'How to create announcements?',
                'Targeting specific audiences',
                'Scheduling announcements',
                'Announcement templates',
                'Best practices for announcements'
            ],
            'general' => [
                'How to submit a concern?',
                'Academic calendar',
                'Contact information',
                'Library hours',
                'Enrollment process',
                'Grade inquiry',
                'Uniform policy',
                'Scholarship information'
            ]
        ];
        
        return $suggestionMap[$context] ?? $suggestionMap['general'];
    }

    /**
     * Get Hugging Face suggestions (if API is available)
     */
    private function getHuggingFaceSuggestions(string $text, string $context): array
    {
        try {
            $response = $this->callHuggingFaceAPI($text);
            if ($response && !empty($response['content'])) {
                return [$response['content']];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get Hugging Face suggestions', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return [];
    }

    /**
     * Extract user message from messages array
     */
    private function extractUserMessage(array $messages): string
    {
        // Get the last user message
        $userMessages = array_filter($messages, fn($msg) => $msg['role'] === 'user');
        $lastUserMessage = end($userMessages);
        
        return $lastUserMessage['content'] ?? '';
    }

    /**
     * Clean response from Hugging Face
     */
    private function cleanResponse(string $generatedText, string $originalMessage): string
    {
        // Remove the original message from the generated text
        $cleanText = str_replace($originalMessage, '', $generatedText);
        $cleanText = trim($cleanText);
        
        // If response is too short or empty, use fallback
        if (strlen($cleanText) < 10) {
            return $this->getStudentResponses($originalMessage);
        }
        
        return $cleanText;
    }

    /**
     * Validate API configuration
     */
    public function validateConfiguration(): bool
    {
        // For free models, we don't need API key validation
        if (empty($this->apiKey)) {
            return true; // Free models are always available
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->get('https://api-inference.huggingface.co/models');

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Hugging Face configuration validation failed', [
                'error' => $e->getMessage(),
            ]);
            return true; // Fallback to enhanced responses
        }
    }

    /**
     * Get available models
     */
    public function getAvailableModels(): array
    {
        return [
            'microsoft/DialoGPT-small' => 'DialoGPT Small (Fast)',
            'microsoft/DialoGPT-medium' => 'DialoGPT Medium (Balanced)',
            'microsoft/DialoGPT-large' => 'DialoGPT Large (Better Quality)',
            'facebook/blenderbot-400M-distill' => 'BlenderBot (Conversational)',
            'enhanced-keyword-based' => 'Enhanced Keyword-Based (Fallback)',
        ];
    }
}
