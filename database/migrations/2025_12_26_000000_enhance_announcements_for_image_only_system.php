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
        // Guarded additions to announcements to avoid fragile 'after' references
        Schema::table('announcements', function (Blueprint $table) {
            // Analytics fields
            if (!Schema::hasColumn('announcements', 'download_count')) {
                $col = $table->integer('download_count')->default(0);
                if (Schema::hasColumn('announcements', 'view_count')) {
                    $col->after('view_count');
                }
            }
            if (!Schema::hasColumn('announcements', 'share_count')) {
                $col = $table->integer('share_count')->default(0);
                if (Schema::hasColumn('announcements', 'download_count')) {
                    $col->after('download_count');
                }
            }
            
            // Scheduling fields
            if (!Schema::hasColumn('announcements', 'scheduled_at')) {
                $col = $table->timestamp('scheduled_at')->nullable();
                if (Schema::hasColumn('announcements', 'expires_at')) {
                    $col->after('expires_at');
                }
            }
            if (!Schema::hasColumn('announcements', 'auto_expire_at')) {
                $col = $table->timestamp('auto_expire_at')->nullable();
                if (Schema::hasColumn('announcements', 'scheduled_at')) {
                    $col->after('scheduled_at');
                }
            }
            
            // Moderation fields
            if (!Schema::hasColumn('announcements', 'moderation_status')) {
                $col = $table->enum('moderation_status', ['pending', 'approved', 'rejected'])->default('approved');
                if (Schema::hasColumn('announcements', 'status')) {
                    $col->after('status');
                }
            }
            if (!Schema::hasColumn('announcements', 'moderation_notes')) {
                $col = $table->text('moderation_notes')->nullable();
                if (Schema::hasColumn('announcements', 'moderation_status')) {
                    $col->after('moderation_status');
                }
            }
            if (!Schema::hasColumn('announcements', 'moderated_by')) {
                $col = $table->unsignedBigInteger('moderated_by')->nullable();
                if (Schema::hasColumn('announcements', 'moderation_notes')) {
                    $col->after('moderation_notes');
                }
            }
            if (!Schema::hasColumn('announcements', 'moderated_at')) {
                $col = $table->timestamp('moderated_at')->nullable();
                if (Schema::hasColumn('announcements', 'moderated_by')) {
                    $col->after('moderated_by');
                }
            }
            
            // Image optimization fields
            if (!Schema::hasColumn('announcements', 'image_thumbnail_path')) {
                $col = $table->string('image_thumbnail_path')->nullable();
                if (Schema::hasColumn('announcements', 'image_height')) {
                    $col->after('image_height');
                }
            }
            if (!Schema::hasColumn('announcements', 'image_compressed_path')) {
                $col = $table->string('image_compressed_path')->nullable();
                if (Schema::hasColumn('announcements', 'image_thumbnail_path')) {
                    $col->after('image_thumbnail_path');
                }
            }
            if (!Schema::hasColumn('announcements', 'image_metadata')) {
                $col = $table->json('image_metadata')->nullable();
                if (Schema::hasColumn('announcements', 'image_compressed_path')) {
                    $col->after('image_compressed_path');
                }
            }
            
            // CDN and storage fields
            if (!Schema::hasColumn('announcements', 'cdn_url')) {
                $col = $table->string('cdn_url')->nullable();
                if (Schema::hasColumn('announcements', 'image_metadata')) {
                    $col->after('image_metadata');
                }
            }
            if (!Schema::hasColumn('announcements', 'storage_provider')) {
                $col = $table->string('storage_provider')->default('local');
                if (Schema::hasColumn('announcements', 'cdn_url')) {
                    $col->after('cdn_url');
                }
            }
            
            // Foreign key for moderator (guarded)
            try {
                if (Schema::hasColumn('announcements', 'moderated_by')) {
                    $table->foreign('moderated_by')->references('id')->on('users')->onDelete('set null');
                }
            } catch (\Throwable $e) {
                // If the FK already exists or the engine rejects it, ignore
            }
        });

        // Create announcement analytics table
        if (!Schema::hasTable('announcement_analytics')) {
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
        }

        // Create announcement schedules table for bulk operations
        if (!Schema::hasTable('announcement_schedules')) {
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
