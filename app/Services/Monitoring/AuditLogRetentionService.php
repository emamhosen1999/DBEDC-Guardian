<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuditLogRetentionService
{
    /**
     * Retention period in days for different log types
     */
    protected array $retentionPeriods = [
        'authentication' => 365, // 1 year
        'authorization' => 365, // 1 year
        'data_modification' => 730, // 2 years
        'data_access' => 180, // 6 months
        'export' => 365, // 1 year
        'system' => 90, // 3 months
    ];

    /**
     * Clean up old audit logs based on retention policy
     */
    public function cleanupOldLogs(): int
    {
        $totalDeleted = 0;

        foreach ($this->retentionPeriods as $logType => $days) {
            $cutoffDate = Carbon::now()->subDays($days);
            
            $deleted = DB::table('audit_logs')
                ->where('action', 'like', "{$logType}%")
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            $totalDeleted += $deleted;

            Log::info("Audit log cleanup completed for {$logType}", [
                'deleted_count' => $deleted,
                'retention_days' => $days,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
            ]);
        }

        // Also clean up old error logs
        $errorDeleted = DB::table('error_logs')
            ->where('created_at', '<', Carbon::now()->subDays(90))
            ->delete();

        $totalDeleted += $errorDeleted;

        Log::info("Error log cleanup completed", [
            'deleted_count' => $errorDeleted,
        ]);

        return $totalDeleted;
    }

    /**
     * Archive old audit logs before deletion
     */
    public function archiveOldLogs(): int
    {
        // This would implement archiving logic
        // For now, just log the action
        Log::info("Audit log archival initiated");
        
        return 0;
    }

    /**
     * Get audit log statistics
     */
    public function getAuditLogStatistics(): array
    {
        $stats = [];

        foreach ($this->retentionPeriods as $logType => $days) {
            $stats[$logType] = [
                'total' => DB::table('audit_logs')
                    ->where('action', 'like', "{$logType}%")
                    ->count(),
                'retention_days' => $days,
                'cutoff_date' => Carbon::now()->subDays($days)->toDateTimeString(),
            ];
        }

        $stats['total_logs'] = DB::table('audit_logs')->count();
        $stats['oldest_log'] = DB::table('audit_logs')
            ->orderBy('created_at', 'asc')
            ->value('created_at');

        return $stats;
    }

    /**
     * Get retention period for a specific log type
     */
    public function getRetentionPeriod(string $logType): int
    {
        return $this->retentionPeriods[$logType] ?? 90;
    }

    /**
     * Set custom retention period for a log type
     */
    public function setRetentionPeriod(string $logType, int $days): void
    {
        $this->retentionPeriods[$logType] = $days;
    }
}
