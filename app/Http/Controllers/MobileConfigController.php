<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MobileConfigController extends Controller
{
    /**
     * Get mobile app configuration
     * This endpoint provides configuration data for the mobile app
     * including API keys, feature flags, and other settings
     */
    public function getConfig(): JsonResponse
    {
        try {
            $config = [
                'success' => true,
                'data' => [
                    'app' => [
                        'name' => config('app.name', 'StudentLink'),
                        'version' => '1.0.0',
                        'environment' => config('app.env', 'production'),
                    ],
                    
                    'api' => [
                        'base_url' => config('app.url') . '/api',
                        'timeout' => 30,
                    ],
                    
                    'firebase' => [
                        'project_id' => config('services.firebase.project_id', 'studentlinkbcp0'),
                        'api_key' => config('services.firebase.api_key'),
                        'app_id' => config('services.firebase.app_id'),
                        'messaging_sender_id' => config('services.firebase.messaging_sender_id'),
                        'storage_bucket' => config('services.firebase.storage_bucket'),
                    ],
                    
                    'pusher' => [
                        'app_id' => config('broadcasting.connections.pusher.app_id'),
                        'app_key' => config('broadcasting.connections.pusher.key'),
                        'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                        // Note: app_secret is not included for security
                    ],
                    
                    'ai' => [
                        'enabled' => config('services.openrouter.enabled', true),
                        'base_url' => config('services.openrouter.base_url'),
                        // Note: API key is not included for security - handled server-side
                    ],
                    
                    'features' => [
                        'ai_features' => config('app.features.ai_enabled', true),
                        'push_notifications' => config('app.features.push_notifications', true),
                        'real_time_updates' => config('app.features.real_time_updates', true),
                        'file_uploads' => config('app.features.file_uploads', true),
                        'biometric_auth' => config('app.features.biometric_auth', true),
                    ],
                    
                    'college' => [
                        'name' => config('app.college.name', 'STUDENTLINK'),
                        'code' => config('app.college.code', 'STUDENTLINK'),
                        'email' => config('app.college.email', 'info@bestlink.edu.ph'),
                        'phone' => config('app.college.phone', '+63-2-8123-4567'),
                        'address' => config('app.college.address', '123 Education Street, Quezon City, Philippines'),
                    ],
                    
                    'emergency' => [
                        'medical' => config('app.emergency.medical', '911'),
                        'security' => config('app.emergency.security', '117'),
                        'fire' => config('app.emergency.fire', '116'),
                    ],
                    
                    'file_upload' => [
                        'max_size' => config('app.upload.max_size', 25 * 1024 * 1024), // 25MB
                        'allowed_types' => config('app.upload.allowed_types', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']),
                    ],
                    
                    'debug' => [
                        'enabled' => config('app.debug', false),
                        'api_logging' => config('app.debug_api', false),
                    ],
                ],
                'timestamp' => now()->toISOString(),
            ];
            
            return response()->json($config);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load configuration',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Get AI configuration for mobile app
     * This endpoint provides AI-related configuration
     */
    public function getAiConfig(): JsonResponse
    {
        try {
            $config = [
                'success' => true,
                'data' => [
                    'enabled' => config('services.openrouter.enabled', true),
                    'base_url' => config('services.openrouter.base_url'),
                    'models' => [
                        'default' => config('services.openrouter.default_model', 'deepseek/deepseek-r1:free'),
                        'fast' => config('services.openrouter.fast_model', 'grok-beta:free'),
                        'creative' => config('services.openrouter.creative_model', 'meta-llama/llama-3.1-8b-instruct:free'),
                    ],
                    'settings' => [
                        'max_tokens' => config('services.openrouter.max_tokens', 300),
                        'temperature' => config('services.openrouter.temperature', 0.7),
                        'timeout' => config('services.openrouter.timeout', 30),
                    ],
                ],
                'timestamp' => now()->toISOString(),
            ];
            
            return response()->json($config);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load AI configuration',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
