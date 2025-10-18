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
            // Analytics fields
            $table->integer('download_count')->default(0)->after('view_count');
            $table->integer('share_count')->default(0)->after('download_count');
            
            // Scheduling fields
            $table->timestamp('scheduled_at')->nullable()->after('expires_at');
            $table->timestamp('auto_expire_at')->nullable()->after('scheduled_at');
            
            // Moderation fields
            $table->enum('moderation_status', ['pending', 'approved', 'rejected'])->default('approved')->after('status');
            $table->text('moderation_notes')->nullable()->after('moderation_status');
            $table->unsignedBigInteger('moderated_by')->nullable()->after('moderation_notes');
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
            
            // Image optimization fields
            $table->string('image_thumbnail_path')->nullable()->after('image_height');
            $table->string('image_compressed_path')->nullable()->after('image_thumbnail_path');
            $table->json('image_metadata')->nullable()->after('image_compressed_path');
            
            // CDN and storage fields
            $table->string('cdn_url')->nullable()->after('image_metadata');
            $table->string('storage_provider')->default('local')->after('cdn_url');
            
            // Foreign key for moderator
            $table->foreign('moderated_by')->references('id')->on('users')->onDelete('set null');
        });

        // Create announcement analytics table
        Schema::create('announcement_analytics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action'); // view, download, share, bookmark
            $table->string('device_type')->nullable(); // mobile, web, tablet
            $table->string('user_agent')->nullable();
            $table->string('ip_address')->nullable();
            $table->json('metadata')->nullable(); // Additional analytics data
            $table->timestamp('created_at');
            
            $table->foreign('announcement_id')->references('id')->on('announcements')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            
            $table->index(['announcement_id', 'action']);
            $table->index(['user_id', 'action']);
            $table->index('created_at');
        });

        // Create announcement schedules table for bulk operations
        Schema::create('announcement_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('name'); // Schedule name
            $table->json('announcements'); // Array of announcement data
            $table->timestamp('scheduled_at');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('results')->nullable(); // Results of bulk operations
            $table->timestamps();
            
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['scheduled_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement_schedules');
        Schema::dropIfExists('announcement_analytics');
        
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign(['moderated_by']);
            $table->dropColumn([
                'download_count',
                'share_count',
                'scheduled_at',
                'auto_expire_at',
                'moderation_status',
                'moderation_notes',
                'moderated_by',
                'moderated_at',
                'image_thumbnail_path',
                'image_compressed_path',
                'image_metadata',
                'cdn_url',
                'storage_provider'
            ]);
        });
    }
};
