<?php

namespace App\Http\Controllers;

use App\Services\ApiResponseService;
use App\Services\DailyWork\DailyWorkBulkOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DailyWorkBulkOperationController extends Controller
{
    private DailyWorkBulkOperationService $bulkService;

    public function __construct(DailyWorkBulkOperationService $bulkService)
    {
        $this->bulkService = $bulkService;
    }

    /**
     * Perform bulk status update
     */
    public function bulkUpdateStatus(Request $request)
    {
        try {
            $request->validate([
                'daily_work_ids' => 'required|array|min:1|max:100',
                'daily_work_ids.*' => 'required|integer|exists:daily_works,id',
                'status' => 'required|string|in:' . implode(',', \App\Models\DailyWork::getStatuses()),
                'options' => 'nullable|array',
            ]);

            $dailyWorkIds = $request->get('daily_work_ids');
            $status = $request->get('status');
            $options = $request->get('options', []);

            $result = $this->bulkService->bulkUpdateStatus($dailyWorkIds, $status, $options);

            return ApiResponseService::bulkOperation(
                $result['summary']['successful_records'],
                $result['summary']['failed_records'],
                $result['message'],
                $result['summary'],
                $result['errors'] ?? []
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Bulk status update failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform bulk assignment
     */
    public function bulkAssign(Request $request)
    {
        try {
            $request->validate([
                'daily_work_ids' => 'required|array|min:1|max:100',
                'daily_work_ids.*' => 'required|integer|exists:daily_works,id',
                'user_id' => 'required|integer|exists:users,id',
                'assignment_type' => 'required|string|in:incharge,assigned',
                'options' => 'nullable|array',
            ]);

            $dailyWorkIds = $request->get('daily_work_ids');
            $userId = $request->get('user_id');
            $assignmentType = $request->get('assignment_type');
            $options = $request->get('options', []);

            $result = $this->bulkService->bulkAssign($dailyWorkIds, $userId, $assignmentType);

            return ApiResponseService::bulkOperation(
                $result['summary']['successful_records'],
                $result['summary']['failed_records'],
                $result['message'],
                $result['summary'],
                $result['errors'] ?? []
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Bulk assignment failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform bulk deletion
     */
    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'daily_work_ids' => 'required|array|min:1|max:100',
                'daily_work_ids.*' => 'required|integer|exists:daily_works,id',
                'options' => 'nullable|array',
            ]);

            $dailyWorkIds = $request->get('daily_work_ids');
            $options = $request->get('options', []);

            $result = $this->bulkService->bulkDelete($dailyWorkIds, $options);

            return ApiResponseService::bulkOperation(
                $result['summary']['successful_records'],
                $result['summary']['failed_records'],
                $result['message'],
                $result['summary'],
                $result['errors'] ?? []
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Bulk deletion failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform bulk submission
     */
    public function bulkSubmit(Request $request)
    {
        try {
            $request->validate([
                'daily_work_ids' => 'required|array|min:1|max:100',
                'daily_work_ids.*' => 'required|integer|exists:daily_works,id',
                'submission_data' => 'required|array',
                'submission_data.rfi_submission_date' => 'required|date',
                'options' => 'nullable|array',
            ]);

            $dailyWorkIds = $request->get('daily_work_ids');
            $submissionData = $request->get('submission_data');
            $options = $request->get('options', []);

            $result = $this->bulkService->bulkSubmit($dailyWorkIds, $submissionData, $options);

            return ApiResponseService::bulkOperation(
                $result['summary']['successful_records'],
                $result['summary']['failed_records'],
                $result['message'],
                $result['summary'],
                $result['errors'] ?? []
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Bulk submission failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform bulk inspection update
     */
    public function bulkUpdateInspection(Request $request)
    {
        try {
            $request->validate([
                'daily_work_ids' => 'required|array|min:1|max:100',
                'daily_work_ids.*' => 'required|integer|exists:daily_works,id',
                'inspection_data' => 'required|array',
                'inspection_data.inspection_result' => 'required|string|in:' . implode(',', \App\Models\DailyWork::getInspectionResults()),
                'options' => 'nullable|array',
            ]);

            $dailyWorkIds = $request->get('daily_work_ids');
            $inspectionData = $request->get('inspection_data');
            $options = $request->get('options', []);

            $result = $this->bulkService->bulkUpdateInspection($dailyWorkIds, $inspectionData, $options);

            return ApiResponseService::bulkOperation(
                $result['summary']['successful_records'],
                $result['summary']['failed_records'],
                $result['message'],
                $result['summary'],
                $result['errors'] ?? []
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Bulk inspection update failed: ' . $e->getMessage());
        }
    }

    /**
     * Get bulk operation preview
     */
    public function getPreview(Request $request)
    {
        try {
            $request->validate([
                'daily_work_ids' => 'required|array|min:1|max:100',
                'daily_work_ids.*' => 'required|integer|exists:daily_works,id',
                'operation_type' => 'required|string',
                'operation_data' => 'nullable|array',
            ]);

            $dailyWorkIds = $request->get('daily_work_ids');
            $operationType = $request->get('operation_type');
            $operationData = $request->get('operation_data', []);

            // Validate the operation
            $validationErrors = $this->bulkService->validateBulkOperation(
                $dailyWorkIds,
                $operationType,
                $operationData
            );

            if (!empty($validationErrors)) {
                return ApiResponseService::validation(
                    'Bulk operation validation failed',
                    $validationErrors
                );
            }

            $result = $this->bulkService->getBulkOperationPreview(
                $dailyWorkIds,
                $operationType,
                $operationData
            );

            return ApiResponseService::success(
                $result['preview'],
                $result['message']
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to generate bulk operation preview: ' . $e->getMessage());
        }
    }

    /**
     * Get available bulk operations
     */
    public function getAvailableOperations()
    {
        try {
            $operations = $this->bulkService->getAvailableOperations();

            return ApiResponseService::success(
                $operations,
                'Available bulk operations retrieved successfully'
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve available operations: ' . $e->getMessage());
        }
    }

    /**
     * Get bulk operation history
     */
    public function getHistory(Request $request)
    {
        try {
            $filters = [
                'operation_type' => $request->get('operation_type'),
                'start_date' => $request->get('start_date'),
                'end_date' => $request->get('end_date'),
                'user_id' => $request->get('user_id'),
                'per_page' => min($request->get('per_page', 20), 100),
            ];

            $result = $this->bulkService->getOperationHistory($filters);

            return ApiResponseService::success(
                $result,
                'Bulk operation history retrieved successfully'
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve bulk operation history: ' . $e->getMessage());
        }
    }

    /**
     * Get bulk operation statistics
     */
    public function getStatistics(Request $request)
    {
        try {
            $filters = [
                'start_date' => $request->get('start_date', now()->subDays(30)->format('Y-m-d')),
                'end_date' => $request->get('end_date', now()->format('Y-m-d')),
            ];

            $history = $this->bulkService->getOperationHistory($filters);
            $operations = collect($history['operations']);

            $statistics = [
                'total_operations' => $operations->count(),
                'operations_by_type' => $operations->groupBy('action')->map->count()->toArray(),
                'total_records_processed' => $operations->sum(function ($op) {
                    return $op->bulk_operation_details['total_records'] ?? 0;
                }),
                'success_rate' => $operations->avg(function ($op) {
                    $details = $op->bulk_operation_details ?? [];
                    $total = $details['total_records'] ?? 0;
                    $successful = $details['successful_records'] ?? 0;
                    return $total > 0 ? ($successful / $total) * 100 : 0;
                }),
                'average_duration' => $operations->avg(function ($op) {
                    return $op->bulk_operation_details['duration_seconds'] ?? 0;
                }),
                'recent_operations' => $operations->take(5)->map(function ($op) {
                    return [
                        'id' => $op->id,
                        'action' => $op->action,
                        'description' => $op->description,
                        'user_name' => $op->user->name ?? 'System',
                        'created_at' => $op->created_at->toISOString(),
                        'success_rate' => $this->calculateOperationSuccessRate($op),
                    ];
                })->toArray(),
                'filters' => $filters,
            ];

            return ApiResponseService::success(
                $statistics,
                'Bulk operation statistics retrieved successfully'
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve bulk operation statistics: ' . $e->getMessage());
        }
    }

    /**
     * Get mobile-optimized bulk operations data
     */
    public function getMobileData(Request $request)
    {
        try {
            $operations = $this->bulkService->getAvailableOperations();
            $history = $this->bulkService->getOperationHistory(['per_page' => 10]);

            $mobileData = [
                'available_operations' => array_map(function ($key, $operation) {
                    return [
                        'key' => $key,
                        'name' => $operation['name'],
                        'description' => $operation['description'],
                        'required_fields' => $operation['required_fields'],
                    ];
                }, array_keys($operations), $operations),
                'recent_operations' => array_map(function ($op) {
                    return [
                        'id' => $op['id'],
                        'action' => $op['action'],
                        'description' => $op['description'],
                        'user_name' => $op['user']['name'] ?? 'System',
                        'created_at' => $op['created_at'],
                        'success_rate' => $this->calculateOperationSuccessRate($op),
                    ];
                }, $history['operations']),
                'quick_stats' => [
                    'total_today' => collect($history['operations'])->filter(function ($op) {
                        return $op['created_at'] >= now()->startOfDay();
                    })->count(),
                    'success_rate_today' => $this->calculateTodaySuccessRate($history['operations']),
                ],
                'last_updated' => now()->toISOString(),
            ];

            return ApiResponseService::success(
                $mobileData,
                'Mobile bulk operations data retrieved successfully'
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve mobile bulk operations data: ' . $e->getMessage());
        }
    }

    /**
     * Calculate success rate for a single operation
     */
    private function calculateOperationSuccessRate($operation): float
    {
        $details = $operation->bulk_operation_details ?? [];
        $total = $details['total_records'] ?? 0;
        $successful = $details['successful_records'] ?? 0;
        
        return $total > 0 ? round(($successful / $total) * 100, 2) : 0;
    }

    /**
     * Calculate today's success rate
     */
    private function calculateTodaySuccessRate(array $operations): float
    {
        $todayOperations = collect($operations)->filter(function ($op) {
            return $op['created_at'] >= now()->startOfDay();
        });

        if ($todayOperations->isEmpty()) {
            return 0;
        }

        $totalSuccessRate = $todayOperations->sum(function ($op) {
            return $this->calculateOperationSuccessRate((object) $op);
        });

        return round($totalSuccessRate / $todayOperations->count(), 2);
    }
}
