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
        Schema::table('announcements', function (Blueprint $table) {
            // Remove text-based columns that are no longer needed, only if they exist
            if (Schema::hasColumn('announcements', 'content')) {
                $table->dropColumn('content');
            }
            if (Schema::hasColumn('announcements', 'excerpt')) {
                $table->dropColumn('excerpt');
            }
            if (Schema::hasColumn('announcements', 'featured_image')) {
                $table->dropColumn('featured_image');
            }
            if (Schema::hasColumn('announcements', 'announcement_type')) {
                $table->dropColumn('announcement_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Add back the columns if we need to rollback
            if (!Schema::hasColumn('announcements', 'content')) {
                $table->text('content')->nullable();
            }
            if (!Schema::hasColumn('announcements', 'excerpt')) {
                $table->text('excerpt')->nullable();
            }
            if (!Schema::hasColumn('announcements', 'featured_image')) {
                $table->string('featured_image')->nullable();
            }
            if (!Schema::hasColumn('announcements', 'announcement_type')) {
                $table->enum('announcement_type', ['text', 'image'])->default('image');
            }
        });
    }
};