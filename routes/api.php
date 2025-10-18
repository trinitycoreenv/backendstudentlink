<?php

use App\Http\Controllers\{
    AuthController,
    ConcernController,
    ConcernFeedbackController,
    AnnouncementController,
    UserController,
    DepartmentController,
    StaffController,
    EmergencyController,
    NotificationController,
    AiController,
    ChatbotTrainingController,
    AnalyticsController,
    AdvancedAnalyticsController,
    CrossDepartmentController,
    SystemController,
    StudentRegistrationController,
    OtpVerificationController,
    TwilioWebhookController,
    ProfileController,
    ForgotPasswordController,
    DepartmentDashboardController,
    ChatController,
    N8nController,
    MobileConfigController
};

use App\Services\FirebaseService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::get('/test', function () {
    return response()->json(['status' => 'ok', 'message' => 'API is working']);
});
Route::get('/health', [SystemController::class, 'health']);
Route::get('/health/detailed', [SystemController::class, 'detailedHealth']);

// Mobile app configuration endpoints (public - no authentication required)
Route::get('/config', [MobileConfigController::class, 'getConfig']);
Route::get('/config/ai', [MobileConfigController::class, 'getAiConfig']);

// AI endpoints (public - no authentication required)
Route::post('/ai/writing-assistance', [AiController::class, 'generateWritingAssistance']);
Route::post('/ai/chat', [AiController::class, 'chat']);

// File upload route (public for now, will be protected later)
Route::post('/upload', [SystemController::class, 'uploadFile']);

