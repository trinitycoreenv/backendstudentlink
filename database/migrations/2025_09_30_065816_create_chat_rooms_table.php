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
        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained()->onDelete('cascade');
            $table->string('room_name');
            $table->enum('status', ['active', 'closed', 'archived'])->default('active');
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('last_message_id')->nullable()->constrained('chat_messages')->onDelete('set null');
            $table->json('participants')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['concern_id', 'status']);
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_rooms');
    }
};