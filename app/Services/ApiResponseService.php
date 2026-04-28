<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\CursorPaginator;

class ApiResponseService
{
    /**
     * Standard success response
     */
    public static function success($data = null, string $message = 'Operation successful', int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response, $statusCode);
    }

    /**
     * Standard error response
     */
    public static function error(string $message, $errors = null, int $statusCode = 400): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response, $statusCode);
    }

    /**
     * Validation error response
     */
    public static function validationError($errors, string $message = 'Validation failed'): JsonResponse
    {
        return self::error($message, $errors, 422);
    }

    /**
     * Not found response
     */
    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return self::error($message, null, 404);
    }

    /**
     * Unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized access'): JsonResponse
    {
        return self::error($message, null, 401);
    }

    /**
     * Forbidden response
     */
    public static function forbidden(string $message = 'Access forbidden'): JsonResponse
    {
        return self::error($message, null, 403);
    }

    /**
     * Server error response
     */
    public static function serverError(string $message = 'Internal server error'): JsonResponse
    {
        return self::error($message, null, 500);
    }

    /**
     * Paginated response
     */
    public static function paginated($data, LengthAwarePaginator|CursorPaginator $paginator, string $message = 'Data retrieved successfully'): JsonResponse
    {
        $paginationData = [
            'current_page' => method_exists($paginator, 'currentPage') ? $paginator->currentPage() : 1,
            'per_page' => method_exists($paginator, 'perPage') ? $paginator->perPage() : $paginator->count(),
            'total' => method_exists($paginator, 'total') ? $paginator->total() : $data->count(),
            'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : 1,
            'from' => method_exists($paginator, 'firstItem') ? $paginator->firstItem() : 1,
            'to' => method_exists($paginator, 'lastItem') ? $paginator->lastItem() : $data->count(),
        ];

        if ($paginator instanceof CursorPaginator) {
            $paginationData['next_cursor'] = $paginator->nextCursor()?->encode();
            $paginationData['prev_cursor'] = $paginator->previousCursor()?->encode();
        }

        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'pagination' => $paginationData,
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response);
    }

    /**
     * Created response
     */
    public static function created($data = null, string $message = 'Resource created successfully'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * Updated response
     */
    public static function updated($data = null, string $message = 'Resource updated successfully'): JsonResponse
    {
        return self::success($data, $message, 200);
    }

    /**
     * Deleted response
     */
    public static function deleted($data = null, string $message = 'Resource deleted successfully'): JsonResponse
    {
        return self::success($data, $message, 200);
    }

    /**
     * Bulk operation response
     */
    public static function bulkOperation(array $results, string $operation, string $message = null): JsonResponse
    {
        $message = $message ?? ucfirst($operation) . ' operation completed';
        
        $summary = [
            'total' => count($results),
            'successful' => count(array_filter($results, fn($r) => isset($r['success']) && $r['success'])),
            'failed' => count(array_filter($results, fn($r) => isset($r['success']) && !$r['success'])),
        ];

        $response = [
            'success' => true,
            'message' => $message,
            'data' => [
                'operation' => $operation,
                'results' => $results,
                'summary' => $summary,
            ],
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response);
    }

    /**
     * Export response
     */
    public static function export($data, string $filename, string $type = 'csv', string $message = 'Export completed successfully'): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => [
                'export_data' => $data,
                'filename' => $filename,
                'type' => $type,
                'total_records' => is_array($data) ? count($data) : 0,
            ],
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response);
    }

    /**
     * Statistics response
     */
    public static function statistics($data, string $message = 'Statistics retrieved successfully'): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => [
                'statistics' => $data,
                'generated_at' => now()->toISOString(),
            ],
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response);
    }

    /**
     * File upload response
     */
    public static function fileUpload($data, string $message = 'File uploaded successfully'): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => [
                'file' => $data,
                'uploaded_at' => now()->toISOString(),
            ],
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response);
    }

    /**
     * Search response
     */
    public static function search($data, $query, int $total, string $message = 'Search completed'): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => [
                'results' => $data,
                'query' => $query,
                'total_results' => $total,
                'searched_at' => now()->toISOString(),
            ],
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($response);
    }
}