// N8N Automation Public Endpoints (no authentication required)
Route::prefix('n8n')->group(function () {
    // Test endpoint
    Route::get('/test', function () {
        return response()->json(['status' => 'ok', 'message' => 'N8N endpoint working']);
    });
    
    // Concerns endpoints for N8N automation
    Route::get('/concerns', function () {
        try {
            $concerns = \App\Models\Concern::with(['student', 'department', 'assignedTo', 'approvedBy'])->get();
            
            // Format response for N8N
            $formattedConcerns = $concerns->map(function ($concern) {
                return [
                    'id' => $concern->id,
                    'reference_number' => $concern->reference_number,
                    'subject' => $concern->subject,
                    'description' => $concern->description,
                    'status' => $concern->status,
                    'priority' => $concern->priority,
                    'type' => $concern->type,
                    'department_id' => $concern->department_id,
                    'department_name' => $concern->department->name ?? 'Unknown',
                    'student_id' => $concern->student_id,
                    'student_name' => $concern->is_anonymous ? 'Anonymous' : ($concern->student->name ?? 'Unknown'),
                    'assigned_to' => $concern->assigned_to,
                    'assigned_staff_name' => $concern->assignedTo->name ?? null,
                    'approved_by' => $concern->approved_by,
                    'approved_by_name' => $concern->approvedBy->name ?? null,
                    'created_at' => $concern->created_at->toISOString(),
                    'updated_at' => $concern->updated_at->toISOString(),
                    'last_activity_at' => $concern->last_activity_at?->toISOString(),
                    'escalated_at' => $concern->escalated_at?->toISOString(),
                    'escalated_by' => $concern->escalated_by,
                    'escalation_reason' => $concern->escalation_reason,
                    'auto_approved' => $concern->auto_approved,
                    'auto_closed' => $concern->auto_closed,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedConcerns,
                'total' => $concerns->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch concerns for N8N',
                'error' => $e->getMessage()
            ], 500);
        }
    });
    Route::get('/concerns/{concern}', [ConcernController::class, 'show']);
    Route::post('/concerns/{concern}/approve', [ConcernController::class, 'approve']);
    Route::post('/concerns/{concern}/reject', [ConcernController::class, 'reject']);
    Route::patch('/concerns/{concern}/status', [ConcernController::class, 'updateStatus']);
    Route::post('/concerns/{concern}/assign', [ConcernController::class, 'assign']);
    
    // Notifications endpoints for N8N automation
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::post('/notifications/send-email', [NotificationController::class, 'sendEmail']);
    Route::post('/notifications/send-sms', [NotificationController::class, 'sendSms']);
    
    // Analytics endpoints for N8N automation
    Route::get('/analytics/predictive-data', [AnalyticsController::class, 'getPredictiveData']);
    Route::post('/analytics/store-insights', [AnalyticsController::class, 'storeInsights']);
    Route::post('/analytics/update-predictions', [AnalyticsController::class, 'updatePredictions']);
    
    // Cross-department endpoints for N8N automation
    Route::get('/staff/cross-department/available', [StaffController::class, 'getCrossDepartmentAvailable']);
    Route::post('/cross-department/activate-emergency', [CrossDepartmentController::class, 'activateEmergency']);
    
    // AI endpoints for N8N automation
    Route::post('/ai/classify-concern', [AiController::class, 'classifyConcern']);
    
    // Firebase endpoints for N8N automation
    Route::post('/firebase/send-to-user', [FirebaseService::class, 'sendToUser']);
});

// Public routes for mobile app (no authentication required)
Route::get('/departments', [DepartmentController::class, 'index']);
Route::get('/emergency/contacts', [EmergencyController::class, 'getContacts']);
Route::get('/announcements/categories', [AnnouncementController::class, 'getCategories']);
Route::get('/emergency/protocols', [EmergencyController::class, 'getProtocols']);

// Student Registration routes (public)
Route::prefix('registration')->group(function () {
    Route::post('/generate-id', [StudentRegistrationController::class, 'generateStudentId']);
    Route::post('/validate', [StudentRegistrationController::class, 'validateRegistrationData']);
    Route::post('/send-email-otp', [StudentRegistrationController::class, 'sendEmailOtp']);
    Route::post('/send-phone-otp', [StudentRegistrationController::class, 'sendPhoneOtp']);
    Route::post('/verify-otps', [StudentRegistrationController::class, 'verifyOtps']);
    Route::post('/create', [StudentRegistrationController::class, 'createStudentAccount']);
    Route::get('/status/{studentId}', [StudentRegistrationController::class, 'getRegistrationStatus']);
    Route::post('/cleanup', [StudentRegistrationController::class, 'cleanupExpiredReservations']);
});

// Twilio webhook routes (public)
Route::prefix('twilio')->group(function () {
    Route::post('/webhook', [TwilioWebhookController::class, 'handle']);
    Route::get('/delivery-stats', [TwilioWebhookController::class, 'getDeliveryStats']);
});

// OTP Verification routes (public)
Route::prefix('otp')->group(function () {
    Route::post('/send-email', [OtpVerificationController::class, 'sendEmailOtp']);
    Route::post('/send-sms', [OtpVerificationController::class, 'sendSmsOtp']);
    Route::post('/verify', [OtpVerificationController::class, 'verifyOtp']);
    Route::post('/check-status', [OtpVerificationController::class, 'checkVerificationStatus']);
    Route::post('/rate-limit', [OtpVerificationController::class, 'getRateLimitStatus']);
    Route::post('/cleanup', [OtpVerificationController::class, 'cleanupExpiredOtps']);
});

// Forgot Password routes (public)
Route::prefix('auth')->group(function () {
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetCode']);
    Route::post('/verify-reset-code', [ForgotPasswordController::class, 'verifyResetCode']);
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword']);
});

// Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    
    // Concerns Management
    Route::prefix('concerns')->group(function () {
        Route::get('/', [ConcernController::class, 'index']);
        Route::post('/', [ConcernController::class, 'store']);
        Route::get('/assignment-stats', [ConcernController::class, 'getAssignmentStats']);
        Route::get('/priority-detection-stats', [ConcernController::class, 'getPriorityDetectionStats']);
        Route::get('/workflow-stats', [ConcernController::class, 'getWorkflowStats']);
        Route::get('/{concern}', [ConcernController::class, 'show']);
        Route::put('/{concern}', [ConcernController::class, 'update']);
        Route::delete('/{concern}', [ConcernController::class, 'destroy']);
        
        // Concern messages
        Route::post('/{concern}/messages', [ConcernController::class, 'addMessage']);
        Route::get('/{concern}/messages', [ConcernController::class, 'getMessages']);
        
        // Concern actions
        Route::patch('/{concern}/status', [ConcernController::class, 'updateStatus']);
        Route::post('/{concern}/assign', [ConcernController::class, 'assign']);
        Route::get('/{concern}/history', [ConcernController::class, 'getHistory']);
        
        // Concern approval workflow
        Route::post('/{concern}/approve', [ConcernController::class, 'approve']);
        Route::post('/{concern}/reject', [ConcernController::class, 'reject']);
        
        // File uploads
        Route::post('/{concern}/attachments', [ConcernController::class, 'uploadAttachment']);
        Route::delete('/{concern}/attachments/{attachmentId}', [ConcernController::class, 'deleteAttachment']);
        
        // Student resolution actions
        Route::post('/{concern}/confirm-resolution', [ConcernController::class, 'confirmResolution']);
        Route::post('/{concern}/dispute-resolution', [ConcernController::class, 'disputeResolution']);
        
        // Concern feedback
        Route::post('/{concern}/feedback', [ConcernFeedbackController::class, 'store']);
        Route::get('/{concern}/feedback', [ConcernFeedbackController::class, 'show']);
        Route::put('/{concern}/feedback/{feedback}', [ConcernFeedbackController::class, 'update']);
    });

    // Real-time Chat Management
    Route::prefix('chat')->group(function () {
        Route::get('/rooms', [ChatController::class, 'getActiveChatRooms']);
        Route::get('/rooms/{concern}/get-or-create', [ChatController::class, 'getOrCreateChatRoom']);
        Route::post('/rooms/{chatRoom}/messages', [ChatController::class, 'sendMessage']);
        Route::get('/rooms/{chatRoom}/messages', [ChatController::class, 'getMessages']);
        Route::post('/rooms/{chatRoom}/mark-read', [ChatController::class, 'markAsRead']);
        Route::post('/rooms/{chatRoom}/close', [ChatController::class, 'closeChatRoom']);
    });

    // Announcements Management
    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index']);
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::get('/{announcement}', [AnnouncementController::class, 'show']);
        Route::put('/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('/{announcement}', [AnnouncementController::class, 'destroy']);
        
        // Announcement actions
        Route::post('/{announcement}/bookmark', [AnnouncementController::class, 'bookmark']);
        Route::delete('/{announcement}/bookmark', [AnnouncementController::class, 'removeBookmark']);
        Route::get('/user/bookmarks', [AnnouncementController::class, 'getBookmarks']);
        
        // Image download
        Route::get('/{announcement}/image/download', [AnnouncementController::class, 'downloadImage']);
        
        // Analytics and tracking
        Route::post('/{announcement}/track/view', [AnnouncementController::class, 'trackView']);
        Route::post('/{announcement}/track/share', [AnnouncementController::class, 'trackShare']);
        Route::get('/{announcement}/analytics', [AnnouncementController::class, 'getAnalytics']);
        
        // Enhanced announcement management
        Route::post('/image-only', [AnnouncementController::class, 'createImageAnnouncement']);
        Route::post('/bulk-upload', [AnnouncementController::class, 'bulkUpload']);
        Route::post('/{announcement}/moderate', [AnnouncementController::class, 'moderate']);
    });

    // User Management (Admin/Department Head only)
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])
            ->middleware('role:admin,department_head');
        Route::post('/', [UserController::class, 'store'])
            ->middleware('role:admin');
        Route::get('/{user}', [UserController::class, 'show']);
        Route::put('/{user}', [UserController::class, 'update']);
        Route::delete('/{user}', [UserController::class, 'destroy'])
            ->middleware('role:admin');
        
        // User profile
        Route::get('/profile/me', [UserController::class, 'profile']);
        Route::put('/profile/me', [UserController::class, 'updateProfile']);
        Route::post('/profile/avatar', [UserController::class, 'uploadAvatar']);
    });

    // Profile Management routes
    Route::prefix('profile')->group(function () {
        Route::get('/me', [UserController::class, 'profile']);
        Route::put('/me', [UserController::class, 'updateProfile']);
        Route::post('/avatar', [UserController::class, 'uploadAvatar']);
        Route::put('/update', [ProfileController::class, 'updateProfile']);
        Route::post('/send-verification-code', [ProfileController::class, 'sendVerificationCode']);
        Route::post('/verify-code', [ProfileController::class, 'verifyCode']);
        Route::post('/change-password', [ProfileController::class, 'changePassword']);
    });

    // Department Dashboard routes (Department Head only)
    Route::prefix('department-dashboard')->group(function () {
        Route::get('/stats', [DepartmentDashboardController::class, 'getDashboardStats']);
        Route::get('/concerns', [DepartmentDashboardController::class, 'getDepartmentConcerns']);
        Route::get('/users', [DepartmentDashboardController::class, 'getDepartmentUsers']);
    });

    // Department Management (protected routes)
    Route::prefix('departments')->group(function () {
        Route::post('/', [DepartmentController::class, 'store'])
            ->middleware('role:admin');
        Route::get('/{department}', [DepartmentController::class, 'show']);
        Route::put('/{department}', [DepartmentController::class, 'update'])
            ->middleware('role:admin');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])
            ->middleware('role:admin');
        
        // Department statistics
        Route::get('/{department}/stats', [DepartmentController::class, 'getStats']);
        Route::get('/{department}/concerns', [DepartmentController::class, 'getConcerns']);
        Route::get('/{department}/users', [DepartmentController::class, 'getUsers']);
    });

    // Staff Management (protected routes)
    Route::prefix('staff')->group(function () {
        // Staff-specific concern management (must come before /{staff} route)
        Route::get('/my-concerns', [StaffController::class, 'getMyConcerns'])
            ->middleware('role:staff');
        Route::get('/my-archived-concerns', [StaffController::class, 'getMyArchivedConcerns'])
            ->middleware('role:staff');
        Route::get('/my-dashboard-stats', [StaffController::class, 'getMyDashboardStats'])
            ->middleware('role:staff');
        Route::patch('/concerns/{concern}/status', [StaffController::class, 'updateConcernStatus'])
            ->middleware('role:staff');
        Route::post('/concerns/{concern}/messages', [StaffController::class, 'addConcernMessage'])
            ->middleware('role:staff');
        
        // General staff management routes
        Route::get('/', [StaffController::class, 'index'])
            ->middleware('role:admin,department_head');
        Route::post('/', [StaffController::class, 'store'])
            ->middleware('role:admin,department_head');
        Route::get('/available', [StaffController::class, 'getAvailableStaff'])
            ->middleware('role:admin,department_head,staff');
        Route::get('/workload-stats', [StaffController::class, 'getWorkloadStats'])
            ->middleware('role:admin,department_head');
        Route::get('/{staff}', [StaffController::class, 'show'])
            ->middleware('role:admin,department_head');
        Route::put('/{staff}', [StaffController::class, 'update'])
            ->middleware('role:admin,department_head');
    });

