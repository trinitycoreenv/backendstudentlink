<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->middleware('auth:api', ['except' => ['login']]);
        $this->auditLogService = $auditLogService;
    }

    /**
     * User login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        try {
            // Find user by email
            $user = User::where('email', $credentials['email'])
                ->where('is_active', true)
                ->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Generate JWT token
            $token = JWTAuth::fromUser($user);

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not create token'
                ], 500);
            }

            // Update last login timestamp
            $user->update(['last_login_at' => now()]);

            // Log the login
            $this->auditLogService->logAuth('login', $user, 'success', 'User logged in successfully');

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'department' => $user->department ? $user->department->name : null,
                        'department_id' => $user->department_id,
                        'display_id' => $user->display_id,
                        'employee_id' => $user->employee_id,
                        'phone' => $user->phone,
                        'avatar' => $user->avatar,
                        'preferences' => $user->preferences,
                        'last_login_at' => $user->last_login_at,
                    ]
                ]
            ]);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not create token'
            ], 500);
        }
    }

    /**
     * Get the authenticated user
     */
    public function me(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'department' => $user->department ? $user->department->name : null,
                    'department_id' => $user->department_id,
                    'display_id' => $user->display_id,
                    'employee_id' => $user->employee_id,
                    'phone' => $user->phone,
                    'avatar' => $user->avatar,
                    'preferences' => $user->preferences,
                    'last_login_at' => $user->last_login_at,
                    'unread_notifications_count' => $user->unread_notifications_count,
                ]
            ]);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token is invalid'
            ], 401);
        }
    }

    /**
     * User logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Log the logout
            if ($user) {
                $this->auditLogService->log($user, 'logout', null, null, [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            // Invalidate the token
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Successfully logged out'
            ]);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout'
            ], 500);
        }
    }

    /**
     * Refresh JWT token
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            $user = auth()->user();

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $newToken,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'department' => $user->department ? $user->department->name : null,
                        'department_id' => $user->department_id,
                    ]
                ]
            ]);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token cannot be refreshed'
            ], 401);
        }
    }

    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        try {
            $user = User::where('email', $request->input('email'))->first();
            
            if (!$user || !$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active account found with this email address'
                ], 404);
            }

            // Generate reset token
            $token = \Str::random(64);
            
            // Store reset token (you would typically use a password_resets table)
            $user->update([
                'password_reset_token' => $token,
                'password_reset_expires' => now()->addHours(1),
            ]);

            // TODO: Send email with reset link
            // Mail::send('emails.password-reset', ['token' => $token], function($message) use ($user) {
            //     $message->to($user->email)->subject('Password Reset Request');
            // });

            // Log the password reset request
            $this->auditLogService->log($user, 'password_reset_request', null, null, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password reset instructions have been sent to your email',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send password reset email',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Reset password with token
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        try {
            $user = User::where('email', $request->input('email'))
                ->where('password_reset_token', $request->input('token'))
                ->where('password_reset_expires', '>', now())
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired reset token'
                ], 400);
            }

            // Update password and clear reset token
            $user->update([
                'password' => Hash::make($request->input('password')),
                'password_reset_token' => null,
                'password_reset_expires' => null,
            ]);

            // Log the password reset
            $this->auditLogService->log($user, 'password_reset', null, null, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password has been reset successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
