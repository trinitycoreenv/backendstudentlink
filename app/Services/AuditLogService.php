<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    /**
     * Log an action
     */
    public function log(
        string $action,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $details = null,
        ?array $metadata = null,
        ?string $status = 'success'
    ): AuditLog {
        $user = Auth::user();
        $request = request();

        $auditLog = AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip() ?? '127.0.0.1',
            'user_agent' => $request?->userAgent() ?? 'Unknown',
            'status' => $status,
            'details' => $details,
            'metadata' => $metadata,
        ]);

        // Log to Laravel log as well for debugging
        Log::info('Audit Log Created', [
            'audit_log_id' => $auditLog->id,
            'user_id' => $user?->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);

        return $auditLog;
    }

    /**
     * Log user authentication
     */
    public function logAuth(string $action, ?User $user = null, ?string $status = 'success', ?string $details = null): AuditLog
    {
        return $this->log(
            action: $action,
            resourceType: 'user',
            resourceId: $user?->id,
            details: $details ?? "User {$action}",
            status: $status
        );
    }

    /**
     * Log CRUD operations
     */
    public function logCrud(
        string $action,
        string $resourceType,
        int $resourceId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $details = null
    ): AuditLog {
        return $this->log(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            oldValues: $oldValues,
            newValues: $newValues,
            details: $details ?? ucfirst($action) . " {$resourceType} #{$resourceId}"
        );
    }

    /**
     * Log system events
     */
    public function logSystem(string $action, ?string $details = null, ?array $metadata = null): AuditLog
    {
        return $this->log(
            action: $action,
            resourceType: 'system',
            details: $details ?? "System {$action}",
            metadata: $metadata
        );
    }

    /**
     * Log data export
     */
    public function logExport(string $exportType, ?array $filters = null, ?int $recordCount = null): AuditLog
    {
        return $this->log(
            action: 'export',
            resourceType: $exportType,
            details: "Exported {$exportType} data" . ($recordCount ? " ({$recordCount} records)" : ''),
            metadata: [
                'export_type' => $exportType,
                'filters' => $filters,
                'record_count' => $recordCount,
            ]
        );
    }

    /**
     * Log configuration changes
     */
    public function logConfig(string $action, ?array $oldValues = null, ?array $newValues = null): AuditLog
    {
        return $this->log(
            action: $action,
            resourceType: 'configuration',
            oldValues: $oldValues,
            newValues: $newValues,
            details: "Configuration {$action}"
        );
    }

    /**
     * Log failed actions
     */
    public function logFailure(string $action, ?string $resourceType = null, ?int $resourceId = null, ?string $error = null): AuditLog
    {
        return $this->log(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            details: $error ?? "Failed to {$action}",
            status: 'failed'
        );
    }

    /**
     * Get audit logs with filters
     */
    public function getLogs(array $filters = [], int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = AuditLog::with('user');

        // Apply filters
        if (!empty($filters['action'])) {
            $query->byAction($filters['action']);
        }

        if (!empty($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        if (!empty($filters['resource_type'])) {
            $query->byResourceType($filters['resource_type']);
        }

        if (!empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $query->byDateRange($filters['date_from'], $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('details', 'like', "%{$search}%")
                  ->orWhere('resource_type', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get audit statistics
     */
    public function getStatistics(array $filters = []): array
    {
        $query = AuditLog::query();

        // Apply date range if provided
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $query->byDateRange($filters['date_from'], $filters['date_to']);
        }

        $totalLogs = $query->count();
        $successLogs = (clone $query)->byStatus('success')->count();
        $failedLogs = (clone $query)->byStatus('failed')->count();
        $warningLogs = (clone $query)->byStatus('warning')->count();

        $actionStats = (clone $query)
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->pluck('count', 'action')
            ->toArray();

        $userStats = (clone $query)
            ->with('user')
            ->selectRaw('user_id, COUNT(*) as count')
            ->groupBy('user_id')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->mapWithKeys(function ($log) {
                return [$log->user->name ?? 'Unknown' => $log->count];
            })
            ->toArray();

        return [
            'total_logs' => $totalLogs,
            'success_logs' => $successLogs,
            'failed_logs' => $failedLogs,
            'warning_logs' => $warningLogs,
            'action_stats' => $actionStats,
            'user_stats' => $userStats,
        ];
    }

    /**
     * Clean up old audit logs
     */
    public function cleanup(int $daysToKeep = 90): int
    {
        $cutoffDate = now()->subDays($daysToKeep);
        
        return AuditLog::where('created_at', '<', $cutoffDate)->delete();
    }
}