// AI Classification Routes
Route::prefix('ai')->group(function () {
    Route::post('/classify-concern', [\App\Http\Controllers\AIController::class, 'classifyConcern']);
    Route::get('/classification-stats', [\App\Http\Controllers\AIController::class, 'getClassificationStats'])
        ->middleware('role:admin,department_head');
});


// Concern AI Classification
Route::post('/concerns/{concern}/ai-classify', [ConcernController::class, 'triggerAIClassification'])
    ->middleware('role:admin,department_head');

// Cross-Department Staff Sharing Routes
Route::prefix('staff/cross-department')->group(function () {
    Route::get('/available', [\App\Http\Controllers\CrossDepartmentController::class, 'getAvailableStaff'])
        ->middleware('role:admin,department_head');
    Route::post('/assign', [\App\Http\Controllers\CrossDepartmentController::class, 'assignStaff'])
        ->middleware('role:admin,department_head');
    Route::get('/assignments', [\App\Http\Controllers\CrossDepartmentController::class, 'getAssignments'])
        ->middleware('role:admin,department_head,staff');
    Route::patch('/assignments/{assignment}/complete', [\App\Http\Controllers\CrossDepartmentController::class, 'completeAssignment'])
        ->middleware('role:admin,department_head,staff');
    Route::post('/cleanup', [\App\Http\Controllers\CrossDepartmentController::class, 'cleanupExpiredAssignments'])
        ->middleware('role:admin');
});

