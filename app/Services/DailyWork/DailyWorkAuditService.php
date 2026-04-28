<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class DailyWorkAuditService
{
    /**
     * Log daily work creation
     */
    public function logCreation(DailyWork $dailyWork, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => DailyWorkAudit::ACTION_CREATED,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'entity_id' => $dailyWork->id,
            'new_values' => [
                'number' => $dailyWork->number,
                'status' => $dailyWork->status,
                'type' => $dailyWork->type,
                'location' => $dailyWork->location,
                'incharge' => $dailyWork->incharge,
                'assigned' => $dailyWork->assigned,
                'date' => $dailyWork->date,
            ],
            'description' => "Daily work '{$dailyWork->number}' was created",
            ...$data,
        ]);
    }

    /**
     * Log daily work update
     */
    public function logUpdate(DailyWork $dailyWork, array $oldValues, array $newValues, array $data = []): DailyWorkAudit
    {
        $changedFields = array_keys(array_diff_assoc($oldValues, $newValues));
        
        $description = "Daily work '{$dailyWork->number}' was updated";
        if (!empty($changedFields)) {
            $fields = implode(', ', $changedFields);
            $description .= " (changed: {$fields})";
        }

        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => DailyWorkAudit::ACTION_UPDATED,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'entity_id' => $dailyWork->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description,
            ...$data,
        ]);
    }

    /**
     * Log daily work deletion
     */
    public function logDeletion(DailyWork $dailyWork, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => DailyWorkAudit::ACTION_DELETED,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'entity_id' => $dailyWork->id,
            'old_values' => [
                'number' => $dailyWork->number,
                'status' => $dailyWork->status,
                'type' => $dailyWork->type,
                'location' => $dailyWork->location,
                'incharge' => $dailyWork->incharge,
                'assigned' => $dailyWork->assigned,
                'date' => $dailyWork->date,
            ],
            'description' => "Daily work '{$dailyWork->number}' was deleted",
            ...$data,
        ]);
    }

    /**
     * Log status change
     */
    public function logStatusChange(DailyWork $dailyWork, string $oldStatus, string $newStatus, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => DailyWorkAudit::ACTION_STATUS_CHANGED,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'entity_id' => $dailyWork->id,
            'old_values' => ['status' => $oldStatus],
            'new_values' => ['status' => $newStatus],
            'description' => "Status changed from '{$oldStatus}' to '{$newStatus}' for daily work '{$dailyWork->number}'",
            ...$data,
        ]);
    }

    /**
     * Log assignment change
     */
    public function logAssignmentChange(DailyWork $dailyWork, string $type, ?int $oldUserId, ?int $newUserId, array $data = []): DailyWorkAudit
    {
        $action = $newUserId ? DailyWorkAudit::ACTION_ASSIGNED : DailyWorkAudit::ACTION_UNASSIGNED;
        $description = $newUserId 
            ? "Daily work '{$dailyWork->number}' was assigned to user {$newUserId}"
            : "Daily work '{$dailyWork->number}' was unassigned from user {$oldUserId}";

        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => $action,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'entity_id' => $dailyWork->id,
            'old_values' => [$type => $oldUserId],
            'new_values' => [$type => $newUserId],
            'description' => $description,
            ...$data,
        ]);
    }

    /**
     * Log file upload
     */
    public function logFileUpload(DailyWork $dailyWork, string $fileName, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => DailyWorkAudit::ACTION_FILE_UPLOADED,
            'entity_type' => DailyWorkAudit::ENTITY_FILE,
            'entity_id' => null,
            'new_values' => ['file_name' => $fileName],
            'description' => "File '{$fileName}' was uploaded to daily work '{$dailyWork->number}'",
            ...$data,
        ]);
    }

    /**
     * Log file deletion
     */
    public function logFileDeletion(DailyWork $dailyWork, string $fileName, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => DailyWorkAudit::ACTION_FILE_DELETED,
            'entity_type' => DailyWorkAudit::ENTITY_FILE,
            'entity_id' => null,
            'old_values' => ['file_name' => $fileName],
            'description' => "File '{$fileName}' was deleted from daily work '{$dailyWork->number}'",
            ...$data,
        ]);
    }

    /**
     * Log objection creation
     */
    public function logObjectionCreation(DailyWork $dailyWork, int $objectionId, string $objectionTitle, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createAudit([
            'daily_work_id' => $dailyWork->id,
            'action' => DailyWorkAudit::ACTION_OBJECTION_CREATED,
            'entity_type' => DailyWorkAudit::ENTITY_OBJECTION,
            'entity_id' => $objectionId,
            'new_values' => [
                'objection_id' => $objectionId,
                'title' => $objectionTitle,
            ],
            'description' => "Objection '{$objectionTitle}' was created for daily work '{$dailyWork->number}'",
            ...$data,
        ]);
    }

    /**
     * Log bulk operation
     */
    public function logBulkOperation(string $action, array $details, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createBulkAudit([
            'action' => $action,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'description' => "Bulk operation: {$action}",
            'total_records' => $details['total_records'] ?? 0,
            'successful_records' => $details['successful_records'] ?? 0,
            'failed_records' => $details['failed_records'] ?? 0,
            'operation_details' => $details['operation_details'] ?? [],
            ...$data,
        ]);
    }

    /**
     * Log export operation
     */
    public function logExport(array $filters, int $recordCount, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createAudit([
            'action' => DailyWorkAudit::ACTION_EXPORTED,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'new_values' => [
                'filters' => $filters,
                'record_count' => $recordCount,
            ],
            'description' => "Daily works data exported ({$recordCount} records)",
            ...$data,
        ]);
    }

    /**
     * Log import operation
     */
    public function logImport(array $details, array $data = []): DailyWorkAudit
    {
        return DailyWorkAudit::createBulkAudit([
            'action' => DailyWorkAudit::ACTION_IMPORTED,
            'entity_type' => DailyWorkAudit::ENTITY_DAILY_WORK,
            'description' => "Daily works data imported",
            'total_records' => $details['total_records'] ?? 0,
            'successful_records' => $details['successful_records'] ?? 0,
            'failed_records' => $details['failed_records'] ?? 0,
            'operation_details' => $details['operation_details'] ?? [],
            ...$data,
        ]);
    }

    /**
     * Get audit trail for a specific daily work
     */
    public function getAuditTrail(int $dailyWorkId, array $filters = []): array
    {
        $query = DailyWorkAudit::where('daily_work_id', $dailyWorkId)
                              ->with(['user:id,name', 'dailyWork:id,number']);

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        $audits = $query->orderBy('created_at', 'desc')
                       ->paginate($filters['per_page'] ?? 50);

        return [
            'audits' => $audits->items(),
            'pagination' => [
                'current_page' => $audits->currentPage(),
                'per_page' => $audits->perPage(),
                'total' => $audits->total(),
                'last_page' => $audits->lastPage(),
            ],
            'statistics' => $this->getAuditStatistics($dailyWorkId),
        ];
    }

    /**
     * Get audit statistics for a daily work
     */
    public function getAuditStatistics(int $dailyWorkId): array
    {
        $query = DailyWorkAudit::where('daily_work_id', $dailyWorkId);

        return [
            'total_audits' => $query->count(),
            'actions_by_type' => (clone $query)->selectRaw('action, COUNT(*) as count')
                                   ->groupBy('action')
                                   ->pluck('count', 'action')
                                   ->toArray(),
            'actions_by_source' => (clone $query)->selectRaw('source, COUNT(*) as count')
                                     ->groupBy('source')
                                     ->pluck('count', 'source')
                                     ->toArray(),
            'bulk_operations' => (clone $query)->where('is_bulk_operation', true)->count(),
            'critical_actions' => (clone $query)->whereIn('action', [
                DailyWorkAudit::ACTION_DELETED,
                DailyWorkAudit::ACTION_OBJECTION_CREATED,
            ])->count(),
            'first_audit' => (clone $query)->oldest()->first(['created_at', 'action']),
            'last_audit' => (clone $query)->latest()->first(['created_at', 'action']),
        ];
    }

    /**
     * Get system-wide audit statistics
     */
    public function getSystemAuditStatistics(array $filters = []): array
    {
        return DailyWorkAudit::getStatistics($filters);
    }

    /**
     * Get recent audit activity
     */
    public function getRecentActivity(int $limit = 20, array $filters = []): array
    {
        $query = DailyWorkAudit::with(['user:id,name', 'dailyWork:id,number'])
                              ->orderBy('created_at', 'desc');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['critical_only']) && $filters['critical_only']) {
            $query->whereIn('action', [
                DailyWorkAudit::ACTION_DELETED,
                DailyWorkAudit::ACTION_BULK_DELETED,
                DailyWorkAudit::ACTION_OBJECTION_CREATED,
            ]);
        }

        return $query->limit($limit)->get()->toArray();
    }

    /**
     * Search audit logs
     */
    public function searchAudits(array $searchParams): array
    {
        $query = DailyWorkAudit::with(['user:id,name', 'dailyWork:id,number']);

        // Search by description
        if (isset($searchParams['query'])) {
            $query->where('description', 'like', '%' . $searchParams['query'] . '%');
        }

        // Filter by date range
        if (isset($searchParams['start_date'])) {
            $query->where('created_at', '>=', $searchParams['start_date']);
        }

        if (isset($searchParams['end_date'])) {
            $query->where('created_at', '<=', $searchParams['end_date']);
        }

        // Filter by user
        if (isset($searchParams['user_id'])) {
            $query->where('user_id', $searchParams['user_id']);
        }

        // Filter by action
        if (isset($searchParams['action'])) {
            $query->where('action', $searchParams['action']);
        }

        // Filter by source
        if (isset($searchParams['source'])) {
            $query->where('source', $searchParams['source']);
        }

        // Filter by bulk operations
        if (isset($searchParams['is_bulk'])) {
            $query->where('is_bulk_operation', $searchParams['is_bulk']);
        }

        $audits = $query->orderBy('created_at', 'desc')
                       ->paginate($searchParams['per_page'] ?? 50);

        return [
            'audits' => $audits->items(),
            'pagination' => [
                'current_page' => $audits->currentPage(),
                'per_page' => $audits->perPage(),
                'total' => $audits->total(),
                'last_page' => $audits->lastPage(),
            ],
            'filters' => $searchParams,
        ];
    }

    /**
     * Cleanup old audit logs
     */
    public function cleanupOldAudits(int $daysToKeep = 90): int
    {
        $cutoffDate = now()->subDays($daysToKeep);
        
        return DailyWorkAudit::where('created_at', '<', $cutoffDate)
                           ->delete();
    }

    /**
     * Export audit logs
     */
    public function exportAudits(array $filters = []): array
    {
        $query = DailyWorkAudit::with(['user:id,name', 'dailyWork:id,number']);

        // Apply filters
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        $audits = $query->orderBy('created_at', 'desc')
                       ->get();

        return [
            'audits' => $audits->map(function ($audit) {
                return [
                    'id' => $audit->id,
                    'daily_work_number' => $audit->dailyWork?->number,
                    'action' => $audit->action,
                    'description' => $audit->description,
                    'user_name' => $audit->user?->name,
                    'source' => $audit->source,
                    'ip_address' => $audit->ip_address,
                    'is_bulk_operation' => $audit->is_bulk_operation,
                    'created_at' => $audit->created_at->toISOString(),
                    'affected_fields' => $audit->affected_fields,
                ];
            })->toArray(),
            'total_count' => $audits->count(),
            'exported_at' => now()->toISOString(),
        ];
    }
}
