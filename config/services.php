<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],


    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'studentlinkbcp0'),
        'api_key' => env('FIREBASE_API_KEY', 'AIzaSyD67rYi1UhLY2oEYvkF9g0zGAAwFpTkxB8'),
        'app_id' => env('FIREBASE_APP_ID', '1:760317150683:android:bb2a282c5b068de6ded3fe'),
        'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID', '760317150683'),
        'storage_bucket' => env('FIREBASE_STORAGE_BUCKET', 'studentlinkbcp0.firebasestorage.app'),
        'private_key' => env('FIREBASE_PRIVATE_KEY'),
        'client_email' => env('FIREBASE_CLIENT_EMAIL'),
        'database_url' => env('FIREBASE_DATABASE_URL'),
    ],

    'dialogflow' => [
        'project_id' => env('DIALOGFLOW_PROJECT_ID'),
        'private_key' => env('DIALOGFLOW_PRIVATE_KEY'),
        'client_email' => env('DIALOGFLOW_CLIENT_EMAIL'),
        'language_code' => env('DIALOGFLOW_LANGUAGE_CODE', 'en'),
        'session_id' => env('DIALOGFLOW_SESSION_ID', 'default-session'),
    ],

    'huggingface' => [
        'api_key' => env('HUGGINGFACE_API_KEY'),
        'base_url' => env('HUGGINGFACE_BASE_URL', 'https://api-inference.huggingface.co/models'),
        'model' => env('HUGGINGFACE_MODEL', 'microsoft/DialoGPT-medium'),
        'max_length' => env('HUGGINGFACE_MAX_LENGTH', 150),
        'temperature' => env('HUGGINGFACE_TEMPERATURE', 0.7),
        'timeout' => env('HUGGINGFACE_TIMEOUT', 30),
    ],

    'openrouter' => [
        'enabled' => env('OPENROUTER_ENABLED', true),
        'api_key' => env('OPENROUTER_API_KEY', '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'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'google/gemini-2.0-flash-exp:free'),
        'fast_model' => env('OPENROUTER_FAST_MODEL', 'grok-beta:free'),
        'creative_model' => env('OPENROUTER_CREATIVE_MODEL', 'google/gemini-2.0-flash-exp:free'),
        'max_tokens' => env('OPENROUTER_MAX_TOKENS', 300),
        'temperature' => env('OPENROUTER_TEMPERATURE', 0.7),
        'timeout' => env('OPENROUTER_TIMEOUT', 30),
    ],

];
