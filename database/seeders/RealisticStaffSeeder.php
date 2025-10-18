<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\Hash;

class RealisticStaffSeeder extends Seeder
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

        $this->command->info('Creating 10 realistic staff members for each department...');

        $firstNames = [
            'Maria', 'Jose', 'Ana', 'Juan', 'Carmen', 'Pedro', 'Rosa', 'Antonio', 'Isabel', 'Carlos',
            'Elena', 'Miguel', 'Teresa', 'Francisco', 'Lourdes', 'Ricardo', 'Patricia', 'Fernando', 'Sofia', 'Roberto',
            'Elena', 'Manuel', 'Carmen', 'Jose', 'Ana', 'Carlos', 'Maria', 'Antonio', 'Isabel', 'Pedro',
            'Rosa', 'Miguel', 'Teresa', 'Francisco', 'Lourdes', 'Ricardo', 'Patricia', 'Fernando', 'Sofia', 'Roberto',
            'Elena', 'Manuel', 'Carmen', 'Jose', 'Ana', 'Carlos', 'Maria', 'Antonio', 'Isabel', 'Pedro'
        ];

        $lastNames = [
            'Santos', 'Cruz', 'Reyes', 'Garcia', 'Rodriguez', 'Lopez', 'Martinez', 'Gonzalez', 'Perez', 'Sanchez',
            'Ramirez', 'Torres', 'Flores', 'Rivera', 'Gomez', 'Diaz', 'Cruz', 'Morales', 'Ramos', 'Jimenez',
            'Herrera', 'Moreno', 'Munoz', 'Alvarez', 'Romero', 'Alonso', 'Gutierrez', 'Navarro', 'Dominguez', 'Vazquez',
            'Ramos', 'Gil', 'Serrano', 'Blanco', 'Molina', 'Suarez', 'Delgado', 'Castro', 'Ortiz', 'Rubio',
            'Marin', 'Sanz', 'Iglesias', 'Medina', 'Cortes', 'Castillo', 'Garrido', 'Leal', 'Peña', 'Vega'
        ];

        $staffCount = 0;
        $usedEmails = [];
        $usedPhones = [];
        $usedEmployeeIds = [];

        foreach ($departments as $department) {
            $this->command->info("Creating staff for: {$department->name}");
            
            // Create 10 staff members for this department
            for ($i = 1; $i <= 10; $i++) {
                $staffNumber = str_pad($i, 2, '0', STR_PAD_LEFT);
                
                // Generate realistic name
                $firstName = $firstNames[array_rand($firstNames)];
                $lastName = $lastNames[array_rand($lastNames)];
                $fullName = "{$firstName} {$lastName}";
                
                // Generate unique email
                $emailPrefix = strtolower($firstName . '.' . $lastName . $staffNumber);
                $email = "{$emailPrefix}@{$department->code}.bcp.edu.ph";
                
                // Ensure email is unique
                $emailCounter = 1;
                while (in_array($email, $usedEmails)) {
                    $email = strtolower($firstName . '.' . $lastName . $staffNumber . $emailCounter) . "@{$department->code}.bcp.edu.ph";
                    $emailCounter++;
                }
                $usedEmails[] = $email;
                
                // Generate unique employee ID using department ID
                $uniqueEmployeeId = "{$department->id}-{$staffNumber}";
                
                // Ensure employee ID is unique
                $empIdCounter = 1;
                while (in_array($uniqueEmployeeId, $usedEmployeeIds)) {
                    $uniqueEmployeeId = "{$department->id}-{$staffNumber}-{$empIdCounter}";
                    $empIdCounter++;
                }
                $usedEmployeeIds[] = $uniqueEmployeeId;
                
                // Generate unique phone number
                $phone = $this->generateUniquePhoneNumber($usedPhones);
                $usedPhones[] = $phone;
                
                User::create([
                    'name' => $fullName,
                    'email' => $email,
                    'password' => Hash::make('password123'), // Same password for all
                    'role' => 'staff',
                    'department_id' => $department->id,
                    'employee_id' => $uniqueEmployeeId,
                    'phone' => $phone,
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
                
                $staffCount++;
            }
        }

        $this->command->info("✅ Created {$staffCount} staff members across {$departments->count()} departments!");
        $this->command->info("📧 Email format: firstname.lastname##@[dept-code].bcp.edu.ph");
        $this->command->info("🔑 All passwords: password123");
        $this->command->info("📱 Employee IDs: [DEPT-ID]-01, [DEPT-ID]-02, etc. (e.g., 1-01, 2-01)");
        $this->command->info("👥 Each department now has 10 staff members!");
    }

    /**
     * Generate a unique realistic phone number
     */
    private function generateUniquePhoneNumber(array $usedPhones): string
    {
        $prefixes = ['0917', '0918', '0919', '0920', '0921', '0922', '0923', '0924', '0925', '0926', '0927', '0928', '0929', '0930'];
        
        do {
            $prefix = $prefixes[array_rand($prefixes)];
            $number = mt_rand(1000000, 9999999);
            $phone = $prefix . $number;
        } while (in_array($phone, $usedPhones));
        
        return $phone;
    }
}
