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
            // Make content column nullable if it exists and is not already nullable
            if (Schema::hasColumn('announcements', 'content')) {
                $table->text('content')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Revert content column to not nullable
            if (Schema::hasColumn('announcements', 'content')) {
                $table->text('content')->nullable(false)->change();
            }
        });
    }
};
