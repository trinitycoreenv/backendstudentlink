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
            // Make text-based fields nullable for image-only announcements
            $table->string('title')->nullable()->change();
            $table->string('type')->nullable()->change();
            $table->string('priority')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Revert to required fields
            $table->string('title')->nullable(false)->change();
            $table->string('type')->nullable(false)->change();
            $table->string('priority')->nullable(false)->change();
        });
    }
};