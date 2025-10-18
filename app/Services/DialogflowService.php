<?php

namespace App\Services;

use Google\Cloud\Dialogflow\V2\Client\SessionsClient;
use Google\Cloud\Dialogflow\V2\TextInput;
use Google\Cloud\Dialogflow\V2\QueryInput;
use Google\Cloud\Dialogflow\V2\QueryParameters;
use Google\Cloud\Dialogflow\V2\DetectIntentRequest;
use Illuminate\Support\Facades\Log;

class DialogflowService
{
    protected ?SessionsClient $sessionsClient;
    protected string $projectId;
    protected string $languageCode;

    public function __construct()
    {
        $this->projectId = config('services.dialogflow.project_id');
        $this->languageCode = config('services.dialogflow.language_code', 'en');
        
        // Initialize Dialogflow client with service account credentials
        $keyFileData = [
            'type' => 'service_account',
            'project_id' => $this->projectId,
            'private_key' => str_replace('\\n', "\n", config('services.dialogflow.private_key')),
            'client_email' => config('services.dialogflow.client_email'),
        ];

        // Only initialize Dialogflow if configuration is available
        if (!empty($keyFileData['project_id']) && !empty($keyFileData['private_key'])) {
            try {
                Log::info('Initializing Dialogflow client', [
                    'project_id' => $this->projectId,
                    'client_email' => $keyFileData['client_email']
                ]);
                
                // Set the credentials file path
                $credentialsFile = storage_path('app/dialogflow_credentials.json');
                
                // Set environment variable for Google Cloud SDK
                putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsFile);
                
                $this->sessionsClient = new SessionsClient();
                
                Log::info('Dialogflow client initialized successfully');
            } catch (\Exception $e) {
                Log::error('Dialogflow initialization failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->sessionsClient = null;
            }
        } else {
            Log::warning('Dialogflow configuration incomplete', [
                'project_id' => !empty($keyFileData['project_id']),
                'private_key' => !empty($keyFileData['private_key']),
                'client_email' => !empty($keyFileData['client_email'])
            ]);
            $this->sessionsClient = null;
        }
    }

