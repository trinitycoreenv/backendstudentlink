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
            // Add new fields for the revamped announcement system
            if (!Schema::hasColumn('announcements', 'category')) {
                $table->string('category')->default('General')->after('internal_title');
            }
            if (!Schema::hasColumn('announcements', 'title')) {
                $table->string('title')->nullable()->after('category');
            }
            if (!Schema::hasColumn('announcements', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (!Schema::hasColumn('announcements', 'action_button_text')) {
                $table->string('action_button_text')->nullable()->after('description');
            }
            if (!Schema::hasColumn('announcements', 'action_button_url')) {
                $table->string('action_button_url')->nullable()->after('action_button_text');
            }
            if (!Schema::hasColumn('announcements', 'announcement_timestamp')) {
                $table->timestamp('announcement_timestamp')->nullable()->after('action_button_url');
            }
        });
        
        // Add indexes if they don't exist
        if (!Schema::hasIndex('announcements', 'announcements_category_index')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->index('category');
            });
        }
        if (!Schema::hasIndex('announcements', 'announcements_announcement_timestamp_index')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->index('announcement_timestamp');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropIndex(['announcement_timestamp']);
            $table->dropColumn([
                'category',
                'title', 
                'description',
                'action_button_text',
                'action_button_url',
                'announcement_timestamp'
            ]);
        });
    }
};
