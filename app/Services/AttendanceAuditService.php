<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class AttendanceAuditService
{
    /**
     * Log an attendance-related action for audit trail.
     */
    public function log(array $data): void
    {
        try {
            DB::table('attendance_audit_logs')->insert([
                'user_id' => $data['user_id'] ?? null,
                'attendance_id' => $data['attendance_id'] ?? null,
                'action' => $data['action'],
                'entity_type' => $data['entity_type'] ?? 'attendance',
                'old_values' => isset($data['old_values']) ? json_encode($data['old_values']) : null,
                'new_values' => isset($data['new_values']) ? json_encode($data['new_values']) : null,
                'ip_address' => $data['ip_address'] ?? request()->ip(),
                'user_agent' => $data['user_agent'] ?? request()->userAgent(),
                'reason' => $data['reason'] ?? null,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log attendance audit', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Log manual attendance update.
     */
    public function logAttendanceUpdate(int $userId, int $attendanceId, array $oldValues, array $newValues, string $reason = null): void
    {
        $this->log([
            'user_id' => $userId,
            'attendance_id' => $attendanceId,
            'action' => 'update',
            'entity_type' => 'attendance',
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason ?? 'Manual attendance update',
        ]);
    }

    /**
     * Log location data access.
     */
    public function logLocationAccess(int $userId, string $date, int $usersViewedCount): void
    {
        $this->log([
            'user_id' => $userId,
            'action' => 'view',
            'entity_type' => 'location',
            'new_values' => [
                'date' => $date,
                'users_viewed_count' => $usersViewedCount,
            ],
            'reason' => 'Location data accessed',
        ]);
    }

    /**
     * Log attendance export.
     */
    public function logAttendanceExport(int $userId, array $filters = []): void
    {
        $this->log([
            'user_id' => $userId,
            'action' => 'export',
            'entity_type' => 'attendance',
            'new_values' => [
                'filters' => $filters,
            ],
            'reason' => 'Attendance data exported',
        ]);
    }

    /**
     * Log attendance configuration change.
     */
    public function logConfigChange(int $userId, string $configType, array $oldValues, array $newValues): void
    {
        $this->log([
            'user_id' => $userId,
            'action' => 'update',
            'entity_type' => 'settings',
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => "Attendance configuration changed: {$configType}",
        ]);
    }

    /**
     * Get audit logs for a specific attendance record.
     */
    public function getAttendanceLogs(int $attendanceId, int $limit = 50): array
    {
        return DB::table('attendance_audit_logs')
            ->where('attendance_id', $attendanceId)
            ->orderBy('occurred_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get audit logs for a specific user.
     */
    public function getUserLogs(int $userId, int $limit = 100): array
    {
        return DB::table('attendance_audit_logs')
            ->where('user_id', $userId)
            ->orderBy('occurred_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get audit logs for a date range.
     */
    public function getLogsByDateRange(string $startDate, string $endDate, array $filters = []): array
    {
        $query = DB::table('attendance_audit_logs')
            ->whereBetween('occurred_at', [$startDate, $endDate]);

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->orderBy('occurred_at', 'desc')
            ->get()
            ->toArray();
    }
}
