<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Department;

class CreateSanitaryDepartment extends Command
{
    protected $signature = 'department:create-sanitary';
    protected $description = 'Create Sanitary Department for testing';

    public function handle()
    {
        try {
            // Check if department already exists
            $existing = Department::where('code', 'SANITARY')->first();
            if ($existing) {
                $this->info('Sanitary Department already exists with ID: ' . $existing->id);
                return;
            }

            // Create Sanitary Department
            $department = Department::create([
                'name' => 'Sanitary Department',
                'code' => 'SANITARY',
                'description' => 'Department responsible for maintaining cleanliness and sanitation across the campus',
                'type' => 'administrative',
                'is_active' => true,
                'contact_info' => [
                    'phone' => '+63-2-8123-4567',
                    'email' => 'sanitary@bestlink.edu.ph',
                    'location' => 'Ground Floor, Main Building',
                    'office_hours' => 'Mon-Fri 8AM-5PM'
                ]
            ]);

            $this->info('✅ Sanitary Department created successfully!');
            $this->info("ID: {$department->id}");
            $this->info("Name: {$department->name}");
            $this->info("Code: {$department->code}");
            $this->info("Type: {$department->type}");
            $this->info("Active: " . ($department->is_active ? 'Yes' : 'No'));
            $this->info("Contact Info: " . json_encode($department->contact_info));

        } catch (\Exception $e) {
            $this->error('❌ Error creating department: ' . $e->getMessage());
        }
    }
}


