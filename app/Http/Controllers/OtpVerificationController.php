<?php

namespace App\Http\Controllers;

use App\Services\OtpVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class OtpVerificationController extends Controller
{
    protected OtpVerificationService $otpService;

    public function __construct(OtpVerificationService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * Send OTP for email verification
     */
    public function sendEmailOtp(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:255',
                'purpose' => 'nullable|in:registration,password_reset,profile_update,login'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->email;
            $purpose = $request->purpose ?? 'registration';

            // Check rate limit
            $rateLimit = $this->otpService->getRateLimitStatus($email, $purpose);
            if (!$rateLimit['can_send']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many OTP requests. Please wait before requesting another code.',
                    'cooldown_minutes' => $rateLimit['cooldown_minutes'],
                    'next_allowed_at' => $rateLimit['next_allowed_at']
                ], 429);
            }

            $result = $this->otpService->sendEmailOtp($email, $purpose);

            return response()->json($result, $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Failed to send email OTP: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Send OTP for SMS verification
     */
    public function sendSmsOtp(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string|max:20',
                'purpose' => 'nullable|in:registration,password_reset,profile_update,login'
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
            $purpose = $request->purpose ?? 'registration';

            // Check rate limit
            $rateLimit = $this->otpService->getRateLimitStatus($phoneNumber, $purpose);
            if (!$rateLimit['can_send']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many OTP requests. Please wait before requesting another code.',
                    'cooldown_minutes' => $rateLimit['cooldown_minutes'],
                    'next_allowed_at' => $rateLimit['next_allowed_at']
                ], 429);
            }

            $result = $this->otpService->sendSmsOtp($phoneNumber, $purpose);

            return response()->json($result, $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Failed to send SMS OTP: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Verify OTP code
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'identifier' => 'required|string|max:255', // Email or phone number
                'code' => 'required|string|size:6|regex:/^\d{6}$/',
                'purpose' => 'nullable|in:registration,password_reset,profile_update,login'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $identifier = $request->identifier;
            $code = $request->code;
            $purpose = $request->purpose ?? 'registration';

            $result = $this->otpService->verifyOtp($identifier, $code, $purpose);

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (Exception $e) {
            Log::error('Failed to verify OTP: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify OTP. Please try again.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Check verification status
     */
    public function checkVerificationStatus(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'identifier' => 'required|string|max:255',
                'purpose' => 'nullable|in:registration,password_reset,profile_update,login'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $identifier = $request->identifier;
            $purpose = $request->purpose ?? 'registration';

            $isVerified = $this->otpService->isVerified($identifier, $purpose);

            return response()->json([
                'success' => true,
                'is_verified' => $isVerified,
                'message' => $isVerified ? 'Identifier is verified' : 'Identifier is not verified'
            ]);

        } catch (Exception $e) {
            Log::error('Failed to check verification status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to check verification status',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get rate limit status
     */
    public function getRateLimitStatus(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'identifier' => 'required|string|max:255',
                'purpose' => 'nullable|in:registration,password_reset,profile_update,login'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $identifier = $request->identifier;
            $purpose = $request->purpose ?? 'registration';

            $rateLimit = $this->otpService->getRateLimitStatus($identifier, $purpose);

            return response()->json([
                'success' => true,
                'data' => $rateLimit
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get rate limit status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get rate limit status',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Clean up expired OTPs (maintenance endpoint)
     */
    public function cleanupExpiredOtps(): JsonResponse
    {
        try {
            $cleanedCount = $this->otpService->cleanupExpiredOtps();

            return response()->json([
                'success' => true,
                'message' => "Cleaned up {$cleanedCount} expired OTPs"
            ]);

        } catch (Exception $e) {
            Log::error('Failed to cleanup expired OTPs: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cleanup expired OTPs',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }
}
