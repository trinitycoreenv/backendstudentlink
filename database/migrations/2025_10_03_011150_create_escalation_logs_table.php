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
        Schema::create('escalation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained()->onDelete('cascade');
            $table->string('escalation_type')->default('automated'); // automated, manual
            $table->text('escalation_reason');
            $table->string('escalated_by'); // user_id or 'system'
            $table->timestamp('escalated_at');
            $table->unsignedBigInteger('previous_assignee')->nullable();
            $table->unsignedBigInteger('new_assignee')->nullable();
            $table->timestamps();
            
            $table->foreign('previous_assignee')->references('id')->on('users')->onDelete('set null');
            $table->foreign('new_assignee')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escalation_logs');
    }
};
