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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained()->onDelete('cascade');
            $table->foreignId('chat_room_id')->constrained()->onDelete('cascade');
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->text('message');
            $table->enum('message_type', ['text', 'image', 'file', 'system', 'status_change', 'resolution_confirmation', 'resolution_dispute', 'chat_closure', 'chat_reopened'])->default('text');
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_typing')->default(false);
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('reactions')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->onDelete('set null');
            $table->timestamps();

            $table->index(['chat_room_id', 'created_at']);
            $table->index(['concern_id', 'created_at']);
            $table->index(['author_id', 'created_at']);
            $table->index('message_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};