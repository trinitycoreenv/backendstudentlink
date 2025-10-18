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
        // AI Chat Messages table - only create if it doesn't exist
        if (!Schema::hasTable('ai_chat_messages')) {
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
        }

        // Training Batches table for tracking bulk imports - only create if it doesn't exist
        if (!Schema::hasTable('training_batches')) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_batches');
        Schema::dropIfExists('ai_chat_messages');
    }
};
