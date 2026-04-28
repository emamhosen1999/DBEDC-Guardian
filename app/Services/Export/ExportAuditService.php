<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class ExportAuditService
{
    /**
     * Log export operation
     */
    public function logExport(string $exportType, array $filters, int $recordCount, string $format, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'data_export',
            'entity_type' => 'export',
            'new_values' => [
                'export_type' => $exportType,
                'format' => $format,
                'filters' => $filters,
                'record_count' => $recordCount,
            ],
            'description' => "Export operation: {$exportType} ({$format}) with {$recordCount} records",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log export failure
     */
    public function logExportFailure(string $exportType, string $reason, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'export_failed',
            'entity_type' => 'export',
            'new_values' => [
                'export_type' => $exportType,
                'failure_reason' => $reason,
            ],
            'description' => "Export failed: {$exportType} - {$reason}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log bulk export operation
     */
    public function logBulkExport(string $exportType, array $exportDetails, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'bulk_export',
            'entity_type' => 'export',
            'new_values' => [
                'export_type' => $exportType,
                'export_details' => $exportDetails,
            ],
            'description' => "Bulk export operation: {$exportType}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log export access attempt (before permission check)
     */
    public function logExportAccessAttempt(string $exportType, bool $authorized, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => $authorized ? 'export_access_granted' : 'export_access_denied',
            'entity_type' => 'export',
            'new_values' => [
                'export_type' => $exportType,
                'authorized' => $authorized,
            ],
            'description' => "Export access ".($authorized ? 'granted' : 'denied').": {$exportType}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Generic log method
     */
    protected function log(array $data): void
    {
        \Log::info('Export Audit Log', $data);
    }
}
