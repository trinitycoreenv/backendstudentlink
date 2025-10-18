<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        echo "🛡️ StudentLink Safe Database Setup\n";
        echo "==================================\n\n";

        try {
            // Disable foreign key checks during setup
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            
            echo "📋 Checking and creating missing tables...\n\n";
            
            // 1. DEPARTMENTS TABLE
            if (!Schema::hasTable('departments')) {
                Schema::create('departments', function ($table) {
                    $table->id();
                    $table->string('name');
                    $table->string('code', 10)->unique();
                    $table->text('description')->nullable();
                    $table->enum('type', ['academic', 'administrative', 'support'])->default('academic');
                    $table->boolean('is_active')->default(true);
                    $table->json('contact_info')->nullable();
                    $table->timestamps();
                    
                    $table->index(['type', 'is_active']);
                    $table->index('code');
                    $table->index('name');
                });
                echo "   ✅ Created table: departments\n";
            } else {
                echo "   ⚠️ Table 'departments' already exists, skipping...\n";
            }
            
            // 2. USERS TABLE
            if (!Schema::hasTable('users')) {
                Schema::create('users', function ($table) {
                    $table->id();
                    $table->string('student_id', 20)->unique()->nullable();
                    $table->string('employee_id', 20)->unique()->nullable();
                    $table->string('name');
                    $table->string('email')->unique();
                    $table->string('personal_email')->nullable(); // Personal email for students
                    $table->timestamp('email_verified_at')->nullable();
                    $table->string('password');
                    $table->enum('role', ['student', 'department_head', 'admin'])->default('student');
                    $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
                    $table->string('phone')->nullable();
                    $table->string('avatar')->nullable();
                    $table->json('preferences')->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->timestamp('last_login_at')->nullable();
                    $table->rememberToken();
                    $table->timestamps();
                    
                    $table->index(['role', 'department_id']);
                    $table->index(['email', 'is_active']);
                    $table->index(['personal_email', 'is_active']);
                    $table->index('student_id');
                    $table->index('employee_id');
                    $table->index(['is_active', 'role']);
                });
                echo "   ✅ Created table: users\n";
            } else {
                echo "   ⚠️ Table 'users' already exists, checking for missing columns...\n";
                
                // Check if personal_email column exists, if not add it
                if (!Schema::hasColumn('users', 'personal_email')) {
                    Schema::table('users', function ($table) {
                        $table->string('personal_email')->nullable()->after('email');
                        $table->index(['personal_email', 'is_active']);
                    });
                    echo "   ✅ Added missing column: personal_email\n";
                }
                
                // Check if other missing columns exist and add them
                $missingColumns = [
                    'first_name' => 'string',
                    'middle_name' => 'string',
                    'last_name' => 'string',
                    'suffix' => 'string',
                    'course' => 'string',
                    'year_level' => 'string',
                    'birthday' => 'date',
                    'civil_status' => 'string'
                ];
                
                foreach ($missingColumns as $column => $type) {
                    if (!Schema::hasColumn('users', $column)) {
                        Schema::table('users', function ($table) use ($column, $type) {
                            if ($type === 'string') {
                                $table->string($column)->nullable();
                            } elseif ($type === 'date') {
                                $table->date($column)->nullable();
                            }
                        });
                        echo "   ✅ Added missing column: {$column}\n";
                    }
                }
            }
            
            // 3. FACILITIES TABLE
            if (!Schema::hasTable('facilities')) {
                Schema::create('facilities', function ($table) {
                    $table->id();
                    $table->string('name');
                    $table->string('code', 10)->unique();
                    $table->text('description')->nullable();
                    $table->string('location')->nullable();
                    $table->string('building')->nullable();
                    $table->string('floor')->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->json('operating_hours')->nullable();
                    $table->json('contact_info')->nullable();
                    $table->timestamps();
                    
                    $table->index(['building', 'floor']);
                    $table->index('is_active');
                    $table->index('code');
                });
                echo "   ✅ Created table: facilities\n";
            } else {
                echo "   ⚠️ Table 'facilities' already exists, skipping...\n";
            }
            
            // 4. CONCERNS TABLE
            if (!Schema::hasTable('concerns')) {
                Schema::create('concerns', function ($table) {
                    $table->id();
                    $table->string('reference_number', 20)->unique();
                    $table->string('subject');
                    $table->text('description');
                    $table->enum('type', ['academic', 'administrative', 'technical', 'health', 'safety', 'other'])->default('other');
                    $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
                    $table->enum('status', ['pending', 'approved', 'rejected', 'in_progress', 'resolved', 'closed', 'cancelled'])->default('pending');
                    $table->boolean('is_anonymous')->default(false);
                    
                    // Approval workflow columns
                    $table->text('rejection_reason')->nullable();
                    $table->timestamp('approved_at')->nullable();
                    $table->timestamp('rejected_at')->nullable();
                    $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
                    $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null');
                    
                    $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
                    $table->foreignId('department_id')->constrained()->onDelete('cascade');
                    $table->foreignId('facility_id')->nullable()->constrained()->onDelete('set null');
                    $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
                    
                    $table->json('attachments')->nullable();
                    $table->json('metadata')->nullable();
                    $table->timestamp('due_date')->nullable();
                    $table->timestamp('resolved_at')->nullable();
                    $table->timestamp('closed_at')->nullable();
                    $table->timestamps();
                    
                    $table->index(['status', 'priority']);
                    $table->index(['department_id', 'status']);
                    $table->index(['student_id', 'created_at']);
                    $table->index('reference_number');
                });
                echo "   ✅ Created table: concerns\n";
            } else {
                echo "   ⚠️ Table 'concerns' already exists, skipping...\n";
            }
            
            // 5. CHAT ROOMS TABLE
            if (!Schema::hasTable('chat_rooms')) {
                Schema::create('chat_rooms', function ($table) {
                    $table->id();
                    $table->string('room_id', 50)->unique();
                    $table->foreignId('concern_id')->constrained()->onDelete('cascade');
                    $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
                    $table->foreignId('department_id')->constrained()->onDelete('cascade');
                    $table->enum('status', ['active', 'closed', 'archived'])->default('active');
                    $table->timestamp('last_message_at')->nullable();
                    $table->timestamps();
                    
                    $table->index(['concern_id', 'status']);
                    $table->index(['student_id', 'status']);
                    $table->index(['department_id', 'status']);
                    $table->index('room_id');
                });
                echo "   ✅ Created table: chat_rooms\n";
            } else {
                echo "   ⚠️ Table 'chat_rooms' already exists, skipping...\n";
            }
            
            // 6. CONCERN MESSAGES TABLE (Enhanced for Chat)
            if (!Schema::hasTable('concern_messages')) {
                Schema::create('concern_messages', function ($table) {
                    $table->id();
                    $table->foreignId('concern_id')->constrained()->onDelete('cascade');
                    $table->foreignId('chat_room_id')->nullable()->constrained()->onDelete('cascade');
                    $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
                    $table->enum('sender_type', ['student', 'department_head', 'admin'])->default('student');
                    $table->text('message');
                    $table->enum('message_type', ['text', 'image', 'file', 'system'])->default('text');
                    $table->json('attachments')->nullable();
                    $table->boolean('is_read')->default(false);
                    $table->timestamp('read_at')->nullable();
                    $table->timestamps();
                    
                    $table->index(['concern_id', 'created_at']);
                    $table->index(['chat_room_id', 'created_at']);
                    $table->index(['sender_id', 'created_at']);
                    $table->index('is_read');
                });
                echo "   ✅ Created table: concern_messages\n";
            } else {
                echo "   ⚠️ Table 'concern_messages' already exists, skipping...\n";
            }
            
            // 7. STUDENT ID COUNTERS TABLE
            if (!Schema::hasTable('student_id_counters')) {
                Schema::create('student_id_counters', function ($table) {
                    $table->string('date_key', 8)->primary(); // YYYYMMDD format
                    $table->integer('last_number')->default(0);
                    $table->timestamps();
                    
                    $table->index('date_key');
                });
                echo "   ✅ Created table: student_id_counters\n";
            } else {
                echo "   ⚠️ Table 'student_id_counters' already exists, skipping...\n";
            }
            
            // 8. STUDENT ID RESERVATIONS TABLE
            if (!Schema::hasTable('student_id_reservations')) {
                Schema::create('student_id_reservations', function ($table) {
                    $table->id();
                    $table->string('student_id', 20)->unique();
                    $table->string('date_key', 8); // YYYYMMDD format
                    $table->timestamp('reserved_at');
                    $table->timestamp('expires_at');
                    $table->boolean('is_used')->default(false);
                    $table->timestamps();
                    
                    $table->index(['date_key', 'is_used']);
                    $table->index('expires_at');
                    $table->index('student_id');
                });
                echo "   ✅ Created table: student_id_reservations\n";
            } else {
                echo "   ⚠️ Table 'student_id_reservations' already exists, skipping...\n";
            }
            
            // 9. OTP VERIFICATIONS TABLE
            if (!Schema::hasTable('otp_verifications')) {
                Schema::create('otp_verifications', function ($table) {
                    $table->id();
                    $table->string('identifier'); // Email or phone number
                    $table->string('code', 6); // 6-digit OTP code
                    $table->enum('method', ['email', 'sms']);
                    $table->enum('purpose', ['registration', 'password_reset', 'profile_update', 'login']);
                    $table->timestamp('expires_at');
                    $table->boolean('is_used')->default(false);
                    $table->timestamp('verified_at')->nullable();
                    $table->integer('failed_attempts')->default(0);
                    $table->timestamps();
                    
                    $table->index(['identifier', 'purpose', 'is_used']);
                    $table->index(['identifier', 'expires_at']);
                    $table->index('expires_at');
                    $table->index('created_at');
                });
                echo "   ✅ Created table: otp_verifications\n";
            } else {
                echo "   ⚠️ Table 'otp_verifications' already exists, skipping...\n";
            }
            
            // 10. CONCERN FEEDBACK TABLE
            if (!Schema::hasTable('concern_feedback')) {
                Schema::create('concern_feedback', function ($table) {
                    $table->id();
                    $table->foreignId('concern_id')->constrained()->onDelete('cascade');
                    $table->foreignId('user_id')->constrained()->onDelete('cascade');
                    $table->integer('rating')->unsigned(); // 1-5 stars
                    $table->text('comment')->nullable();
                    $table->json('feedback_data')->nullable();
                    $table->timestamps();
                    
                    $table->index(['concern_id', 'user_id']);
                    $table->index('rating');
                });
                echo "   ✅ Created table: concern_feedback\n";
            } else {
                echo "   ⚠️ Table 'concern_feedback' already exists, skipping...\n";
            }
            
            // 11. ANNOUNCEMENTS TABLE
            if (!Schema::hasTable('announcements')) {
                Schema::create('announcements', function ($table) {
                    $table->id();
                    $table->string('title');
                    $table->text('content')->nullable();
                    $table->enum('type', ['general', 'academic', 'administrative', 'emergency'])->default('general');
                    $table->enum('announcement_type', ['text', 'image'])->default('text');
                    $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
                    $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
                    $table->boolean('is_pinned')->default(false);
                    $table->timestamp('published_at')->nullable();
                    $table->timestamp('expires_at')->nullable();
                    
                    // Image support columns
                    $table->string('image_path')->nullable();
                    $table->string('image_filename')->nullable();
                    $table->string('image_mime_type')->nullable();
                    $table->integer('image_size')->nullable();
                    $table->integer('image_width')->nullable();
                    $table->integer('image_height')->nullable();
                    
                    $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
                    $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
                    $table->json('target_audience')->nullable();
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    
                    $table->index(['status', 'published_at']);
                    $table->index(['type', 'status']);
                    $table->index(['announcement_type', 'status']);
                    $table->index(['is_pinned', 'published_at']);
                    $table->index(['department_id', 'status']);
                });
                echo "   ✅ Created table: announcements\n";
            } else {
                echo "   ⚠️ Table 'announcements' already exists, checking for missing columns...\n";
                
                // Check for missing columns and add them
                $missingColumns = [
                    'announcement_type' => "enum('text', 'image') default 'text'",
                    'image_path' => 'string nullable',
                    'image_filename' => 'string nullable',
                    'image_mime_type' => 'string nullable',
                    'image_size' => 'integer nullable',
                    'image_width' => 'integer nullable',
                    'image_height' => 'integer nullable'
                ];
                
                foreach ($missingColumns as $column => $definition) {
                    if (!Schema::hasColumn('announcements', $column)) {
                        Schema::table('announcements', function ($table) use ($column, $definition) {
                            if (strpos($definition, 'enum') !== false) {
                                $table->enum('announcement_type', ['text', 'image'])->default('text');
                            } elseif (strpos($definition, 'string') !== false) {
                                $table->string($column)->nullable();
                            } elseif (strpos($definition, 'integer') !== false) {
                                $table->integer($column)->nullable();
                            }
                        });
                        echo "   ✅ Added missing column: {$column}\n";
                    }
                }
            }
            
            // 12. ANNOUNCEMENT BOOKMARKS TABLE
            if (!Schema::hasTable('announcement_bookmarks')) {
                Schema::create('announcement_bookmarks', function ($table) {
                    $table->id();
                    $table->foreignId('user_id')->constrained()->onDelete('cascade');
                    $table->foreignId('announcement_id')->constrained()->onDelete('cascade');
                    $table->timestamps();
                    
                    $table->unique(['user_id', 'announcement_id']);
                    $table->index(['user_id', 'created_at']);
                });
                echo "   ✅ Created table: announcement_bookmarks\n";
            } else {
                echo "   ⚠️ Table 'announcement_bookmarks' already exists, skipping...\n";
            }
            
            // 13. EMERGENCY CONTACTS TABLE
            if (!Schema::hasTable('emergency_contacts')) {
                Schema::create('emergency_contacts', function ($table) {
                    $table->id();
                    $table->string('name');
                    $table->string('phone');
                    $table->string('email')->nullable();
                    $table->string('department')->nullable();
                    $table->string('position')->nullable();
                    $table->enum('type', ['medical', 'security', 'administrative', 'technical'])->default('administrative');
                    $table->boolean('is_active')->default(true);
                    $table->integer('priority')->default(1);
                    $table->json('contact_info')->nullable();
                    $table->timestamps();
                    
                    $table->index(['type', 'is_active']);
                    $table->index('priority');
                });
                echo "   ✅ Created table: emergency_contacts\n";
            } else {
                echo "   ⚠️ Table 'emergency_contacts' already exists, skipping...\n";
            }
            
            // 14. EMERGENCY PROTOCOLS TABLE
            if (!Schema::hasTable('emergency_protocols')) {
                Schema::create('emergency_protocols', function ($table) {
                    $table->id();
                    $table->string('title');
                    $table->text('description');
                    $table->enum('type', ['fire', 'earthquake', 'medical', 'security', 'weather', 'other'])->default('other');
                    $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
                    $table->json('steps')->nullable();
                    $table->json('contact_list')->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    
                    $table->index(['type', 'severity']);
                    $table->index('is_active');
                });
                echo "   ✅ Created table: emergency_protocols\n";
            } else {
                echo "   ⚠️ Table 'emergency_protocols' already exists, skipping...\n";
            }
            
            // 15. NOTIFICATIONS TABLE
            if (!Schema::hasTable('notifications')) {
                Schema::create('notifications', function ($table) {
                    $table->id();
                    $table->string('type');
                    $table->string('title');
                    $table->text('message');
                    $table->json('data')->nullable();
                    $table->foreignId('user_id')->constrained()->onDelete('cascade');
                    $table->foreignId('concern_id')->nullable()->constrained()->onDelete('cascade');
                    $table->foreignId('announcement_id')->nullable()->constrained()->onDelete('cascade');
                    $table->timestamp('read_at')->nullable();
                    $table->timestamps();
                    
                    $table->index(['user_id', 'read_at']);
                    $table->index(['type', 'created_at']);
                });
                echo "   ✅ Created table: notifications\n";
            } else {
                echo "   ⚠️ Table 'notifications' already exists, skipping...\n";
            }
            
            // 16. FCM TOKENS TABLE
            if (!Schema::hasTable('fcm_tokens')) {
                Schema::create('fcm_tokens', function ($table) {
                    $table->id();
                    $table->foreignId('user_id')->constrained()->onDelete('cascade');
                    $table->string('token')->unique();
                    $table->string('device_type')->nullable();
                    $table->string('device_id')->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->timestamp('last_used_at')->nullable();
                    $table->timestamps();
                    
                    $table->index(['user_id', 'is_active']);
                    $table->index('token');
                });
                echo "   ✅ Created table: fcm_tokens\n";
            } else {
                echo "   ⚠️ Table 'fcm_tokens' already exists, skipping...\n";
            }
            
            // 17. AUDIT LOGS TABLE
            if (!Schema::hasTable('audit_logs')) {
                Schema::create('audit_logs', function ($table) {
                    $table->id();
                    $table->string('event');
                    $table->string('model_type');
                    $table->unsignedBigInteger('model_id');
                    $table->json('old_values')->nullable();
                    $table->json('new_values')->nullable();
                    $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
                    $table->string('ip_address')->nullable();
                    $table->string('user_agent')->nullable();
                    $table->timestamps();
                    
                    $table->index(['model_type', 'model_id']);
                    $table->index(['user_id', 'created_at']);
                    $table->index('event');
                });
                echo "   ✅ Created table: audit_logs\n";
            } else {
                echo "   ⚠️ Table 'audit_logs' already exists, skipping...\n";
            }
            
            // 18. AI CHAT SESSIONS TABLE
            if (!Schema::hasTable('ai_chat_sessions')) {
                Schema::create('ai_chat_sessions', function ($table) {
                    $table->id();
                    $table->foreignId('user_id')->constrained()->onDelete('cascade');
                    $table->string('session_id')->unique();
                    $table->string('context')->default('general');
                    $table->json('metadata')->nullable();
                    $table->timestamp('last_activity_at');
                    $table->timestamps();
                    
                    $table->index(['user_id', 'last_activity_at']);
                    $table->index('session_id');
                });
                echo "   ✅ Created table: ai_chat_sessions\n";
            } else {
                echo "   ⚠️ Table 'ai_chat_sessions' already exists, skipping...\n";
            }
            
            // 19. AI CHAT MESSAGES TABLE
            if (!Schema::hasTable('ai_chat_messages')) {
                Schema::create('ai_chat_messages', function ($table) {
                    $table->id();
                    $table->foreignId('session_id')->constrained('ai_chat_sessions')->onDelete('cascade');
                    $table->enum('role', ['user', 'assistant', 'system']);
                    $table->text('content');
                    $table->string('service')->default('huggingface');
                    $table->json('metadata')->nullable();
                    $table->decimal('confidence', 3, 2)->nullable();
                    $table->string('intent')->nullable();
                    $table->timestamps();
                    
                    $table->index(['session_id', 'created_at']);
                    $table->index('role');
                    $table->index('service');
                });
                echo "   ✅ Created table: ai_chat_messages\n";
            } else {
                echo "   ⚠️ Table 'ai_chat_messages' already exists, skipping...\n";
            }
            
            // 20. FAQ ITEMS TABLE
            if (!Schema::hasTable('faq_items')) {
                Schema::create('faq_items', function ($table) {
                    $table->id();
                    $table->string('question');
                    $table->text('answer');
                    $table->string('category')->default('general');
                    $table->string('intent')->nullable();
                    $table->decimal('confidence', 3, 2)->default(0.80);
                    $table->boolean('active')->default(true);
                    $table->json('tags')->nullable();
                    $table->string('context')->default('general');
                    $table->integer('priority')->default(1);
                    $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                    $table->timestamps();
                    
                    $table->index(['category', 'active']);
                    $table->index(['intent', 'active']);
                    $table->index(['context', 'active']);
                    $table->index('priority');
                });
                echo "   ✅ Created table: faq_items\n";
            } else {
                echo "   ⚠️ Table 'faq_items' already exists, skipping...\n";
            }
            
            // 21. TRAINING DATA TABLE
            if (!Schema::hasTable('training_data')) {
                Schema::create('training_data', function ($table) {
                    $table->id();
                    $table->string('type');
                    $table->string('question')->nullable();
                    $table->text('answer')->nullable();
                    $table->text('user_message')->nullable();
                    $table->text('assistant_response')->nullable();
                    $table->string('department')->nullable();
                    $table->string('topic')->nullable();
                    $table->text('information')->nullable();
                    $table->string('category')->default('general');
                    $table->string('context')->default('general');
                    $table->json('tags')->nullable();
                    $table->integer('priority')->default(1);
                    $table->boolean('active')->default(true);
                    $table->string('source')->default('manual');
                    $table->string('batch_id')->nullable();
                    $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                    $table->timestamps();
                    
                    $table->index(['type', 'active']);
                    $table->index(['category', 'active']);
                    $table->index(['context', 'active']);
                    $table->index('batch_id');
                    $table->index('source');
                });
                echo "   ✅ Created table: training_data\n";
            } else {
                echo "   ⚠️ Table 'training_data' already exists, skipping...\n";
            }
            
            // 22. TRAINING BATCHES TABLE
            if (!Schema::hasTable('training_batches')) {
                Schema::create('training_batches', function ($table) {
                    $table->id();
                    $table->string('batch_id')->unique();
                    $table->string('filename')->nullable();
                    $table->string('type');
                    $table->integer('total_items');
                    $table->integer('successful_items')->default(0);
                    $table->integer('failed_items')->default(0);
                    $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
                    $table->json('errors')->nullable();
                    $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
                    $table->timestamp('processed_at')->nullable();
                    $table->timestamps();
                    
                    $table->index(['status', 'created_at']);
                    $table->index('batch_id');
                });
                echo "   ✅ Created table: training_batches\n";
            } else {
                echo "   ⚠️ Table 'training_batches' already exists, skipping...\n";
            }
            
            // 23. SYSTEM SETTINGS TABLE
            if (!Schema::hasTable('system_settings')) {
                Schema::create('system_settings', function ($table) {
                    $table->id();
                    $table->string('key')->unique();
                    $table->text('value')->nullable();
                    $table->string('type')->default('string');
                    $table->text('description')->nullable();
                    $table->boolean('is_public')->default(false);
                    $table->timestamps();
                    
                    $table->index('key');
                    $table->index('is_public');
                });
                echo "   ✅ Created table: system_settings\n";
            } else {
                echo "   ⚠️ Table 'system_settings' already exists, skipping...\n";
            }
            
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            
            echo "\n📊 Database Summary...\n";
            echo "====================\n";
            
            $tables = [
                'departments', 'users', 'facilities', 'concerns', 'chat_rooms',
                'concern_messages', 'concern_feedback', 'student_id_counters', 
                'student_id_reservations', 'otp_verifications', 'announcements',
                'announcement_bookmarks', 'emergency_contacts', 'emergency_protocols',
                'notifications', 'fcm_tokens', 'audit_logs', 'ai_chat_sessions',
                'ai_chat_messages', 'faq_items', 'training_data', 'training_batches',
                'system_settings'
            ];
            
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $count = DB::table($table)->count();
                    echo "  ✅ {$table}: {$count} records\n";
                } else {
                    echo "  ❌ {$table}: Table missing\n";
                }
            }
            
            echo "\n🎉 Safe database setup completed successfully!\n";
            echo "All existing data has been preserved.\n";
            
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // This migration is designed to be safe and not drop existing data
        // The down method is intentionally left empty to prevent data loss
        echo "⚠️ This migration is designed to be safe and does not support rollback to prevent data loss.\n";
    }
};