    /**
     * Detect intent from text input
     */
    public function detectIntent(string $text, string $sessionId, array $context = []): array
    {
        if (!$this->sessionsClient) {
            throw new \Exception('Dialogflow client not initialized. Please check your configuration.');
        }
        
        try {
            // Format session path
            $session = $this->sessionsClient->sessionName($this->projectId, $sessionId);

            // Create text input
            $textInput = new TextInput();
            $textInput->setText($text);
            $textInput->setLanguageCode($this->languageCode);

            // Create query input
            $queryInput = new QueryInput();
            $queryInput->setText($textInput);

            // Add query parameters if context is provided
            $queryParameters = new QueryParameters();
            if (!empty($context)) {
                // Add contexts to the query parameters
                foreach ($context as $contextName => $contextData) {
                    // You can add specific context handling here
                }
            }

            // Create detect intent request
            $request = new DetectIntentRequest();
            $request->setSession($session);
            $request->setQueryInput($queryInput);
            $request->setQueryParams($queryParameters);

            // Detect intent
            $response = $this->sessionsClient->detectIntent($request);

            $queryResult = $response->getQueryResult();

            return [
                'query_text' => $queryResult->getQueryText(),
                'intent' => [
                    'name' => $queryResult->getIntent()->getDisplayName(),
                    'confidence' => $queryResult->getIntentDetectionConfidence(),
                ],
                'fulfillment_text' => $queryResult->getFulfillmentText(),
                'parameters' => $this->extractParameters($queryResult->getParameters()),
                'contexts' => $this->extractContexts($queryResult->getOutputContexts()),
                'webhook_payload' => $queryResult->getWebhookPayload(),
            ];

        } catch (\Exception $e) {
            Log::error('Dialogflow intent detection failed', [
                'text' => $text,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to detect intent: ' . $e->getMessage());
        }
    }

    /**
     * Create a Dialogflow session for a user
     */
    public function createSession(int $userId, string $context = 'general'): string
    {
        return "studentlink-{$context}-{$userId}-" . time();
    }

    /**
     * Handle FAQ queries
     */
    public function handleFaq(string $question, int $userId): array
    {
        $sessionId = $this->createSession($userId, 'faq');
        
        try {
            $response = $this->detectIntent($question, $sessionId, [
                'studentlink-context' => [
                    'user_id' => $userId,
                    'context_type' => 'faq',
                ]
            ]);

            // Enhance response with StudentLink-specific information
            if ($response['intent']['confidence'] < 0.7) {
                // Fall back to general help response
                return [
                    'response' => $this->getGenericHelpResponse(),
                    'intent' => 'fallback',
                    'confidence' => 0,
                    'suggestions' => $this->getHelpSuggestions(),
                ];
            }

            return [
                'response' => $response['fulfillment_text'],
                'intent' => $response['intent']['name'],
                'confidence' => $response['intent']['confidence'],
                'parameters' => $response['parameters'],
                'suggestions' => $this->getContextualSuggestions($response['intent']['name']),
            ];

        } catch (\Exception $e) {
            Log::error('Dialogflow FAQ handling failed', [
                'question' => $question,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'response' => $this->getGenericHelpResponse(),
                'intent' => 'error',
                'confidence' => 0,
                'error' => 'Unable to process your question at the moment.',
            ];
        }
    }

    /**
     * Extract parameters from Dialogflow response
     */
    private function extractParameters($parameters): array
    {
        $extracted = [];
        
        if ($parameters) {
            foreach ($parameters->getFields() as $key => $value) {
                $extracted[$key] = $this->extractParameterValue($value);
            }
        }

        return $extracted;
    }

    /**
     * Extract parameter value based on its type
     */
    private function extractParameterValue($value)
    {
        switch ($value->getKind()) {
            case 'string_value':
                return $value->getStringValue();
            case 'number_value':
                return $value->getNumberValue();
            case 'bool_value':
                return $value->getBoolValue();
            case 'list_value':
                $list = [];
                foreach ($value->getListValue()->getValues() as $item) {
                    $list[] = $this->extractParameterValue($item);
                }
                return $list;
            default:
                return null;
        }
    }

    /**
     * Extract contexts from Dialogflow response
     */
    private function extractContexts($contexts): array
    {
        $extracted = [];
        
        foreach ($contexts as $context) {
            $contextName = basename($context->getName());
            $extracted[$contextName] = [
                'lifespan_count' => $context->getLifespanCount(),
                'parameters' => $this->extractParameters($context->getParameters()),
            ];
        }

        return $extracted;
    }

    /**
     * Get generic help response
     */
    private function getGenericHelpResponse(): string
    {
        return "I'm here to help you with StudentLink! You can ask me about submitting concerns, checking announcements, emergency contacts, or general system questions. How can I assist you today?";
    }

    /**
     * Get general help suggestions
     */
    private function getHelpSuggestions(): array
    {
        return [
            'How do I submit a concern?',
            'Where can I check announcements?',
            'What are the emergency contacts?',
            'How do I track my concern status?',
            'Who can I contact for technical support?',
        ];
    }

    /**
     * Get contextual suggestions based on intent
     */
    private function getContextualSuggestions(string $intentName): array
    {
        return match($intentName) {
            'concern.submit' => [
                'What information do I need to submit a concern?',
                'Can I submit an anonymous concern?',
                'How long does it take to process a concern?',
            ],
            'concern.status' => [
                'How do I get updates on my concern?',
                'Who is handling my concern?',
                'Can I add more information to my concern?',
            ],
            'announcement.check' => [
                'How do I bookmark important announcements?',
                'Are announcements sent to my email?',
                'Can I filter announcements by department?',
            ],
            'emergency.contact' => [
                'What should I do in a medical emergency?',
                'How do I report a security issue?',
                'Where is the nearest clinic?',
            ],
            default => $this->getHelpSuggestions(),
        };
    }

    /**
     * Validate Dialogflow configuration
     */
    public function validateConfiguration(): bool
    {
        try {
            $sessionId = 'test-session-' . time();
            $this->detectIntent('Hello', $sessionId);
            return true;
        } catch (\Exception $e) {
            Log::error('Dialogflow configuration validation failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
