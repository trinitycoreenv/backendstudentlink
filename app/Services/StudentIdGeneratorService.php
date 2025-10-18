<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class StudentIdGeneratorService
{
    /**
     * Generate a unique student ID in format: 2509XXXXX
     * 2509: Fixed prefix (always the same)
     * XXXXX: Random 5-digit number (00001-99999)
     * 
     * Example: 250912345
     */
    public function generateStudentId(): string
    {
        $maxRetries = 10;
        $retryCount = 0;
        $fixedPrefix = '2509';

        while ($retryCount < $maxRetries) {
            try {
                // Start database transaction
                DB::beginTransaction();

                // Generate random 5-digit number (00001-99999)
                $randomDigits = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                
                // Construct the student ID with fixed prefix
                $studentId = $fixedPrefix . $randomDigits;
                
                // Verify uniqueness
                if ($this->isStudentIdUnique($studentId)) {
                    // Store the generated ID to prevent duplicates
                    $this->reserveStudentId($studentId);
                    
                    // Commit transaction
                    DB::commit();
                    
                    Log::info("Student ID generated successfully: {$studentId}");
                    return $studentId;
                } else {
                    // Rollback and retry
                    DB::rollBack();
                    $retryCount++;
                    Log::warning("Student ID collision detected: {$studentId}, retry {$retryCount}");
                }
                
            } catch (Exception $e) {
                // Rollback transaction on error
                DB::rollBack();
                $retryCount++;
                
                Log::error("Error generating student ID (attempt {$retryCount}): " . $e->getMessage());
                
                if ($retryCount >= $maxRetries) {
                    throw new Exception("Failed to generate unique student ID after {$maxRetries} attempts: " . $e->getMessage());
                }
                
                // Wait before retry (exponential backoff)
                usleep(pow(2, $retryCount) * 100000); // 0.1s, 0.2s, 0.4s, etc.
            }
        }
        
        throw new Exception("Failed to generate unique student ID after {$maxRetries} attempts");
    }

    /**
     * Get the next sequential number for the current date (for tracking purposes)
     */
    private function getNextSequentialNumber(): int
    {
        // Use current date as key for tracking
        $dateKey = date('Ymd'); // YYYYMMDD format
        
        // Get the last used sequential number for today
        $lastNumber = DB::table('student_id_counters')
            ->where('date_key', $dateKey)
            ->value('last_number') ?? 0;
        
        return $lastNumber + 1;
    }

    /**
     * Check if student ID is unique
     */
    public function isStudentIdUnique(string $studentId): bool
    {
        // Check in users table
        $existsInUsers = DB::table('app_users')
            ->where('student_id', $studentId)
            ->exists();
        
        // Check in reserved IDs table (only active reservations)
        $existsInReserved = DB::table('student_id_reservations')
            ->where('student_id', $studentId)
            ->where('expires_at', '>', now())
            ->exists();
        
        return !$existsInUsers && !$existsInReserved;
    }

    /**
     * Check if student ID is available for account creation (allows own reservation)
     */
    public function isStudentIdAvailableForCreation(string $studentId): bool
    {
        // Check in users table
        $existsInUsers = DB::table('app_users')
            ->where('student_id', $studentId)
            ->exists();
        
        // For account creation, we allow the ID if it's reserved (since we're creating the account)
        // but not if it's already used by another user
        return !$existsInUsers;
    }

    /**
     * Reserve a student ID to prevent duplicates during registration
     */
    private function reserveStudentId(string $studentId): void
    {
        $dateKey = date('Ymd'); // YYYYMMDD format for tracking
        $sequentialNumber = $this->getNextSequentialNumber();
        
        // Reserve the ID for 30 minutes
        DB::table('student_id_reservations')->insert([
            'student_id' => $studentId,
            'date_key' => $dateKey,
            'sequential_number' => $sequentialNumber,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Update the counter for tracking purposes
        DB::table('student_id_counters')->updateOrInsert(
            ['date_key' => $dateKey],
            [
                'last_number' => $sequentialNumber,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Generate school email from student ID
     */
    public function generateSchoolEmail(string $studentId): string
    {
        return $studentId . '@bcp.edu.ph';
    }

    /**
     * Validate student ID format
     */
    public function validateStudentIdFormat(string $studentId): bool
    {
        // Should be exactly 9 digits: 2509XXXXX
        return preg_match('/^2509\d{5}$/', $studentId) === 1;
    }

    /**
     * Clean up expired reservations (should be called periodically)
     */
    public function cleanupExpiredReservations(): int
    {
        return DB::table('student_id_reservations')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
