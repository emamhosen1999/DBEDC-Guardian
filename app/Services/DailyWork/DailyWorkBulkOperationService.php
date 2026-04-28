<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Services\ApiResponseService;
use App\Services\DailyWork\DailyWorkAuditService;
use App\Services\DailyWork\DailyWorkCacheService;
use App\Services\DailyWork\DailyWorkRealtimeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyWorkBulkOperationService
{
    private DailyWorkAuditService $auditService;
    private DailyWorkCacheService $cacheService;
    private DailyWorkRealtimeService $realtimeService;

    public function __construct(
        DailyWorkAuditService $auditService,
        DailyWorkCacheService $cacheService,
        DailyWorkRealtimeService $realtimeService
    ) {
        $this->auditService = $auditService;
        $this->cacheService = $cacheService;
        $this->realtimeService = $realtimeService;
    }

    /**
     * Perform bulk status update
     */
    public function bulkUpdateStatus(array $dailyWorkIds, string $status, array $options = []): array
    {
        return $this->performBulkOperation(
            'bulk_status_update',
            $dailyWorkIds,
            function ($dailyWork) use ($status, $options) {
                $oldStatus = $dailyWork->status;
                $dailyWork->update(['status' => $status]);
                
                // Log individual status change
                $this->auditService->logStatusChange($dailyWork, $oldStatus, $status);
                
                return [
                    'id' => $dailyWork->id,
                    'old_status' => $oldStatus,
                    'new_status' => $status,
                ];
            },
            [
                'operation_type' => 'status_update',
                'target_status' => $status,
                'options' => $options,
            ]
        );
    }

    /**
     * Perform bulk assignment
     */
    public function bulkAssign(array $dailyWorkIds, int $userId, string $assignmentType = 'assigned'): array
    {
        return $this->performBulkOperation(
            'bulk_assignment',
            $dailyWorkIds,
            function ($dailyWork) use ($userId, $assignmentType) {
                $oldUserId = $dailyWork->$assignmentType;
                $dailyWork->update([$assignmentType => $userId]);
                
                // Log individual assignment change
                $this->auditService->logAssignmentChange($dailyWork, $assignmentType, $oldUserId, $userId);
                
                return [
                    'id' => $dailyWork->id,
                    'assignment_type' => $assignmentType,
                    'old_user_id' => $oldUserId,
                    'new_user_id' => $userId,
                ];
            },
            [
                'operation_type' => 'assignment',
                'assignment_type' => $assignmentType,
                'target_user_id' => $userId,
            ]
        );
    }

    /**
     * Perform bulk deletion
     */
    public function bulkDelete(array $dailyWorkIds, array $options = []): array
    {
        return $this->performBulkOperation(
            'bulk_delete',
            $dailyWorkIds,
            function ($dailyWork) use ($options) {
                // Log deletion before actually deleting
                $this->auditService->logDeletion($dailyWork);
                
                $dailyWork->delete();
                
                return [
                    'id' => $dailyWork->id,
                    'number' => $dailyWork->number,
                    'deleted_at' => now()->toISOString(),
                ];
            },
            [
                'operation_type' => 'deletion',
                'options' => $options,
            ]
        );
    }

    /**
     * Perform bulk submission
     */
    public function bulkSubmit(array $dailyWorkIds, array $submissionData, array $options = []): array
    {
        return $this->performBulkOperation(
            'bulk_submission',
            $dailyWorkIds,
            function ($dailyWork) use ($submissionData, $options) {
                $oldSubmissionTime = $dailyWork->rfi_submission_date;
                $dailyWork->update($submissionData);
                
                // Log submission change
                $this->auditService->logUpdate(
                    $dailyWork,
                    ['rfi_submission_date' => $oldSubmissionTime],
                    $submissionData
                );
                
                return [
                    'id' => $dailyWork->id,
                    'submission_data' => $submissionData,
                    'old_submission_time' => $oldSubmissionTime,
                ];
            },
            [
                'operation_type' => 'submission',
                'submission_data' => $submissionData,
                'options' => $options,
            ]
        );
    }

    /**
     * Perform bulk inspection update
     */
    public function bulkUpdateInspection(array $dailyWorkIds, array $inspectionData, array $options = []): array
    {
        return $this->performBulkOperation(
            'bulk_inspection_update',
            $dailyWorkIds,
            function ($dailyWork) use ($inspectionData, $options) {
                $oldInspectionData = [
                    'inspection_result' => $dailyWork->inspection_result,
                    'inspection_details' => $dailyWork->inspection_details,
                ];
                $dailyWork->update($inspectionData);
                
                // Log inspection update
                $this->auditService->logUpdate(
                    $dailyWork,
                    $oldInspectionData,
                    $inspectionData
                );
                
                return [
                    'id' => $dailyWork->id,
                    'inspection_data' => $inspectionData,
                    'old_inspection_data' => $oldInspectionData,
                ];
            },
            [
                'operation_type' => 'inspection_update',
                'inspection_data' => $inspectionData,
                'options' => $options,
            ]
        );
    }

    /**
     * Generic bulk operation handler
     */
    private function performBulkOperation(string $operationType, array $dailyWorkIds, callable $operation, array $auditData): array
    {
        $startTime = now();
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $errors = [];

        try {
            DB::beginTransaction();

            // Validate daily works exist and user has access
            $dailyWorks = DailyWork::whereIn('id', $dailyWorkIds)->get();
            $validIds = $dailyWorks->pluck('id')->toArray();
            $invalidIds = array_diff($dailyWorkIds, $validIds);

            if (!empty($invalidIds)) {
                $failureCount += count($invalidIds);
                foreach ($invalidIds as $invalidId) {
                    $errors[$invalidId] = 'Daily work not found or access denied';
                }
            }

            // Perform operation on valid daily works
            foreach ($dailyWorks as $dailyWork) {
                try {
                    $result = $operation($dailyWork);
                    $results['successful'][] = $result;
                    $successCount++;
                } catch (\Exception $e) {
                    $failureCount++;
                    $errors[$dailyWork->id] = $e->getMessage();
                    Log::error("Bulk operation failed for daily work {$dailyWork->id}: " . $e->getMessage());
                }
            }

            // Invalidate caches for affected daily works
            foreach ($validIds as $dailyWorkId) {
                $this->cacheService->invalidateDailyWorkCaches($dailyWorkId);
            }

            // Broadcast real-time updates
            if (!empty($validIds)) {
                $this->realtimeService->broadcastBulkUpdate($validIds, $operationType, $auditData['operation_type'] ?? 'bulk_operation');
            }

            // Log bulk operation
            $this->auditService->logBulkOperation($operationType, [
                'total_records' => count($dailyWorkIds),
                'successful_records' => $successCount,
                'failed_records' => $failureCount,
                'operation_details' => array_merge($auditData, [
                    'start_time' => $startTime->toISOString(),
                    'end_time' => now()->toISOString(),
                    'duration_seconds' => now()->diffInSeconds($startTime),
                ]),
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => $this->generateSuccessMessage($operationType, $successCount, $failureCount),
                'results' => $results,
                'summary' => [
                    'total_records' => count($dailyWorkIds),
                    'successful_records' => $successCount,
                    'failed_records' => $failureCount,
                    'success_rate' => count($dailyWorkIds) > 0 ? round(($successCount / count($dailyWorkIds)) * 100, 2) : 0,
                    'duration_seconds' => now()->diffInSeconds($startTime),
                ],
                'errors' => $errors,
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Bulk operation {$operationType} failed: " . $e->getMessage());

            return [
                'success' => false,
                'message' => "Bulk operation failed: " . $e->getMessage(),
                'results' => $results,
                'summary' => [
                    'total_records' => count($dailyWorkIds),
                    'successful_records' => $successCount,
                    'failed_records' => $failureCount,
                    'success_rate' => 0,
                ],
                'errors' => ['system' => $e->getMessage()],
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Generate success message based on operation type
     */
    private function generateSuccessMessage(string $operationType, int $successCount, int $failureCount): string
    {
        $operationName = $this->getOperationDisplayName($operationType);
        
        if ($failureCount === 0) {
            return "Successfully {$operationName} {$successCount} daily work(s)";
        } elseif ($successCount === 0) {
            return "Failed to {$operationName} any daily works";
        } else {
            return "Partially {$operationName} daily works: {$successCount} successful, {$failureCount} failed";
        }
    }

    /**
     * Get display name for operation type
     */
    private function getOperationDisplayName(string $operationType): string
    {
        $displayNames = [
            'bulk_status_update' => 'updated status for',
            'bulk_assignment' => 'assigned',
            'bulk_delete' => 'deleted',
            'bulk_submission' => 'submitted',
            'bulk_inspection_update' => 'updated inspection for',
        ];

        return $displayNames[$operationType] ?? 'processed';
    }

    /**
     * Validate bulk operation parameters
     */
    public function validateBulkOperation(array $dailyWorkIds, string $operationType, array $data = []): array
    {
        $errors = [];

        // Validate daily work IDs
        if (empty($dailyWorkIds)) {
            $errors['daily_work_ids'] = 'Daily work IDs are required';
        } elseif (count($dailyWorkIds) > 100) {
            $errors['daily_work_ids'] = 'Cannot process more than 100 daily works at once';
        }

        // Validate operation-specific data
        switch ($operationType) {
            case 'bulk_status_update':
                if (!isset($data['status']) || !in_array($data['status'], DailyWork::getStatuses())) {
                    $errors['status'] = 'Valid status is required';
                }
                break;

            case 'bulk_assignment':
                if (!isset($data['user_id']) || !is_numeric($data['user_id'])) {
                    $errors['user_id'] = 'Valid user ID is required';
                }
                break;

            case 'bulk_submission':
                if (!isset($data['submission_data']) || !is_array($data['submission_data'])) {
                    $errors['submission_data'] = 'Submission data is required';
                }
                break;

            case 'bulk_inspection_update':
                if (!isset($data['inspection_data']) || !is_array($data['inspection_data'])) {
                    $errors['inspection_data'] = 'Inspection data is required';
                }
                break;
        }

        return $errors;
    }

    /**
     * Get bulk operation preview
     */
    public function getBulkOperationPreview(array $dailyWorkIds, string $operationType, array $data = []): array
    {
        $dailyWorks = DailyWork::whereIn('id', $dailyWorkIds)
                           ->with(['inchargeUser:id,name', 'assignedUser:id,name'])
                           ->get();

        $preview = [
            'operation_type' => $operationType,
            'total_records' => count($dailyWorkIds),
            'valid_records' => $dailyWorks->count(),
            'invalid_records' => count($dailyWorkIds) - $dailyWorks->count(),
            'operation_details' => $data,
            'affected_records' => $dailyWorks->map(function ($work) {
                return [
                    'id' => $work->id,
                    'number' => $work->number,
                    'status' => $work->status,
                    'incharge' => $work->inchargeUser?->name,
                    'assigned' => $work->assignedUser?->name,
                    'location' => $work->location,
                ];
            })->toArray(),
            'estimated_duration' => $this->estimateOperationDuration($dailyWorks->count(), $operationType),
        ];

        return [
            'success' => true,
            'message' => 'Bulk operation preview generated',
            'preview' => $preview,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Estimate operation duration based on record count and operation type
     */
    private function estimateOperationDuration(int $recordCount, string $operationType): array
    {
        $baseTimes = [
            'bulk_status_update' => 0.1, // 100ms per record
            'bulk_assignment' => 0.1,
            'bulk_delete' => 0.05,
            'bulk_submission' => 0.15,
            'bulk_inspection_update' => 0.12,
        ];

        $baseTime = $baseTimes[$operationType] ?? 0.1;
        $estimatedSeconds = $recordCount * $baseTime;
        
        return [
            'seconds' => round($estimatedSeconds, 2),
            'minutes' => round($estimatedSeconds / 60, 2),
            'formatted' => $this->formatDuration($estimatedSeconds),
        ];
    }

    /**
     * Format duration in human readable format
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . ' seconds';
        } elseif ($seconds < 3600) {
            $minutes = round($seconds / 60, 1);
            return $minutes . ' minute' . ($minutes != 1 ? 's' : '');
        } else {
            $hours = round($seconds / 3600, 1);
            return $hours . ' hour' . ($hours != 1 ? 's' : '');
        }
    }

    /**
     * Get available bulk operations
     */
    public function getAvailableOperations(): array
    {
        return [
            'bulk_status_update' => [
                'name' => 'Update Status',
                'description' => 'Update status for multiple daily works',
                'required_fields' => ['status'],
                'optional_fields' => ['reason', 'notify_users'],
            ],
            'bulk_assignment' => [
                'name' => 'Assign Users',
                'description' => 'Assign users to multiple daily works',
                'required_fields' => ['user_id', 'assignment_type'],
                'optional_fields' => ['notify_users'],
            ],
            'bulk_delete' => [
                'name' => 'Delete Works',
                'description' => 'Delete multiple daily works',
                'required_fields' => [],
                'optional_fields' => ['reason', 'confirm'],
            ],
            'bulk_submission' => [
                'name' => 'Submit Works',
                'description' => 'Submit multiple daily works for review',
                'required_fields' => ['submission_data'],
                'optional_fields' => ['notify_users'],
            ],
            'bulk_inspection_update' => [
                'name' => 'Update Inspection',
                'description' => 'Update inspection results for multiple works',
                'required_fields' => ['inspection_data'],
                'optional_fields' => ['notify_users'],
            ],
        ];
    }

    /**
     * Get bulk operation history
     */
    public function getOperationHistory(array $filters = []): array
    {
        $query = DailyWorkAudit::where('is_bulk_operation', true)
                              ->with(['user:id,name'])
                              ->orderBy('created_at', 'desc');

        if (isset($filters['operation_type'])) {
            $query->where('action', $filters['operation_type']);
        }

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $operations = $query->paginate($filters['per_page'] ?? 20);

        return [
            'operations' => $operations->items(),
            'pagination' => [
                'current_page' => $operations->currentPage(),
                'per_page' => $operations->perPage(),
                'total' => $operations->total(),
                'last_page' => $operations->lastPage(),
            ],
            'filters' => $filters,
        ];
    }
}
