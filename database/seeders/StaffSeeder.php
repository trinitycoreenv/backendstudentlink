<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all departments
        $departments = Department::all();
        
        if ($departments->isEmpty()) {
            $this->command->error('No departments found. Please run DepartmentSeeder first.');
            return;
        }

        $this->command->info('Creating 10 staff members for each department...');

        foreach ($departments as $department) {
            $this->command->info("Creating staff for: {$department->name}");
            
            // Create 10 staff members for this department
            for ($i = 1; $i <= 10; $i++) {
                $staffNumber = str_pad($i, 2, '0', STR_PAD_LEFT);
                $departmentCode = strtoupper(substr($department->name, 0, 3));
                
                User::create([
                    'name' => "Staff Member {$staffNumber} - {$department->name}",
                    'email' => "staff{$staffNumber}@{$department->code}.bcp.edu.ph",
                    'password' => Hash::make('password123'), // Same password for all
                    'role' => 'staff',
                    'department_id' => $department->id,
                    'employee_id' => "{$departmentCode}-{$staffNumber}",
                    'phone' => $this->generatePhoneNumber(),
                    'is_active' => true,
                    'preferences' => [
                        'theme' => 'light',
                        'language' => 'en',
                        'notifications' => [
                            'email' => true,
                            'push' => true,
                            'sms' => false
                        ]
                    ]
                ]);
            }
        }

        $totalStaff = $departments->count() * 10;
        $this->command->info("✅ Created {$totalStaff} staff members across {$departments->count()} departments!");
        $this->command->info("📧 All staff emails follow pattern: staff01@[dept-code].bcp.edu.ph");
        $this->command->info("🔑 All staff passwords: password123");
        $this->command->info("📱 Employee IDs follow pattern: [DEPT]-01, [DEPT]-02, etc.");
    }

    /**
     * Generate a realistic phone number
     */
    private function generatePhoneNumber(): string
    {
        $prefixes = ['0917', '0918', '0919', '0920', '0921', '0922', '0923', '0924', '0925', '0926', '0927', '0928', '0929', '0930'];
        $prefix = $prefixes[array_rand($prefixes)];
        $number = mt_rand(1000000, 9999999);
        return $prefix . $number;
    }
}
