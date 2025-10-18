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
        Schema::create('cross_department_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained()->onDelete('cascade');
            $table->foreignId('staff_id')->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('original_department_id');
            $table->unsignedBigInteger('assigned_department_id');
            $table->string('assignment_type')->default('cross_department');
            $table->integer('estimated_duration_hours')->default(8);
            $table->string('status')->default('active');
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->foreign('original_department_id')->references('id')->on('departments')->onDelete('cascade');
            $table->foreign('assigned_department_id')->references('id')->on('departments')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cross_department_assignments');
    }
};
