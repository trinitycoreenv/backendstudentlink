<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if concerns table exists before modifying
        if (Schema::hasTable('concerns')) {
            // Add new columns for approval workflow
            Schema::table('concerns', function (Blueprint $table) {
                // Only add columns if they don't exist
                if (!Schema::hasColumn('concerns', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable();
                }
                if (!Schema::hasColumn('concerns', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable();
                }
                if (!Schema::hasColumn('concerns', 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable();
                }
                if (!Schema::hasColumn('concerns', 'approved_by')) {
                    $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('concerns', 'rejected_by')) {
                    $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null');
                }
            });

            // Update the status enum to include approved and rejected
            // Only if the table exists and has the status column
            if (Schema::hasColumn('concerns', 'status')) {
                DB::statement("ALTER TABLE concerns MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'in_progress', 'resolved', 'closed', 'cancelled') DEFAULT 'pending'");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the new columns
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejection_reason', 'approved_at', 'rejected_at', 'approved_by', 'rejected_by']);
        });

        // Revert the status enum
        DB::statement("ALTER TABLE concerns MODIFY COLUMN status ENUM('pending', 'in_progress', 'resolved', 'closed', 'cancelled') DEFAULT 'pending'");
    }
};
