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
        Schema::table('training_data', function (Blueprint $table) {
            // Update column lengths to handle longer text
            $table->text('user_message')->nullable()->change();
            $table->text('assistant_response')->nullable()->change();
            $table->text('information')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('training_data', function (Blueprint $table) {
            // Revert to shorter lengths
            $table->string('user_message')->nullable()->change();
            $table->string('assistant_response')->nullable()->change();
            $table->string('information')->nullable()->change();
        });
    }
};