// Smart Assignment Routes
Route::prefix('smart-assignment')->group(function () {
    Route::get('/analytics', [\App\Http\Controllers\SmartAssignmentController::class, 'getAnalytics'])
        ->middleware('role:admin,department_head');
    Route::post('/rebalance', [\App\Http\Controllers\SmartAssignmentController::class, 'rebalanceWorkload'])
        ->middleware('role:admin,department_head');
    Route::get('/suggest-assignee', [\App\Http\Controllers\SmartAssignmentController::class, 'suggestAssignee'])
        ->middleware('role:admin,department_head');
});

// Escalation Routes
Route::prefix('escalation')->group(function () {
    Route::post('/check', [\App\Http\Controllers\EscalationController::class, 'checkAndEscalate'])
        ->middleware('role:admin');
    Route::get('/stats', [\App\Http\Controllers\EscalationController::class, 'getEscalationStats'])
        ->middleware('role:admin,department_head');
    Route::post('/concerns/{concern}/escalate', [\App\Http\Controllers\EscalationController::class, 'manualEscalate'])
        ->middleware('role:admin,department_head');
});

// Performance Analytics Routes
Route::prefix('analytics')->group(function () {
    Route::get('/performance', [\App\Http\Controllers\PerformanceAnalyticsController::class, 'getPerformanceAnalytics'])
        ->middleware('role:admin,department_head');
    Route::get('/workload-distribution', [\App\Http\Controllers\PerformanceAnalyticsController::class, 'getStaffWorkloadDistribution'])
        ->middleware('role:admin,department_head');
    Route::get('/export', [\App\Http\Controllers\PerformanceAnalyticsController::class, 'exportAnalytics'])
        ->middleware('role:admin,department_head');
});

    // Emergency Help (protected routes)
    Route::prefix('emergency')->group(function () {
        // Admin only routes
        Route::middleware('role:admin')->group(function () {
            Route::post('/contacts', [EmergencyController::class, 'createContact']);
            Route::put('/contacts/{contact}', [EmergencyController::class, 'updateContact']);
            Route::delete('/contacts/{contact}', [EmergencyController::class, 'deleteContact']);
            
            Route::post('/protocols', [EmergencyController::class, 'createProtocol']);
            Route::put('/protocols/{protocol}', [EmergencyController::class, 'updateProtocol']);
            Route::delete('/protocols/{protocol}', [EmergencyController::class, 'deleteProtocol']);
            
            // Additional emergency management routes
            Route::get('/settings', [EmergencyController::class, 'getSettings']);
            Route::put('/settings', [EmergencyController::class, 'updateSettings']);
            Route::post('/broadcast', [EmergencyController::class, 'broadcastAlert']);
            Route::get('/stats', [EmergencyController::class, 'getStats']);
        });
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/stats', [NotificationController::class, 'getStats']);
        Route::post('/mark-read', [NotificationController::class, 'markAsRead']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/', [NotificationController::class, 'delete']);
        
        // FCM token management
        Route::post('/fcm-token', [NotificationController::class, 'storeFcmToken']);
        Route::delete('/fcm-token', [NotificationController::class, 'removeFcmToken']);
        Route::get('/fcm-tokens', [NotificationController::class, 'getFcmTokens']);
        
        // Test notification (development only)
        Route::post('/test', [NotificationController::class, 'sendTestNotification']);
    });

    // AI Features
    Route::prefix('ai')->group(function () {
        Route::post('/suggestions', [AiController::class, 'getSuggestions']);
        Route::post('/transcribe', [AiController::class, 'transcribeAudio']);
        
        // AI session management
        Route::get('/sessions', [AiController::class, 'getSessions']);
        Route::post('/sessions', [AiController::class, 'createSession']);
        Route::get('/sessions/{session}', [AiController::class, 'getSession']);
        Route::delete('/sessions/{session}', [AiController::class, 'deleteSession']);
        
        // AI Chatbot Management (Admin only)
        Route::middleware('role:admin')->group(function () {
            Route::get('/settings', [AiController::class, 'getSettings']);
            Route::put('/settings', [AiController::class, 'updateSettings']);
            Route::post('/train', [AiController::class, 'trainChatbot']);
            Route::get('/analytics', [AiController::class, 'getAnalytics']);
            Route::get('/conversations', [AiController::class, 'getConversations']);
            
            // Dialogflow Configuration
            Route::get('/dialogflow/config', [AiController::class, 'getDialogflowConfig']);
            Route::put('/dialogflow/config', [AiController::class, 'updateDialogflowConfig']);
            
            // Hugging Face Configuration
            Route::get('/huggingface/config', [AiController::class, 'getHuggingFaceConfig']);
            Route::put('/huggingface/config', [AiController::class, 'updateHuggingFaceConfig']);
            
            
            // FAQ Management
            Route::get('/faq', [AiController::class, 'getFAQItems']);
            Route::put('/faq', [AiController::class, 'updateFAQItems']);
            
            // Chat Sessions Management
            Route::get('/chat/sessions', [AiController::class, 'getChatSessions']);
            
            // Chatbot Testing
            Route::post('/test', [AiController::class, 'testChatbot']);
            
            // Chatbot Training Management
            Route::get('/training/data', [ChatbotTrainingController::class, 'getTrainingData']);
            Route::post('/training/data', [ChatbotTrainingController::class, 'addTrainingData']);
            Route::put('/training/data/{id}', [ChatbotTrainingController::class, 'updateTrainingData']);
            
            // Bulk Training Data Upload
            Route::post('/training/bulk-upload', [AiController::class, 'bulkUploadTrainingData']);
            Route::get('/training/batches', [AiController::class, 'getTrainingBatches']);
            Route::get('/training/stats', [AiController::class, 'getTrainingStats']);
            Route::delete('/training/data/{id}', [ChatbotTrainingController::class, 'deleteTrainingData']);
            Route::post('/training/bulk-import', [ChatbotTrainingController::class, 'bulkImport']);
            Route::post('/training/test', [ChatbotTrainingController::class, 'testChatbot']);
            Route::get('/training/analytics', [ChatbotTrainingController::class, 'getTrainingAnalytics']);
        });
    });

    // Analytics & Reports
    Route::prefix('analytics')->group(function () {
        Route::get('/dashboard', [AnalyticsController::class, 'getDashboardStats']);
        Route::get('/concerns', [AnalyticsController::class, 'getConcernStats']);
        Route::get('/departments', [AnalyticsController::class, 'getDepartmentStats']);
        Route::get('/users', [AnalyticsController::class, 'getUserStats']);
        
    // Performance Analytics
    Route::get('/performance', [AnalyticsController::class, 'getPerformanceAnalytics']);
    Route::get('/staff-performance', [AnalyticsController::class, 'getStaffPerformanceMetrics']);
    Route::get('/department-performance', [AnalyticsController::class, 'getDepartmentPerformanceMetrics']);
    Route::get('/concern-trends', [AnalyticsController::class, 'getConcernTrends']);
    Route::get('/response-times', [AnalyticsController::class, 'getResponseTimeAnalytics']);
    Route::get('/escalation-analytics', [AnalyticsController::class, 'getEscalationAnalytics']);
    Route::get('/satisfaction-metrics', [AnalyticsController::class, 'getSatisfactionMetrics']);
    
    // Cross-Department Intelligence
    Route::get('/cross-department/workload-analysis', [CrossDepartmentController::class, 'analyzeWorkloadDistribution']);
    Route::get('/cross-department/optimal-assignments/{departmentId}', [CrossDepartmentController::class, 'findOptimalAssignments']);
    Route::post('/cross-department/execute-assignments', [CrossDepartmentController::class, 'executeAssignments']);
    Route::get('/cross-department/stats', [CrossDepartmentController::class, 'getStats']);
    Route::post('/cross-department/auto-balance', [CrossDepartmentController::class, 'autoBalanceWorkload']);
    Route::get('/cross-department/staff-assignments/{staffId}', [CrossDepartmentController::class, 'getStaffCrossDepartmentAssignments']);
    Route::post('/cross-department/complete-assignment/{assignmentId}', [CrossDepartmentController::class, 'completeAssignment']);
    
    // Advanced Analytics
    Route::get('/advanced-analytics/dashboard', [AdvancedAnalyticsController::class, 'getDashboardAnalytics']);
    Route::get('/advanced-analytics/overview', [AdvancedAnalyticsController::class, 'getOverviewMetrics']);
    Route::get('/advanced-analytics/performance', [AdvancedAnalyticsController::class, 'getPerformanceMetrics']);
    Route::get('/advanced-analytics/trends', [AdvancedAnalyticsController::class, 'getTrendAnalytics']);
    Route::get('/advanced-analytics/departments', [AdvancedAnalyticsController::class, 'getDepartmentAnalytics']);
    Route::get('/advanced-analytics/staff', [AdvancedAnalyticsController::class, 'getStaffAnalytics']);
    Route::get('/advanced-analytics/response-times', [AdvancedAnalyticsController::class, 'getResponseTimeAnalytics']);
    Route::get('/advanced-analytics/satisfaction', [AdvancedAnalyticsController::class, 'getSatisfactionMetrics']);
    Route::get('/advanced-analytics/charts/{chartType}', [AdvancedAnalyticsController::class, 'getChartData']);
    Route::get('/advanced-analytics/charts', [AdvancedAnalyticsController::class, 'getAvailableCharts']);
    Route::post('/advanced-analytics/clear-cache', [AdvancedAnalyticsController::class, 'clearCache']);
        
        // Detailed reports (Admin only)
        Route::middleware('role:admin,department_head')->group(function () {
            Route::get('/reports/concerns', [AnalyticsController::class, 'getConcernReport']);
            Route::get('/reports/departments', [AnalyticsController::class, 'getDepartmentReport']);
            Route::get('/reports/users', [AnalyticsController::class, 'getUserReport']);
            Route::get('/reports/export', [AnalyticsController::class, 'exportReport']);
            
            // Feedback analytics
            Route::get('/feedback/stats', [ConcernFeedbackController::class, 'getStats']);
            Route::get('/feedback/recent', [ConcernFeedbackController::class, 'getRecentFeedback']);
        });
    });

    // System Management (Admin only)
    Route::prefix('system')->middleware('role:admin')->group(function () {
        Route::get('/settings', [SystemController::class, 'getSettings']);
        Route::put('/settings', [SystemController::class, 'updateSettings']);
        Route::get('/audit-logs', [SystemController::class, 'getAuditLogs']);
        Route::get('/audit-logs/stats', [SystemController::class, 'getAuditLogStats']);
        Route::get('/system-info', [SystemController::class, 'getSystemInfo']);
    });

    // Admin Notification Management
    Route::prefix('admin/notifications')->middleware('role:admin')->group(function () {
        Route::get('/stats', [NotificationController::class, 'getNotificationStats']);
        Route::post('/send', [NotificationController::class, 'sendNotification']);
        Route::get('/recent', [NotificationController::class, 'getRecentNotifications']);
        Route::get('/templates', [NotificationController::class, 'getNotificationTemplates']);
        Route::post('/templates', [NotificationController::class, 'createNotificationTemplate']);
        Route::put('/templates/{template}', [NotificationController::class, 'updateNotificationTemplate']);
        Route::delete('/templates/{template}', [NotificationController::class, 'deleteNotificationTemplate']);
    });
});

