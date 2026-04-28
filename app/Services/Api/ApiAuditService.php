<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class ApiAuditService
{
    /**
     * Log API authentication event
     */
    public function logAuthentication(string $action, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => "api_auth_{$action}",
            'entity_type' => 'api',
            'description' => "API authentication event: {$action}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log API data access
     */
    public function logDataAccess(string $endpoint, string $method, array $filters = [], int $recordCount = 0, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'api_data_access',
            'entity_type' => 'api',
            'new_values' => [
                'endpoint' => $endpoint,
                'method' => $method,
                'filters' => $filters,
                'record_count' => $recordCount,
            ],
            'description' => "API data access: {$method} {$endpoint}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log API data modification
     */
    public function logDataModification(string $endpoint, string $method, array $oldValues = [], array $newValues = [], array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'api_data_modification',
            'entity_type' => 'api',
            'old_values' => $oldValues,
            'new_values' => [
                'endpoint' => $endpoint,
                'method' => $method,
                'data' => $newValues,
            ],
            'description' => "API data modification: {$method} {$endpoint}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log API error
     */
    public function logError(string $endpoint, string $method, string $errorMessage, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'api_error',
            'entity_type' => 'api',
            'new_values' => [
                'endpoint' => $endpoint,
                'method' => $method,
                'error_message' => $errorMessage,
            ],
            'description' => "API error: {$method} {$endpoint} - {$errorMessage}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log RBAC operation
     */
    public function logRbacOperation(string $operation, string $entityType, int $entityId, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => "rbac_{$operation}",
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => "RBAC operation: {$operation} on {$entityType} {$entityId}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$details,
        ]);
    }

    /**
     * Log rate limit violation
     */
    public function logRateLimitViolation(string $endpoint, string $limit, array $details = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'api_rate_limit_violation',
            'entity_type' => 'api',
            'new_values' => [
                'endpoint' => $endpoint,
                'limit' => $limit,
            ],
            'description' => "API rate limit violation: {$endpoint} exceeded limit {$limit}",
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
        \Log::info('API Audit Log', $data);
    }
}
