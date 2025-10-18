<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Department;
use App\Models\Concern;
use App\Models\CrossDepartmentAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class StaffCentricSystemTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $admin;
    protected $departmentHead;
    protected $staff;
    protected $student;
    protected $department;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test department
        $this->department = Department::factory()->create([
            'name' => 'BSIT',
            'code' => 'BSIT'
        ]);

        // Create test users
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@test.com'
        ]);

        $this->departmentHead = User::factory()->create([
            'role' => 'department_head',
            'department_id' => $this->department->id,
            'email' => 'depthead@test.com'
        ]);

        $this->staff = User::factory()->create([
            'role' => 'staff',
            'department_id' => $this->department->id,
            'employee_id' => 'BSIT-001',
            'email' => 'staff@test.com'
        ]);

        $this->student = User::factory()->create([
            'role' => 'student',
            'email' => 'student@test.com'
        ]);
    }

    /** @test */
    public function test_staff_can_view_their_dashboard()
    {
        $response = $this->actingAs($this->staff)
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'role' => 'staff',
                    'employee_id' => 'BSIT-001'
                ]
            ]);
    }

    /** @test */
    public function test_staff_can_view_their_assigned_concerns()
    {
        // Create a concern assigned to staff
        $concern = Concern::factory()->create([
            'student_id' => $this->student->id,
            'department_id' => $this->department->id,
            'assigned_to' => $this->staff->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/staff/my-concerns');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ])
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function test_staff_can_update_concern_status()
    {
        $concern = Concern::factory()->create([
            'student_id' => $this->student->id,
            'department_id' => $this->department->id,
            'assigned_to' => $this->staff->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->staff)
            ->patchJson("/api/staff/concerns/{$concern->id}/status", [
                'status' => 'in_progress'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'in_progress'
                ]
            ]);

        $this->assertDatabaseHas('concerns', [
            'id' => $concern->id,
            'status' => 'in_progress'
        ]);
    }

    /** @test */
    public function test_ai_classification_works()
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/ai/classify-concern', [
                'subject' => 'Urgent: Cannot login to student portal',
                'description' => 'I am unable to access my student portal and need immediate help with my grades',
                'type' => 'technical'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'priority' => 'urgent',
                    'category' => 'technical'
                ]
            ]);
    }

    /** @test */
    public function test_smart_assignment_suggests_best_assignee()
    {
        $concern = Concern::factory()->create([
            'student_id' => $this->student->id,
            'department_id' => $this->department->id,
            'subject' => 'Technical issue with login',
            'description' => 'Cannot access student portal',
            'type' => 'technical',
            'priority' => 'high'
        ]);

        $response = $this->actingAs($this->departmentHead)
            ->getJson('/api/smart-assignment/suggest-assignee', [
                'concern_id' => $concern->id
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'suggested_assignee' => [
                        'id' => $this->staff->id,
                        'name' => $this->staff->name
                    ]
                ]
            ]);
    }

    /** @test */
    public function test_cross_department_staff_sharing()
    {
        // Create another department and staff
        $otherDepartment = Department::factory()->create([
            'name' => 'BSBA',
            'code' => 'BSBA'
        ]);

        $otherStaff = User::factory()->create([
            'role' => 'staff',
            'department_id' => $otherDepartment->id,
            'employee_id' => 'BSBA-001',
            'email' => 'otherstaff@test.com'
        ]);

        $concern = Concern::factory()->create([
            'student_id' => $this->student->id,
            'department_id' => $this->department->id,
            'priority' => 'urgent'
        ]);

        // Request cross-department staff
        $response = $this->actingAs($this->departmentHead)
            ->getJson('/api/staff/cross-department/available', [
                'requesting_department_id' => $this->department->id,
                'max_workload' => 10
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ])
            ->assertJsonCount(1, 'data'); // Should find the other staff

        // Assign cross-department staff
        $response = $this->actingAs($this->departmentHead)
            ->postJson('/api/staff/cross-department/assign', [
                'staff_id' => $otherStaff->id,
                'requesting_department_id' => $this->department->id,
                'concern_id' => $concern->id,
                'assignment_type' => 'cross_department',
                'estimated_duration_hours' => 8
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $this->assertDatabaseHas('cross_department_assignments', [
            'staff_id' => $otherStaff->id,
            'requesting_department_id' => $this->department->id,
            'concern_id' => $concern->id
        ]);
    }

    /** @test */
    public function test_escalation_system_works()
    {
        // Create an overdue concern
        $concern = Concern::factory()->create([
            'student_id' => $this->student->id,
            'department_id' => $this->department->id,
            'assigned_to' => $this->staff->id,
            'status' => 'pending',
            'priority' => 'urgent',
            'created_at' => now()->subHours(25), // 25 hours ago
            'assigned_at' => now()->subHours(24) // 24 hours ago
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/escalation/check');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        // Check if concern was escalated
        $concern->refresh();
        $this->assertNotNull($concern->escalated_at);
        $this->assertNotNull($concern->escalation_level);
    }

    /** @test */
    public function test_performance_analytics_work()
    {
        // Create some test data
        Concern::factory()->count(5)->create([
            'department_id' => $this->department->id,
            'assigned_to' => $this->staff->id,
            'status' => 'resolved'
        ]);

        Concern::factory()->count(2)->create([
            'department_id' => $this->department->id,
            'assigned_to' => $this->staff->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->departmentHead)
            ->getJson('/api/analytics/performance', [
                'department_id' => $this->department->id,
                'date_range' => '30'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'overview' => [
                        'total_concerns' => 7,
                        'resolved_concerns' => 5,
                        'pending_concerns' => 2
                    ]
                ]
            ]);
    }

    /** @test */
    public function test_workload_balancing()
    {
        // Create multiple staff members
        $staff2 = User::factory()->create([
            'role' => 'staff',
            'department_id' => $this->department->id,
            'employee_id' => 'BSIT-002',
            'email' => 'staff2@test.com'
        ]);

        // Assign many concerns to one staff
        Concern::factory()->count(15)->create([
            'department_id' => $this->department->id,
            'assigned_to' => $this->staff->id,
            'status' => 'pending'
        ]);

        // Get rebalancing suggestions
        $response = $this->actingAs($this->departmentHead)
            ->postJson('/api/smart-assignment/rebalance', [
                'department_id' => $this->department->id
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['count']); // Should have rebalancing suggestions
    }

    /** @test */
    public function test_end_to_end_concern_flow()
    {
        // 1. Student creates concern
        $concernData = [
            'subject' => 'Urgent: Cannot access grades',
            'description' => 'I need to check my grades but cannot login to the portal',
            'type' => 'technical',
            'priority' => 'urgent',
            'department_id' => $this->department->id
        ];

        $response = $this->actingAs($this->student)
            ->postJson('/api/concerns', $concernData);

        $response->assertStatus(201);
        $concernId = $response->json('data.id');

        // 2. AI classifies the concern
        $response = $this->actingAs($this->admin)
            ->postJson("/api/concerns/{$concernId}/ai-classify");

        $response->assertStatus(200);

        // 3. Staff gets assigned and updates status
        $concern = Concern::find($concernId);
        $concern->update(['assigned_to' => $this->staff->id]);

        $response = $this->actingAs($this->staff)
            ->patchJson("/api/staff/concerns/{$concernId}/status", [
                'status' => 'in_progress'
            ]);

        $response->assertStatus(200);

        // 4. Staff resolves the concern
        $response = $this->actingAs($this->staff)
            ->patchJson("/api/staff/concerns/{$concernId}/status", [
                'status' => 'resolved',
                'resolution_notes' => 'Issue resolved by resetting password'
            ]);

        $response->assertStatus(200);

        // 5. Check analytics reflect the resolution
        $response = $this->actingAs($this->departmentHead)
            ->getJson('/api/analytics/performance', [
                'department_id' => $this->department->id
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'overview' => [
                        'total_concerns' => 1,
                        'resolved_concerns' => 1,
                        'resolution_rate' => 100
                    ]
                ]
            ]);
    }

    /** @test */
    public function test_n8n_webhook_endpoints_exist()
    {
        // Test that the webhook endpoints are accessible (they should return 405 for GET)
        $response = $this->getJson('/api/concern-created');
        $this->assertEquals(405, $response->status()); // Method not allowed

        $response = $this->getJson('/api/cross-department-request');
        $this->assertEquals(405, $response->status()); // Method not allowed
    }
}
