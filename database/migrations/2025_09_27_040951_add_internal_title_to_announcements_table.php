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
            // Add internal_title safely, without assuming author_id exists
            if (!Schema::hasColumn('announcements', 'internal_title')) {
                if (Schema::hasColumn('announcements', 'author_id')) {
                    $table->string('internal_title')->nullable()->after('author_id');
                } else {
                    $table->string('internal_title')->nullable();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (Schema::hasColumn('announcements', 'internal_title')) {
                $table->dropColumn('internal_title');
            }
        });
    }
};
