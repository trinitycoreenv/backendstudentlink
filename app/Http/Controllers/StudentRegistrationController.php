<?php

namespace App\Http\Controllers;

use App\Services\StudentIdGeneratorService;
use App\Services\OtpVerificationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class StudentRegistrationController extends Controller
{
    protected StudentIdGeneratorService $studentIdGenerator;
    protected OtpVerificationService $otpService;

    public function __construct(StudentIdGeneratorService $studentIdGenerator, OtpVerificationService $otpService)
    {
        $this->studentIdGenerator = $studentIdGenerator;
        $this->otpService = $otpService;
    }

    /**
     * Step 1: Generate Student ID
     */
    public function generateStudentId(): JsonResponse
    {
        try {
            $studentId = $this->studentIdGenerator->generateStudentId();
            $schoolEmail = $this->studentIdGenerator->generateSchoolEmail($studentId);

            return response()->json([
                'success' => true,
                'data' => [
                    'student_id' => $studentId,
                    'school_email' => $schoolEmail,
                ],
                'message' => 'Student ID generated successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Failed to generate student ID: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate student ID. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Step 2-4: Validate registration data
     */
    public function validateRegistrationData(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'student_id' => 'required|string|size:9|regex:/^2509\d{5}$/',
                'first_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'last_name' => 'required|string|max:255',
                'suffix' => 'nullable|string|max:10',
                'personal_email' => 'required|email|max:255',
                'contact_number' => 'required|string|max:20',
                'birthday' => 'required|date|before:today',
                'civil_status' => 'required|in:single,married,widowed,separated',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate student ID format
            if (!$this->studentIdGenerator->validateStudentIdFormat($request->student_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid student ID format'
                ], 422);
            }

            // Check if student ID is still available for creation
            if (!$this->studentIdGenerator->isStudentIdAvailableForCreation($request->student_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student ID is no longer available. Please generate a new one.'
                ], 409);
            }

            // Check if personal email is already used
            $existingUser = User::where('email', $request->personal_email)->first();
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Personal email is already registered'
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Registration data is valid'
            ]);

        } catch (Exception $e) {
            Log::error('Registration validation failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }


    /**
     * Get registration status for a student ID
     */
    public function getRegistrationStatus(string $studentId): JsonResponse
    {
        try {
            $user = User::where('student_id', $studentId)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student ID not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'student_id' => $user->student_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'created_at' => $user->created_at,
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get registration status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get registration status',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Step 3: Send OTP for email verification
     */
    public function sendEmailOtp(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->email;

            // Check if email is already registered
            $existingUser = User::where('email', $email)
                ->orWhere('personal_email', $email)
                ->first();
            
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email address is already registered'
                ], 409);
            }

            $result = $this->otpService->sendEmailOtp($email, 'registration');

            return response()->json($result, $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Failed to send email OTP for registration: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Step 4: Send OTP for phone verification
     */
    public function sendPhoneOtp(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string|max:20'
            ]);
            
            // Custom phone validation
            $phoneNumber = $request->phone_number;
            if (!preg_match('/^(63|0)?9[0-9]{9}$/', $phoneNumber)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['phone_number' => ['Invalid phone number format. Please use format: 09123456789 or +639123456789']]
                ], 422);
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $phoneNumber = $request->phone_number;

            // Check if phone is already registered
            $existingUser = User::where('phone', $phoneNumber)->first();
            
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number is already registered'
                ], 409);
            }

            $result = $this->otpService->sendSmsOtp($phoneNumber, 'registration');

            return response()->json($result, $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Failed to send phone OTP for registration: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Step 5: Verify OTP codes
     */
    public function verifyOtps(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:255',
                'email_otp' => 'required|string|size:6|regex:/^\d{6}$/',
                'phone_number' => 'required|string|max:20',
                'phone_otp' => 'required|string|size:6|regex:/^\d{6}$/'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->email;
            $emailOtp = $request->email_otp;
            $phoneNumber = $request->phone_number;
            $phoneOtp = $request->phone_otp;

            // Verify email OTP
            $emailResult = $this->otpService->verifyOtp($email, $emailOtp, 'registration');
            if (!$emailResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email verification failed: ' . $emailResult['message']
                ], 400);
            }

            // Verify phone OTP
            $phoneResult = $this->otpService->verifyOtp($phoneNumber, $phoneOtp, 'registration');
            if (!$phoneResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone verification failed: ' . $phoneResult['message']
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Both email and phone numbers verified successfully',
                'data' => [
                    'email_verified_at' => $emailResult['verified_at'],
                    'phone_verified_at' => $phoneResult['verified_at']
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to verify OTPs for registration: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify OTPs. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Step 6: Create student account (updated with OTP verification)
     */
    public function createStudentAccount(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'student_id' => 'required|string|size:9|regex:/^2509\d{5}$/',
                'first_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'last_name' => 'required|string|max:255',
                'suffix' => 'nullable|string|max:10',
                'course' => 'required|string|max:255',
                'year_level' => 'required|string|max:50',
                'personal_email' => 'required|email|max:255',
                'contact_number' => 'required|string|max:20',
                'birthday' => 'required|date|before:today',
                'civil_status' => 'required|in:single,married,widowed,divorced,separated',
                'password' => 'required|string|min:8|confirmed',
                'email_otp' => 'required|string|size:6|regex:/^\d{6}$/',
                'phone_otp' => 'required|string|size:6|regex:/^\d{6}$/'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Start database transaction
            DB::beginTransaction();

            try {
                // Validate student ID format and availability
                if (!$this->studentIdGenerator->validateStudentIdFormat($request->student_id)) {
                    throw new Exception('Invalid student ID format');
                }

                if (!$this->studentIdGenerator->isStudentIdAvailableForCreation($request->student_id)) {
                    throw new Exception('Student ID is no longer available');
                }

                // Verify OTPs before creating account
                $emailResult = $this->otpService->verifyOtp($request->personal_email, $request->email_otp, 'registration');
                if (!$emailResult['success']) {
                    throw new Exception('Email verification failed: ' . $emailResult['message']);
                }

                $phoneResult = $this->otpService->verifyOtp($request->contact_number, $request->phone_otp, 'registration');
                if (!$phoneResult['success']) {
                    throw new Exception('Phone verification failed: ' . $phoneResult['message']);
                }

                // Check if personal email is already used
                $existingUser = User::where('email', $request->personal_email)
                    ->orWhere('personal_email', $request->personal_email)
                    ->first();
                if ($existingUser) {
                    throw new Exception('Personal email is already registered');
                }

                // Check if phone is already used
                $existingPhone = User::where('phone', $request->contact_number)->first();
                if ($existingPhone) {
                    throw new Exception('Phone number is already registered');
                }

                // Generate school email
                $schoolEmail = $this->studentIdGenerator->generateSchoolEmail($request->student_id);

                // Create full name
                $fullName = trim($request->first_name . ' ' . 
                    ($request->middle_name ? $request->middle_name . ' ' : '') . 
                    $request->last_name . 
                    ($request->suffix ? ' ' . $request->suffix : ''));

                // Map course to department
                $departmentId = $this->mapCourseToDepartment($request->course);

                // Create user account
                $user = User::create([
                    'student_id' => $request->student_id,
                    'name' => $fullName,
                    'first_name' => $request->first_name,
                    'middle_name' => $request->middle_name,
                    'last_name' => $request->last_name,
                    'suffix' => $request->suffix,
                    'email' => $schoolEmail, // Use school email as primary
                    'personal_email' => $request->personal_email,
                    'password' => Hash::make($request->password),
                    'role' => 'student',
                    'department_id' => $departmentId, // Assign department based on course
                    'course' => $request->course,
                    'year_level' => $request->year_level,
                    'phone' => $request->contact_number,
                    'birthday' => $request->birthday,
                    'civil_status' => $request->civil_status,
                    'is_active' => true,
                    'email_verified_at' => now(), // Mark as verified since OTP was verified
                    'preferences' => [
                        'theme' => 'light',
                        'language' => 'en',
                        'notifications' => [
                            'email' => true,
                            'push' => true,
                            'sms' => false
                        ]
                    ],
                ]);

                // Clean up the reserved student ID
                DB::table('student_id_reservations')
                    ->where('student_id', $request->student_id)
                    ->delete();

                // Commit transaction
                DB::commit();

                Log::info("Student account created successfully with OTP verification: {$user->student_id} - {$user->name}");

                return response()->json([
                    'success' => true,
                    'message' => 'Student account created successfully',
                    'data' => [
                        'user_id' => $user->id,
                        'student_id' => $user->student_id,
                        'name' => $user->name,
                        'first_name' => $user->first_name,
                        'middle_name' => $user->middle_name,
                        'last_name' => $user->last_name,
                        'suffix' => $user->suffix,
                        'email' => $user->email,
                        'personal_email' => $user->personal_email,
                        'course' => $user->course,
                        'year_level' => $user->year_level,
                        'phone' => $user->phone,
                        'birthday' => $user->birthday?->format('Y-m-d'),
                        'civil_status' => $user->civil_status,
                        'role' => $user->role,
                        'email_verified_at' => $user->email_verified_at?->toISOString(),
                    ]
                ], 201);

            } catch (Exception $e) {
                // Rollback transaction
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Log::error('Failed to create student account: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create student account. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Clean up expired student ID reservations (maintenance endpoint)
     */
    public function cleanupExpiredReservations(): JsonResponse
    {
        try {
            $cleanedCount = $this->studentIdGenerator->cleanupExpiredReservations();

            return response()->json([
                'success' => true,
                'message' => "Cleaned up {$cleanedCount} expired reservations"
            ]);

        } catch (Exception $e) {
            Log::error('Failed to cleanup expired reservations: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cleanup expired reservations',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Map course to department ID
     */
    private function mapCourseToDepartment(string $course): ?int
    {
        $courseMapping = [
            'BSAIS' => 1,  // BS in Accounting Information System
            'BSBA-FM' => 2,  // BSBA major in Financial Management
            'BSBA-HRM' => 3,  // BSBA major in Human Resource Management
            'BSBA-MM' => 4,  // BSBA major in Marketing Management
            'BSCPE' => 5,   // BS in Computer Engineering
            'BSIT' => 6,    // BS in Information Technology
            'BSCrim' => 7,  // BS in Criminology
            'BSPsych' => 8, // BS in Psychology
            'BSEntrep' => 9, // BS in Entrepreneurship
            'BSOA' => 10,   // BS in Office Administration
            'BSHM' => 11,   // BS in Hospitality Management
            'BSTM' => 12,   // BS in Tourism Management
            'BLIS' => 13,   // Bachelor in Library Information Science
            'BPED' => 14,   // Bachelor in Physical Education
            'BEED' => 15,   // Bachelor of Elementary Education
            'BSED-English' => 16,  // BSED major in English
            'BSED-Filipino' => 17, // BSED major in Filipino
            'BSED-Math' => 18,     // BSED major in Mathematics
            'BSED-Science' => 19,  // BSED major in Science
            'BSED-Social' => 20,   // BSED major in Social Studies
            'BSED-Values' => 21,   // BSED major in Values
            'BTLED' => 22,  // Bachelor of Technology and Livelihood Education
        ];

        return $courseMapping[$course] ?? null;
    }
}
