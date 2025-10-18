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
            // Auto-approval fields
            $table->boolean('auto_approved')->default(false)->after('rating');
            
            // Auto-closure fields (closed_at already exists, so only add missing ones)
            $table->string('closed_by')->nullable()->after('closed_at');
            $table->boolean('auto_closed')->default(false)->after('closed_by');
            
            // Indexes for better performance
            $table->index(['auto_approved', 'created_at']);
            $table->index(['escalated_at', 'escalated_by']);
            $table->index(['closed_at', 'auto_closed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropIndex(['auto_approved', 'created_at']);
            $table->dropIndex(['escalated_at', 'escalated_by']);
            $table->dropIndex(['closed_at', 'auto_closed']);
            
            $table->dropColumn([
                'auto_approved',
                'closed_by',
                'auto_closed',
            ]);
        });
    }
};