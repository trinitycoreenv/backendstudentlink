<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Twilio Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Twilio SMS service integration
    |
    */

    'sid' => env('TWILIO_SID'),
    'token' => env('TWILIO_TOKEN'),
    'from' => env('TWILIO_FROM'),
    
    /*
    |--------------------------------------------------------------------------
    | SMS Settings
    |--------------------------------------------------------------------------
    |
    | Configure SMS service settings
    |
    */

    'sms' => [
        'enabled' => env('TWILIO_SMS_ENABLED', true),
        'default_country_code' => env('TWILIO_DEFAULT_COUNTRY_CODE', '+63'),
        'max_length' => env('TWILIO_SMS_MAX_LENGTH', 160),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Configure webhook endpoints for Twilio callbacks
    |
    */

    'webhook' => [
        'url' => env('TWILIO_WEBHOOK_URL'),
        'secret' => env('TWILIO_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for SMS sending
    |
    */

    'rate_limit' => [
        'enabled' => env('TWILIO_RATE_LIMIT_ENABLED', true),
        'max_per_minute' => env('TWILIO_MAX_PER_MINUTE', 10),
        'max_per_hour' => env('TWILIO_MAX_PER_HOUR', 100),
    ],
];