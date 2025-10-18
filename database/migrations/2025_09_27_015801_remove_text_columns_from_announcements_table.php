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
            // Remove text-based columns that are no longer needed
            $table->dropColumn([
                'content',
                'excerpt', 
                'featured_image',
                'announcement_type' // Since all announcements are now image-only
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Add back the columns if we need to rollback
            $table->text('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('featured_image')->nullable();
            $table->enum('announcement_type', ['text', 'image'])->default('image');
        });
    }
};