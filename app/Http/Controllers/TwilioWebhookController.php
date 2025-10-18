<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TwilioWebhookController extends Controller
{
    /**
     * Handle Twilio webhook for message status updates
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $messageSid = $request->input('MessageSid');
            $messageStatus = $request->input('MessageStatus');
            $to = $request->input('To');
            $from = $request->input('From');
            $errorCode = $request->input('ErrorCode');
            $errorMessage = $request->input('ErrorMessage');

            Log::info('Twilio webhook received', [
                'message_sid' => $messageSid,
                'status' => $messageStatus,
                'to' => $to,
                'from' => $from,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            // Update OTP record with delivery status
            if ($messageSid) {
                $this->updateOtpDeliveryStatus($messageSid, $messageStatus, $errorCode, $errorMessage);
            }

            // Log delivery status
            $this->logDeliveryStatus($messageSid, $messageStatus, $to, $errorCode, $errorMessage);

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Twilio webhook error: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Update OTP delivery status in database
     */
    private function updateOtpDeliveryStatus(string $messageSid, string $status, ?string $errorCode, ?string $errorMessage): void
    {
        try {
            // Find OTP record by message SID (stored in metadata)
            $otpRecord = DB::table('otp_verifications')
                ->where('metadata->message_sid', $messageSid)
                ->first();

            if ($otpRecord) {
                $metadata = json_decode($otpRecord->metadata ?? '{}', true);
                $metadata['delivery_status'] = $status;
                $metadata['delivery_updated_at'] = now()->toISOString();

                if ($errorCode) {
                    $metadata['error_code'] = $errorCode;
                }

                if ($errorMessage) {
                    $metadata['error_message'] = $errorMessage;
                }

                DB::table('otp_verifications')
                    ->where('id', $otpRecord->id)
                    ->update([
                        'metadata' => json_encode($metadata),
                        'updated_at' => now()
                    ]);

                Log::info("Updated OTP delivery status for message SID: {$messageSid}", [
                    'status' => $status,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to update OTP delivery status: " . $e->getMessage());
        }
    }

    /**
     * Log delivery status for monitoring
     */
    private function logDeliveryStatus(string $messageSid, string $status, string $to, ?string $errorCode, ?string $errorMessage): void
    {
        $logData = [
            'message_sid' => $messageSid,
            'status' => $status,
            'to' => $to,
            'timestamp' => now()->toISOString()
        ];

        if ($errorCode) {
            $logData['error_code'] = $errorCode;
        }

        if ($errorMessage) {
            $logData['error_message'] = $errorMessage;
        }

        switch ($status) {
            case 'delivered':
                Log::info('SMS delivered successfully', $logData);
                break;
            case 'failed':
                Log::error('SMS delivery failed', $logData);
                break;
            case 'undelivered':
                Log::warning('SMS undelivered', $logData);
                break;
            default:
                Log::info('SMS status update', $logData);
        }
    }

    /**
     * Get delivery statistics
     */
    public function getDeliveryStats(): JsonResponse
    {
        try {
            $stats = DB::table('otp_verifications')
                ->where('method', 'sms')
                ->whereNotNull('metadata')
                ->get()
                ->map(function ($record) {
                    $metadata = json_decode($record->metadata ?? '{}', true);
                    return [
                        'id' => $record->id,
                        'identifier' => $record->identifier,
                        'delivery_status' => $metadata['delivery_status'] ?? 'unknown',
                        'error_code' => $metadata['error_code'] ?? null,
                        'error_message' => $metadata['error_message'] ?? null,
                        'created_at' => $record->created_at,
                        'delivery_updated_at' => $metadata['delivery_updated_at'] ?? null,
                    ];
                });

            $summary = [
                'total_sms' => $stats->count(),
                'delivered' => $stats->where('delivery_status', 'delivered')->count(),
                'failed' => $stats->where('delivery_status', 'failed')->count(),
                'undelivered' => $stats->where('delivery_status', 'undelivered')->count(),
                'unknown' => $stats->where('delivery_status', 'unknown')->count(),
            ];

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'details' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get delivery stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get delivery statistics'
            ], 500);
        }
    }
}
