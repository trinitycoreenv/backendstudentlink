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
            // Only add fields that don't already exist
            $table->timestamp('overdue_at')->nullable()->after('escalation_reason');
            $table->text('overdue_reason')->nullable()->after('overdue_at');
            $table->timestamp('reassigned_at')->nullable()->after('overdue_reason');
            $table->string('reassigned_by')->nullable()->after('reassigned_at');
            $table->text('reassignment_reason')->nullable()->after('reassigned_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropColumn([
                'overdue_at',
                'overdue_reason',
                'reassigned_at',
                'reassigned_by',
                'reassignment_reason'
            ]);
        });
    }
};
