<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // FAQ Items table
        Schema::create('faq_items', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->string('category')->default('general');
            $table->string('intent')->nullable();
            $table->decimal('confidence', 3, 2)->default(0.80);
            $table->boolean('active')->default(true);
            $table->json('tags')->nullable();
            $table->string('context')->default('general');
            $table->integer('priority')->default(1); // 1=low, 2=medium, 3=high
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['category', 'active']);
            $table->index(['intent', 'active']);
            $table->index(['context', 'active']);
            $table->index('priority');
        });

        // Training Data table for bulk imports
        Schema::create('training_data', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // faq, conversation, department_info, custom
            $table->string('question')->nullable();
            $table->text('answer')->nullable();
            $table->string('user_message')->nullable(); // for conversation type
            $table->string('assistant_response')->nullable(); // for conversation type
            $table->string('department')->nullable(); // for department_info type
            $table->string('topic')->nullable(); // for department_info type
            $table->string('information')->nullable(); // for department_info type
            $table->string('category')->default('general');
            $table->string('context')->default('general');
            $table->json('tags')->nullable();
            $table->integer('priority')->default(1);
            $table->boolean('active')->default(true);
            $table->string('source')->default('manual'); // manual, bulk_import, api
            $table->string('batch_id')->nullable(); // for tracking bulk imports
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['type', 'active']);
            $table->index(['category', 'active']);
            $table->index(['context', 'active']);
            $table->index('batch_id');
            $table->index('source');
        });

        // AI Chat Sessions table (enhanced)
        Schema::create('ai_chat_sessions', function (Blueprint $table) {
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

        // AI Chat Messages table
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_chat_sessions')->onDelete('cascade');
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');
            $table->string('service')->default('huggingface'); // huggingface, dialogflow, fallback
            $table->json('metadata')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('intent')->nullable();
            $table->timestamps();
            
            $table->index(['session_id', 'created_at']);
            $table->index('role');
            $table->index('service');
        });

        // Training Batches table for tracking bulk imports
        Schema::create('training_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id')->unique();
            $table->string('filename')->nullable();
            $table->string('type'); // faq, conversation, mixed
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_batches');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_sessions');
        Schema::dropIfExists('training_data');
        Schema::dropIfExists('faq_items');
    }
};
