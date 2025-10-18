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
            $table->foreignId('staff_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('requesting_department_id')->constrained('departments')->onDelete('cascade');
            $table->foreignId('concern_id')->constrained('concerns')->onDelete('cascade');
            $table->string('assignment_type')->default('cross_department'); // cross_department, emergency, specialized
            $table->integer('estimated_duration_hours')->default(8);
            $table->integer('actual_duration_hours')->nullable();
            $table->enum('status', ['active', 'completed', 'expired', 'cancelled'])->default('active');
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->onDelete('cascade');
            $table->text('completion_notes')->nullable();
            $table->timestamps();
            
            $table->index(['staff_id', 'status'], 'cd_assignments_staff_status_idx');
            $table->index(['requesting_department_id', 'status'], 'cd_assignments_dept_status_idx');
            $table->index(['concern_id'], 'cd_assignments_concern_idx');
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
