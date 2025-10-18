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
            $table->enum('status', ['pending', 'approved', 'in_progress', 'resolved', 'student_confirmed', 'closed', 'cancelled'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concerns', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'in_progress', 'resolved', 'closed', 'cancelled'])->change();
        });
    }
};
