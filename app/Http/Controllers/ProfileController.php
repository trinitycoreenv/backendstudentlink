<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ProfileController extends Controller
{
    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'course' => 'nullable|string|max:255',
                'year_level' => 'nullable|string|max:50',
                'personal_email' => 'nullable|email|max:255|unique:app_users,personal_email,' . $user->id,
                'phone' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Update only the provided fields
            $updateData = [];
            if ($request->has('course')) {
                $updateData['course'] = $request->course;
            }
            if ($request->has('year_level')) {
                $updateData['year_level'] = $request->year_level;
            }
            if ($request->has('personal_email')) {
                $updateData['personal_email'] = $request->personal_email;
            }
            if ($request->has('phone')) {
                $updateData['phone'] = $request->phone;
            }

            $user->update($updateData);

            Log::info("Profile updated for user: {$user->student_id}");

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
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
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Profile update failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Send verification code for email/phone changes
     */
    public function sendVerificationCode(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'method' => 'required|in:email,sms',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification method'
                ], 422);
            }

            $user = auth()->user();
            $method = $request->method;
            $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

            // Store verification code in session (in production, use Redis or database)
            session(['verification_code' => $code, 'verification_method' => $method]);

            // In a real application, you would send the code via email/SMS
            // For now, we'll just log it for testing
            Log::info("Verification code for {$method}: {$code}");

            return response()->json([
                'success' => true,
                'message' => "Verification code sent to your {$method}",
                'debug_code' => app()->environment('local') ? $code : null // Only in local environment
            ]);

        } catch (Exception $e) {
            Log::error('Failed to send verification code: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification code. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Verify the verification code
     */
    public function verifyCode(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|size:6',
                'method' => 'required|in:email,sms',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification code format'
                ], 422);
            }

            $storedCode = session('verification_code');
            $storedMethod = session('verification_method');

            if (!$storedCode || $storedMethod !== $request->method) {
                return response()->json([
                    'success' => false,
                    'message' => 'No verification code found. Please request a new one.'
                ], 400);
            }

            if ($storedCode !== $request->code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification code'
                ], 400);
            }

            // Clear the verification code after successful verification
            session()->forget(['verification_code', 'verification_method']);

            return response()->json([
                'success' => true,
                'message' => 'Code verified successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Code verification failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify code. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            // Validate new password requirements
            $newPassword = $request->new_password;
            $isValidPassword = $this->validatePasswordRequirements($newPassword);
            
            if (!$isValidPassword) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password must be at least 15 characters OR at least 8 characters with a number and lowercase letter'
                ], 422);
            }

            // Update password
            $user->update([
                'password' => Hash::make($newPassword)
            ]);

            Log::info("Password changed for user: {$user->student_id}");

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Password change failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Validate password requirements
     * Option A: At least 15 characters
     * Option B: At least 8 characters including a number and a lowercase letter
     */
    private function validatePasswordRequirements(string $password): bool
    {
        // Option A: At least 15 characters
        if (strlen($password) >= 15) {
            return true;
        }

        // Option B: At least 8 characters with number and lowercase letter
        if (strlen($password) >= 8) {
            $hasNumber = preg_match('/[0-9]/', $password);
            $hasLowercase = preg_match('/[a-z]/', $password);
            
            return $hasNumber && $hasLowercase;
        }

        return false;
    }
}