// N8N Webhook Routes (Public - secured by webhook tokens)
Route::prefix('n8n')->group(function () {
    Route::post('/concern-classification', [N8nController::class, 'classifyConcern']);
    Route::post('/auto-reply', [N8nController::class, 'handleAutoReply']);
    Route::post('/assignment-reminder', [N8nController::class, 'handleAssignmentReminder']);
    
    // Public API Routes for N8N Workflows (No Authentication Required)
    Route::post('/classify-concern', [\App\Http\Controllers\AIController::class, 'classifyConcern']);
    Route::get('/staff/available', [\App\Http\Controllers\StaffController::class, 'getAvailableStaff']);
    Route::post('/concerns/{concern}/assign', [\App\Http\Controllers\ConcernController::class, 'assign']);
    Route::post('/notifications/send', [\App\Http\Controllers\NotificationController::class, 'sendNotification']);
});

// Image Processing Routes (Public - for accessibility features)
Route::prefix('ai/image')->group(function () {
    Route::post('/extract-text', [App\Http\Controllers\ImageProcessingController::class, 'extractText']);
    Route::post('/translate', [App\Http\Controllers\ImageProcessingController::class, 'translateText']);
    Route::post('/identify-language', [App\Http\Controllers\ImageProcessingController::class, 'identifyLanguage']);
    Route::post('/process-with-translation', [App\Http\Controllers\ImageProcessingController::class, 'processImageWithTranslation']);
    Route::get('/supported-languages', [App\Http\Controllers\ImageProcessingController::class, 'getSupportedLanguages']);
});
