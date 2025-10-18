<?php

return [
    /*
    |--------------------------------------------------------------------------
    | N8N Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for n8n workflow automation integration
    |
    */

    'enabled' => env('N8N_ENABLED', true),

    'webhook_url' => env('N8N_WEBHOOK_URL', 'https://studentlinkbcp.app.n8n.cloud/webhook-test'),

    'api_key' => env('N8N_API_KEY'),

    'workflows' => [
        'concern_classification' => [
            'enabled' => env('N8N_CONCERN_CLASSIFICATION_ENABLED', true),
            'webhook_path' => 'ticket-urgency-classification-corrected',
            'confidence_threshold' => 0.7,
            'auto_update_priority' => true,
        ],
        'auto_reply_faq' => [
            'enabled' => env('N8N_AUTO_REPLY_FAQ_ENABLED', true),
            'webhook_path' => 'auto-reply-to-faqs',
            'confidence_threshold' => 0.8,
            'auto_send_reply' => true,
        ],
        'assignment_reminder' => [
            'enabled' => env('N8N_ASSIGNMENT_REMINDER_ENABLED', true),
            'webhook_path' => 'assignment-sms-reminder',
            'reminder_intervals' => [
                'deadline_approaching' => 24, // hours before deadline
                'overdue' => 0, // hours after deadline
                'escalation' => 72, // hours after deadline
            ],
        ],
    ],

    'ai_services' => [
        // Anthropic removed - using Hugging Face instead
        'cohere' => [
            'api_key' => env('COHERE_API_KEY'),
            'model' => env('COHERE_MODEL', 'embed-english-v3.0'),
        ],
        'pinecone' => [
            'api_key' => env('PINECONE_API_KEY'),
            'environment' => env('PINECONE_ENVIRONMENT'),
            'index_name' => env('PINECONE_INDEX_NAME', 'studentlink-concerns'),
        ],
    ],

    'notification_channels' => [
        'email' => [
            'enabled' => env('N8N_EMAIL_NOTIFICATIONS_ENABLED', true),
            'from_address' => env('N8N_FROM_EMAIL', 'noreply@studentlink.edu'),
        ],
        'sms' => [
            'enabled' => env('N8N_SMS_NOTIFICATIONS_ENABLED', true),
            'provider' => env('N8N_SMS_PROVIDER', 'twilio'),
        ],
        'push' => [
            'enabled' => env('N8N_PUSH_NOTIFICATIONS_ENABLED', true),
            'firebase_project_id' => env('FIREBASE_PROJECT_ID'),
        ],
    ],

    'logging' => [
        'enabled' => env('N8N_LOGGING_ENABLED', true),
        'level' => env('N8N_LOG_LEVEL', 'info'),
        'channel' => env('N8N_LOG_CHANNEL', 'daily'),
    ],

    'security' => [
        'webhook_secret' => env('N8N_WEBHOOK_SECRET'),
        'rate_limiting' => [
            'enabled' => env('N8N_RATE_LIMITING_ENABLED', true),
            'max_requests' => env('N8N_RATE_LIMIT_MAX_REQUESTS', 100),
            'per_minutes' => env('N8N_RATE_LIMIT_PER_MINUTES', 60),
        ],
    ],

    'monitoring' => [
        'enabled' => env('N8N_MONITORING_ENABLED', true),
        'health_check_interval' => env('N8N_HEALTH_CHECK_INTERVAL', 300), // seconds
        'alert_on_failure' => env('N8N_ALERT_ON_FAILURE', true),
        'alert_recipients' => explode(',', env('N8N_ALERT_RECIPIENTS', '')),
    ],
];
