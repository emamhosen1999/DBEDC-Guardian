<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyWorkAudit extends Model
{
    protected $fillable = [
        'daily_work_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'description',
        'ip_address',
        'user_agent',
        'user_id',
        'session_id',
        'request_id',
        'is_bulk_operation',
        'bulk_operation_details',
        'source',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'bulk_operation_details' => 'array',
        'is_bulk_operation' => 'boolean',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    // Constants for actions
    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_DELETED = 'deleted';
    const ACTION_STATUS_CHANGED = 'status_changed';
    const ACTION_ASSIGNED = 'assigned';
    const ACTION_UNASSIGNED = 'unassigned';
    const ACTION_FILE_UPLOADED = 'file_uploaded';
    const ACTION_FILE_DELETED = 'file_deleted';
    const ACTION_OBJECTION_CREATED = 'objection_created';
    const ACTION_OBJECTION_UPDATED = 'objection_updated';
    const ACTION_OBJECTION_RESOLVED = 'objection_resolved';
    const ACTION_BULK_CREATED = 'bulk_created';
    const ACTION_BULK_UPDATED = 'bulk_updated';
    const ACTION_BULK_DELETED = 'bulk_deleted';
    const ACTION_EXPORTED = 'exported';
    const ACTION_IMPORTED = 'imported';

    // Constants for entity types
    const ENTITY_DAILY_WORK = 'daily_work';
    const ENTITY_OBJECTION = 'objection';
    const ENTITY_FILE = 'file';
    const ENTITY_USER = 'user';

    // Constants for sources
    const SOURCE_WEB = 'web';
    const SOURCE_MOBILE = 'mobile';
    const SOURCE_API = 'api';
    const SOURCE_CLI = 'cli';
    const SOURCE_SYSTEM = 'system';

    /**
     * Get the daily work that owns the audit.
     */
    public function dailyWork(): BelongsTo
    {
        return $this->belongsTo(DailyWork::class);
    }

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get audits by action.
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to get audits by entity.
     */
    public function scopeByEntity($query, string $entityType, int $entityId)
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    /**
     * Scope to get audits by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get audits by date range.
     */
    public function scopeByDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get bulk operations.
     */
    public function scopeBulkOperations($query)
    {
        return $query->where('is_bulk_operation', true);
    }

    /**
     * Scope to get individual operations.
     */
    public function scopeIndividualOperations($query)
    {
        return $query->where('is_bulk_operation', false);
    }

    /**
     * Scope to get audits by source.
     */
    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Get formatted action name.
     */
    public function getFormattedActionAttribute(): string
    {
        return ucwords(str_replace('_', ' ', $this->action));
    }

    /**
     * Get formatted source name.
     */
    public function getFormattedSourceAttribute(): string
    {
        return strtoupper($this->source);
    }

    /**
     * Check if this is a critical action.
     */
    public function isCriticalAction(): bool
    {
        $criticalActions = [
            self::ACTION_DELETED,
            self::ACTION_BULK_DELETED,
            self::ACTION_OBJECTION_CREATED,
        ];

        return in_array($this->action, $criticalActions);
    }

    /**
     * Get the change summary.
     */
    public function getChangeSummaryAttribute(): string
    {
        if ($this->action === self::ACTION_CREATED) {
            return 'Daily work was created';
        }

        if ($this->action === self::ACTION_DELETED) {
            return 'Daily work was deleted';
        }

        if ($this->action === self::ACTION_STATUS_CHANGED) {
            $oldStatus = $this->old_values['status'] ?? 'unknown';
            $newStatus = $this->new_values['status'] ?? 'unknown';
            return "Status changed from {$oldStatus} to {$newStatus}";
        }

        if ($this->action === self::ACTION_ASSIGNED) {
            return 'Work was assigned to user';
        }

        if ($this->action === self::ACTION_UNASSIGNED) {
            return 'Work was unassigned from user';
        }

        return $this->description ?? 'Action performed';
    }

    /**
     * Get the affected fields.
     */
    public function getAffectedFieldsAttribute(): array
    {
        if (!$this->old_values || !$this->new_values) {
            return [];
        }

        return array_keys(array_diff_assoc($this->old_values, $this->new_values));
    }

    /**
     * Create an audit record.
     */
    public static function createAudit(array $data): self
    {
        return static::create([
            'daily_work_id' => $data['daily_work_id'] ?? null,
            'action' => $data['action'],
            'entity_type' => $data['entity_type'] ?? self::ENTITY_DAILY_WORK,
            'entity_id' => $data['entity_id'] ?? $data['daily_work_id'] ?? null,
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'description' => $data['description'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()?->ip(),
            'user_agent' => $data['user_agent'] ?? request()?->userAgent(),
            'user_id' => $data['user_id'] ?? auth()->id(),
            'session_id' => $data['session_id'] ?? session()->getId(),
            'request_id' => $data['request_id'] ?? uniqid('req_', true),
            'is_bulk_operation' => $data['is_bulk_operation'] ?? false,
            'bulk_operation_details' => $data['bulk_operation_details'] ?? null,
            'source' => $data['source'] ?? self::detectSource(),
        ]);
    }

    /**
     * Detect the source of the request.
     */
    private static function detectSource(): string
    {
        $userAgent = request()?->userAgent() ?? '';

        if (str_contains($userAgent, 'Mobile') || str_contains($userAgent, 'Android') || str_contains($userAgent, 'iOS')) {
            return self::SOURCE_MOBILE;
        }

        if (str_contains($userAgent, 'Postman') || str_contains($userAgent, 'curl') || str_contains($userAgent, 'httpie')) {
            return self::SOURCE_API;
        }

        if (app()->runningInConsole()) {
            return self::SOURCE_CLI;
        }

        return self::SOURCE_WEB;
    }

    /**
     * Create bulk operation audit.
     */
    public static function createBulkAudit(array $data): self
    {
        return static::createAudit([
            ...$data,
            'is_bulk_operation' => true,
            'bulk_operation_details' => [
                'total_records' => $data['total_records'] ?? 0,
                'successful_records' => $data['successful_records'] ?? 0,
                'failed_records' => $data['failed_records'] ?? 0,
                'operation_details' => $data['operation_details'] ?? [],
            ],
        ]);
    }

    /**
     * Get audit statistics.
     */
    public static function getStatistics(array $filters = []): array
    {
        $query = static::query();

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('created_at', [$filters['start_date'], $filters['end_date']]);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['daily_work_id'])) {
            $query->where('daily_work_id', $filters['daily_work_id']);
        }

        return [
            'total_audits' => $query->count(),
            'bulk_operations' => (clone $query)->where('is_bulk_operation', true)->count(),
            'individual_operations' => (clone $query)->where('is_bulk_operation', false)->count(),
            'critical_actions' => (clone $query)->whereIn('action', [
                self::ACTION_DELETED,
                self::ACTION_BULK_DELETED,
                self::ACTION_OBJECTION_CREATED,
            ])->count(),
            'actions_by_type' => (clone $query)->selectRaw('action, COUNT(*) as count')
                                   ->groupBy('action')
                                   ->pluck('count', 'action')
                                   ->toArray(),
            'actions_by_source' => (clone $query)->selectRaw('source, COUNT(*) as count')
                                     ->groupBy('source')
                                     ->pluck('count', 'source')
                                     ->toArray(),
            'recent_activity' => (clone $query)->latest()
                                   ->limit(10)
                                   ->get(['action', 'description', 'created_at', 'user_id'])
                                   ->toArray(),
        ];
    }
}
