<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Twilio\Rest\Client as TwilioClient;
use Exception;

class OtpVerificationService
{
    /**
     * Generate a 6-digit OTP code
     */
    public function generateOtpCode(): string
    {
        return str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Send OTP via email
     */
    public function sendEmailOtp(string $email, string $purpose = 'registration'): array
    {
        try {
            $otpCode = $this->generateOtpCode();
            $expiresAt = now()->addMinutes(10); // 10 minutes expiry

            // Store OTP in database
            $this->storeOtp($email, $otpCode, 'email', $purpose, $expiresAt);

            // Send email
            $this->sendOtpEmail($email, $otpCode, $purpose);

            Log::info("Email OTP sent to: {$email} for purpose: {$purpose}");

            $response = [
                'success' => true,
                'message' => 'OTP sent to your email address',
                'expires_at' => $expiresAt->toISOString(),
                'expires_in_minutes' => 10
            ];

            // In local environment, include the OTP code for testing
            if (app()->environment('local')) {
                $response['debug_otp'] = $otpCode;
                $response['message'] = 'OTP sent to your email address (check logs for actual email)';
            }

            return $response;

        } catch (Exception $e) {
            Log::error("Failed to send email OTP to {$email}: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to send OTP to email address',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ];
        }
    }

    /**
     * Send OTP via SMS
     */
    public function sendSmsOtp(string $phoneNumber, string $purpose = 'registration'): array
    {
        try {
            $otpCode = $this->generateOtpCode();
            $expiresAt = now()->addMinutes(10); // 10 minutes expiry

            // Store OTP in database
            $this->storeOtp($phoneNumber, $otpCode, 'sms', $purpose, $expiresAt);

            // Send SMS (implement your SMS provider here)
            $this->sendOtpSms($phoneNumber, $otpCode, $purpose);

            Log::info("SMS OTP sent to: {$phoneNumber} for purpose: {$purpose}");

            $response = [
                'success' => true,
                'message' => 'OTP sent to your phone number',
                'expires_at' => $expiresAt->toISOString(),
                'expires_in_minutes' => 10
            ];

            // In local environment, include the OTP code for testing
            if (app()->environment('local')) {
                $response['debug_otp'] = $otpCode;
                $response['message'] = 'OTP sent to your phone number (check logs for actual SMS)';
            }

            return $response;

        } catch (Exception $e) {
            Log::error("Failed to send SMS OTP to {$phoneNumber}: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to send OTP to phone number',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ];
        }
    }

    /**
     * Verify OTP code
     */
    public function verifyOtp(string $identifier, string $code, string $purpose = 'registration'): array
    {
        try {
            // Get the most recent OTP for this identifier and purpose
            $otpRecord = DB::table('otp_verifications')
                ->where('identifier', $identifier)
                ->where('purpose', $purpose)
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$otpRecord) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired OTP code'
                ];
            }

            if ($otpRecord->code !== $code) {
                // Increment failed attempts
                DB::table('otp_verifications')
                    ->where('id', $otpRecord->id)
                    ->increment('failed_attempts');

                return [
                    'success' => false,
                    'message' => 'Invalid OTP code'
                ];
            }

            // Mark OTP as used
            DB::table('otp_verifications')
                ->where('id', $otpRecord->id)
                ->update([
                    'is_used' => true,
                    'verified_at' => now(),
                    'updated_at' => now()
                ]);

            Log::info("OTP verified successfully for: {$identifier} purpose: {$purpose}");

            return [
                'success' => true,
                'message' => 'OTP verified successfully',
                'verified_at' => now()->toISOString()
            ];

        } catch (Exception $e) {
            Log::error("Failed to verify OTP for {$identifier}: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to verify OTP',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ];
        }
    }

    /**
     * Check if identifier is verified for a specific purpose
     */
    public function isVerified(string $identifier, string $purpose = 'registration'): bool
    {
        return DB::table('otp_verifications')
            ->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('is_used', true)
            ->where('verified_at', '>', now()->subHours(24)) // Valid for 24 hours
            ->exists();
    }

    /**
     * Store OTP in database
     */
    private function storeOtp(string $identifier, string $code, string $method, string $purpose, $expiresAt): void
    {
        // Clean up old OTPs for this identifier and purpose
        DB::table('otp_verifications')
            ->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('is_used', false)
            ->delete();

        // Insert new OTP
        DB::table('otp_verifications')->insert([
            'identifier' => $identifier,
            'code' => $code,
            'method' => $method,
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'is_used' => false,
            'failed_attempts' => 0,
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Update OTP metadata
     */
    private function updateOtpMetadata(string $identifier, string $method, string $purpose, array $metadata): void
    {
        try {
            $otpRecord = DB::table('otp_verifications')
                ->where('identifier', $identifier)
                ->where('method', $method)
                ->where('purpose', $purpose)
                ->where('is_used', false)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($otpRecord) {
                $existingMetadata = json_decode($otpRecord->metadata ?? '{}', true);
                $updatedMetadata = array_merge($existingMetadata, $metadata);

                DB::table('otp_verifications')
                    ->where('id', $otpRecord->id)
                    ->update([
                        'metadata' => json_encode($updatedMetadata),
                        'updated_at' => now()
                    ]);
            }
        } catch (Exception $e) {
            Log::error("Failed to update OTP metadata: " . $e->getMessage());
        }
    }

    /**
     * Send OTP email
     */
    private function sendOtpEmail(string $email, string $otpCode, string $purpose): void
    {
        $subject = match($purpose) {
            'registration' => 'StudentLink Registration - Verification Code',
            'password_reset' => 'StudentLink Password Reset - Verification Code',
            'profile_update' => 'StudentLink Profile Update - Verification Code',
            default => 'StudentLink Verification Code'
        };

        $message = match($purpose) {
            'registration' => "Your StudentLink registration verification code is: {$otpCode}\n\nThis code will expire in 10 minutes.\n\nIf you didn't request this code, please ignore this email.",
            'password_reset' => "Your StudentLink password reset verification code is: {$otpCode}\n\nThis code will expire in 10 minutes.\n\nIf you didn't request this code, please ignore this email.",
            'profile_update' => "Your StudentLink profile update verification code is: {$otpCode}\n\nThis code will expire in 10 minutes.\n\nIf you didn't request this code, please ignore this email.",
            default => "Your StudentLink verification code is: {$otpCode}\n\nThis code will expire in 10 minutes."
        };

        // Log the email for debugging
        Log::info("EMAIL OTP for {$email}: {$otpCode}");
        
        // Check if we're in local development mode
        if (app()->environment('local')) {
            // In local development, just log the email
            Log::info("LOCAL DEV - Would send email to {$email} with subject: {$subject}");
            Log::info("LOCAL DEV - Email content: {$message}");
            return;
        }

        // In production, send actual email
        try {
            Mail::raw($message, function ($mail) use ($email, $subject) {
                $mail->to($email)
                     ->subject($subject)
                     ->from(config('mail.from.address'), config('mail.from.name'));
            });

            Log::info("Email sent successfully to {$email} via SMTP");
        } catch (Exception $e) {
            Log::error("Failed to send email to {$email}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send OTP SMS
     */
    private function sendOtpSms(string $phoneNumber, string $otpCode, string $purpose): void
    {
        $message = match($purpose) {
            'registration' => "Your StudentLink registration code is: {$otpCode}. Valid for 10 minutes.",
            'password_reset' => "Your StudentLink password reset code is: {$otpCode}. Valid for 10 minutes.",
            'profile_update' => "Your StudentLink profile update code is: {$otpCode}. Valid for 10 minutes.",
            default => "Your StudentLink verification code is: {$otpCode}. Valid for 10 minutes."
        };

        // Log the SMS for debugging
        Log::info("SMS OTP for {$phoneNumber}: {$otpCode}");
        
        // Check if we're in local development mode
        if (app()->environment('local')) {
            Log::info("LOCAL DEV - Would send SMS to {$phoneNumber}: {$message}");
            return;
        }

        // In production, send actual SMS via Twilio
        $twilioSid = config('twilio.sid');
        $twilioToken = config('twilio.token');
        $twilioFrom = config('twilio.from');

        if (!$twilioSid || !$twilioToken || !$twilioFrom) {
            Log::error('Twilio configuration missing. Cannot send SMS.');
            throw new Exception('SMS service configuration is missing');
        }
        
        try {
            $twilio = new TwilioClient($twilioSid, $twilioToken);
            
            // Ensure phone number has proper format
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            $messageResult = $twilio->messages->create($formattedPhone, [
                'from' => $twilioFrom,
                'body' => $message
            ]);

            // Store message SID for webhook tracking
            $this->updateOtpMetadata($phoneNumber, 'sms', 'registration', [
                'message_sid' => $messageResult->sid,
                'twilio_status' => 'sent',
                'sent_at' => now()->toISOString()
            ]);

            Log::info("SMS sent successfully to {$formattedPhone} via Twilio. Message SID: {$messageResult->sid}");

        } catch (Exception $e) {
            Log::error("Failed to send SMS via Twilio: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Format phone number for international SMS
     */
    private function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove any non-digit characters
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        // If it starts with 0, replace with +63 (Philippines country code)
        if (str_starts_with($phoneNumber, '0')) {
            $phoneNumber = '+63' . substr($phoneNumber, 1);
        }
        
        // If it doesn't start with +, add +63
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+63' . $phoneNumber;
        }
        
        return $phoneNumber;
    }

    /**
     * Clean up expired OTPs
     */
    public function cleanupExpiredOtps(): int
    {
        return DB::table('otp_verifications')
            ->where('expires_at', '<', now())
            ->orWhere('created_at', '<', now()->subDays(7)) // Clean up OTPs older than 7 days
            ->delete();
    }

    /**
     * Get OTP rate limit status
     */
    public function getRateLimitStatus(string $identifier, string $purpose = 'registration'): array
    {
        $recentOtps = DB::table('otp_verifications')
            ->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subMinutes(5))
            ->count();

        $canSend = $recentOtps < 3; // Max 3 OTPs per 5 minutes
        $nextAllowedAt = null;

        if (!$canSend) {
            $lastOtp = DB::table('otp_verifications')
                ->where('identifier', $identifier)
                ->where('purpose', $purpose)
                ->orderBy('created_at', 'desc')
                ->first();
            
            if ($lastOtp) {
                $nextAllowedAt = now()->parse($lastOtp->created_at)->addMinutes(5);
            }
        }

        return [
            'can_send' => $canSend,
            'recent_attempts' => $recentOtps,
            'max_attempts' => 3,
            'next_allowed_at' => $nextAllowedAt?->toISOString(),
            'cooldown_minutes' => $nextAllowedAt ? $nextAllowedAt->diffInMinutes(now()) : 0
        ];
    }
}
