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
        Schema::table('concerns', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalation_level')->nullable(); // staff, department_head, admin
            $table->text('escalation_reason')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('last_reminder_sent')->nullable();
            $table->json('ai_classification')->nullable();
            
            $table->index(['escalated_at']);
            $table->index(['escalation_level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropIndex(['escalated_at']);
            $table->dropIndex(['escalation_level']);
            $table->dropColumn([
                'escalated_at',
                'escalation_level',
                'escalation_reason',
                'escalated_by',
                'last_reminder_sent',
                'ai_classification'
            ]);
        });
    }
};
