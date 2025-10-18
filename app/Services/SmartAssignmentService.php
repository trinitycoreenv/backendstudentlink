<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SmartAssignmentService
{
    private $workloadThreshold = 5; // Maximum active concerns per staff
    private $responseTimeThreshold = 2; // Hours for response time consideration
    private $skillWeight = 0.3; // Weight for skill matching
    private $workloadWeight = 0.4; // Weight for workload balancing
    private $responseTimeWeight = 0.3; // Weight for response time

    /**
     * Assign a concern to the best available staff member
     */
    public function assignConcern(Concern $concern): ?User
    {
        try {
            Log::info("Starting smart assignment for concern {$concern->id}", [
                'concern_id' => $concern->id,
                'department_id' => $concern->department_id,
                'priority' => $concern->priority,
                'type' => $concern->type
            ]);

            // Get available staff in the concern's department
            $availableStaff = $this->getAvailableStaff($concern->department_id);
            
            if ($availableStaff->isEmpty()) {
                Log::warning("No available staff in department {$concern->department_id}");
                return $this->findCrossDepartmentStaff($concern);
            }

            // Calculate assignment scores for each staff member
            $staffScores = $this->calculateAssignmentScores($concern, $availableStaff);
            
            // Sort by score (highest first)
            $staffScores = $staffScores->sortByDesc('score');
            
            $bestStaff = $staffScores->first();
            
            if ($bestStaff && $bestStaff['score'] > 0.5) { // Minimum score threshold
                Log::info("Assigned concern {$concern->id} to staff {$bestStaff['staff']->id}", [
                    'staff_id' => $bestStaff['staff']->id,
                    'staff_name' => $bestStaff['staff']->name,
                    'score' => $bestStaff['score'],
                    'reasons' => $bestStaff['reasons']
                ]);
                
                return $bestStaff['staff'];
            }

            // If no good match found, try cross-department
            Log::info("No suitable staff found in department, trying cross-department assignment");
            return $this->findCrossDepartmentStaff($concern);

        } catch (\Exception $e) {
            Log::error("Smart assignment failed for concern {$concern->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get available staff in a department
     */
    private function getAvailableStaff(int $departmentId): \Illuminate\Database\Eloquent\Collection
    {
        return User::where('role', 'staff')
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->with(['assignedConcerns' => function($query) {
                $query->whereNull('archived_at')
                      ->whereNotIn('status', ['resolved', 'student_confirmed', 'closed']);
            }])
            ->get();
    }

    /**
     * Calculate assignment scores for staff members
     */
    private function calculateAssignmentScores(Concern $concern, $staff): \Illuminate\Support\Collection
    {
        return $staff->map(function($staffMember) use ($concern) {
            $workloadScore = $this->calculateWorkloadScore($staffMember);
            $skillScore = $this->calculateSkillScore($concern, $staffMember);
            $responseTimeScore = $this->calculateResponseTimeScore($staffMember);
            
            $totalScore = ($workloadScore * $this->workloadWeight) + 
                         ($skillScore * $this->skillWeight) + 
                         ($responseTimeScore * $this->responseTimeWeight);

            $reasons = [];
            if ($workloadScore > 0.7) $reasons[] = 'Low workload';
            if ($skillScore > 0.7) $reasons[] = 'Skill match';
            if ($responseTimeScore > 0.7) $reasons[] = 'Fast response time';

            return [
                'staff' => $staffMember,
                'score' => $totalScore,
                'workload_score' => $workloadScore,
                'skill_score' => $skillScore,
                'response_time_score' => $responseTimeScore,
                'reasons' => $reasons
            ];
        });
    }

    /**
     * Calculate workload score (lower workload = higher score)
     */
    private function calculateWorkloadScore(User $staff): float
    {
        $activeConcerns = $staff->assignedConcerns->count();
        
        if ($activeConcerns == 0) return 1.0; // Perfect score for no workload
        if ($activeConcerns >= $this->workloadThreshold) return 0.0; // No score if overloaded
        
        // Linear decrease from 1.0 to 0.0
        return 1.0 - ($activeConcerns / $this->workloadThreshold);
    }

    /**
     * Calculate skill matching score
     */
    private function calculateSkillScore(Concern $concern, User $staff): float
    {
        // Define skill mappings
        $skillMappings = [
            'academic' => ['academic_advisor', 'registrar', 'faculty'],
            'technical' => ['it_support', 'technical_support', 'system_admin'],
            'administrative' => ['admin_staff', 'secretary', 'coordinator'],
            'health' => ['health_services', 'counselor', 'nurse'],
            'safety' => ['security', 'safety_officer', 'emergency_response'],
        ];

        $concernType = strtolower($concern->type);
        $staffRole = strtolower($staff->role ?? '');
        $staffTitle = strtolower($staff->title ?? '');

        // Check if staff has relevant skills
        if (isset($skillMappings[$concernType])) {
            $relevantSkills = $skillMappings[$concernType];
            
            foreach ($relevantSkills as $skill) {
                if (str_contains($staffTitle, $skill) || str_contains($staffRole, $skill)) {
                    return 1.0; // Perfect match
                }
            }
        }

        // Check for general skills
        $generalSkills = ['support', 'assistant', 'coordinator', 'specialist'];
        foreach ($generalSkills as $skill) {
            if (str_contains($staffTitle, $skill) || str_contains($staffRole, $skill)) {
                return 0.6; // Good match
            }
        }

        return 0.3; // Default score for any staff member
    }

    /**
     * Calculate response time score
     */
    private function calculateResponseTimeScore(User $staff): float
    {
        // Get average response time for this staff member
        $avgResponseTime = $this->getAverageResponseTime($staff);
        
        if ($avgResponseTime === null) return 0.8; // Default for new staff
        
        // Convert to hours
        $avgResponseHours = $avgResponseTime / 3600;
        
        if ($avgResponseHours <= 1) return 1.0; // Excellent
        if ($avgResponseHours <= 2) return 0.8; // Good
        if ($avgResponseHours <= 4) return 0.6; // Average
        if ($avgResponseHours <= 8) return 0.4; // Below average
        return 0.2; // Poor
    }

    /**
     * Get average response time for a staff member
     */
    private function getAverageResponseTime(User $staff): ?float
    {
        $concerns = $staff->assignedConcerns()
            ->whereNotNull('resolved_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        if ($concerns->isEmpty()) return null;

        $totalResponseTime = 0;
        $count = 0;

        foreach ($concerns as $concern) {
            if ($concern->resolved_at) {
                $responseTime = $concern->resolved_at->diffInSeconds($concern->created_at);
                $totalResponseTime += $responseTime;
                $count++;
            }
        }

        return $count > 0 ? $totalResponseTime / $count : null;
    }

    /**
     * Find staff from other departments if current department is overloaded
     */
    private function findCrossDepartmentStaff(Concern $concern): ?User
    {
        Log::info("Searching for cross-department staff for concern {$concern->id}");

        // Get all available staff across departments
        $allStaff = User::where('role', 'staff')
            ->where('is_active', true)
            ->where('can_handle_cross_department', true) // Only staff who can handle cross-department
            ->with(['assignedConcerns' => function($query) {
                $query->whereNull('archived_at')
                      ->whereNotIn('status', ['resolved', 'student_confirmed', 'closed']);
            }])
            ->get();

        if ($allStaff->isEmpty()) {
            Log::warning("No cross-department staff available");
            return null;
        }

        // Calculate scores for cross-department staff
        $staffScores = $this->calculateAssignmentScores($concern, $allStaff);
        $staffScores = $staffScores->sortByDesc('score');
        
        $bestStaff = $staffScores->first();
        
        if ($bestStaff && $bestStaff['score'] > 0.4) { // Lower threshold for cross-department
            Log::info("Assigned concern {$concern->id} to cross-department staff {$bestStaff['staff']->id}", [
                'staff_id' => $bestStaff['staff']->id,
                'staff_name' => $bestStaff['staff']->name,
                'department_id' => $bestStaff['staff']->department_id,
                'score' => $bestStaff['score']
            ]);
            
            // Create cross-department assignment record
            $this->createCrossDepartmentAssignment($concern, $bestStaff['staff']);
            
            return $bestStaff['staff'];
        }

        Log::warning("No suitable cross-department staff found for concern {$concern->id}");
        return null;
    }

    /**
     * Create cross-department assignment record
     */
    private function createCrossDepartmentAssignment(Concern $concern, User $staff): void
    {
        try {
            DB::table('cross_department_assignments')->insert([
                'concern_id' => $concern->id,
                'staff_id' => $staff->id,
                'original_department_id' => $concern->department_id,
                'assigned_department_id' => $staff->department_id,
                'assignment_type' => 'cross_department',
                'estimated_duration_hours' => $this->estimateResolutionTime($concern),
                'status' => 'active',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create cross-department assignment: " . $e->getMessage());
        }
    }

    /**
     * Estimate resolution time based on concern priority and type
     */
    private function estimateResolutionTime(Concern $concern): int
    {
        $baseTime = [
            'urgent' => 2,
            'high' => 4,
            'medium' => 8,
            'low' => 24,
        ];

        $typeMultiplier = [
            'academic' => 1.0,
            'technical' => 1.5,
            'administrative' => 0.8,
            'health' => 0.5,
            'safety' => 0.3,
        ];

        $base = $baseTime[$concern->priority] ?? 8;
        $multiplier = $typeMultiplier[$concern->type] ?? 1.0;

        return (int) ($base * $multiplier);
    }

    /**
     * Get assignment statistics
     */
    public function getAssignmentStats(): array
    {
        $totalAssignments = Concern::whereNotNull('assigned_to')->count();
        $crossDepartmentAssignments = DB::table('cross_department_assignments')->count();
        $avgResponseTime = $this->getSystemAverageResponseTime();

        return [
            'total_assignments' => $totalAssignments,
            'cross_department_assignments' => $crossDepartmentAssignments,
            'cross_department_percentage' => $totalAssignments > 0 ? 
                ($crossDepartmentAssignments / $totalAssignments) * 100 : 0,
            'average_response_time_hours' => $avgResponseTime,
            'workload_threshold' => $this->workloadThreshold,
        ];
    }

    /**
     * Get system-wide average response time
     */
    private function getSystemAverageResponseTime(): float
    {
        $concerns = Concern::whereNotNull('resolved_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        if ($concerns->isEmpty()) return 0;

        $totalResponseTime = 0;
        foreach ($concerns as $concern) {
            $responseTime = $concern->resolved_at->diffInHours($concern->created_at);
            $totalResponseTime += $responseTime;
        }

        return $totalResponseTime / $concerns->count();
    }
}