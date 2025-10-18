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

class ForgotPasswordController extends Controller
{
    /**
     * Send password reset code
     */
    public function sendResetCode(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'method' => 'required|in:email,sms',
                'target' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid request parameters'
                ], 422);
            }

            $method = $request->method;
            $target = $request->target;

            // Find user by email or phone
            $user = null;
            if ($method === 'email') {
                $user = User::where('email', $target)
                           ->orWhere('personal_email', $target)
                           ->first();
            } else {
                $user = User::where('phone', $target)->first();
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'No account found with the provided information'
                ], 404);
            }

            // Generate reset code
            $resetCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = now()->addMinutes(15); // Code expires in 15 minutes

            // Store reset code in session (in production, use Redis or database)
            session([
                'password_reset_code' => $resetCode,
                'password_reset_user_id' => $user->id,
                'password_reset_expires_at' => $expiresAt->timestamp,
            ]);

            // In a real application, you would send the code via email/SMS
            // For now, we'll just log it for testing
            Log::info("Password reset code for user {$user->student_id} ({$method}): {$resetCode}");

            return response()->json([
                'success' => true,
                'message' => "Password reset code sent to your {$method}",
                'debug_code' => app()->environment('local') ? $resetCode : null // Only in local environment
            ]);

        } catch (Exception $e) {
            Log::error('Failed to send password reset code: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send reset code. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Verify password reset code
     */
    public function verifyResetCode(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|size:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid reset code format'
                ], 422);
            }

            $storedCode = session('password_reset_code');
            $userId = session('password_reset_user_id');
            $expiresAt = session('password_reset_expires_at');

            if (!$storedCode || !$userId || !$expiresAt) {
                return response()->json([
                    'success' => false,
                    'message' => 'No reset code found. Please request a new one.'
                ], 400);
            }

            // Check if code has expired
            if (now()->timestamp > $expiresAt) {
                session()->forget(['password_reset_code', 'password_reset_user_id', 'password_reset_expires_at']);
                return response()->json([
                    'success' => false,
                    'message' => 'Reset code has expired. Please request a new one.'
                ], 400);
            }

            if ($storedCode !== $request->code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid reset code'
                ], 400);
            }

            // Code is valid, keep it in session for the next step
            return response()->json([
                'success' => true,
                'message' => 'Reset code verified successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Reset code verification failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify reset code. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Reset password with code
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|size:6',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $storedCode = session('password_reset_code');
            $userId = session('password_reset_user_id');
            $expiresAt = session('password_reset_expires_at');

            if (!$storedCode || !$userId || !$expiresAt) {
                return response()->json([
                    'success' => false,
                    'message' => 'No reset code found. Please request a new one.'
                ], 400);
            }

            // Check if code has expired
            if (now()->timestamp > $expiresAt) {
                session()->forget(['password_reset_code', 'password_reset_user_id', 'password_reset_expires_at']);
                return response()->json([
                    'success' => false,
                    'message' => 'Reset code has expired. Please request a new one.'
                ], 400);
            }

            if ($storedCode !== $request->code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid reset code'
                ], 400);
            }

            // Find the user
            $user = User::find($userId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
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

            // Clear reset session data
            session()->forget(['password_reset_code', 'password_reset_user_id', 'password_reset_expires_at']);

            Log::info("Password reset completed for user: {$user->student_id}");

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Password reset failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password. Please try again.',
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