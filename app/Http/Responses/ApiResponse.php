<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a successful JSON response
     *
     * @param  mixed  $data  The response data
     * @param  string|null  $message  Optional success message
     * @param  int  $statusCode  HTTP status code
     */
    protected function successResponse($data = null, ?string $message = null, int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return an error JSON response
     *
     * @param  string  $message  Error message
     * @param  string|null  $errorCode  Specific error code
     * @param  int  $statusCode  HTTP status code
     * @param  array|null  $errors  Validation errors or additional error details
     */
    protected function errorResponse(string $message, ?string $errorCode = null, int $statusCode = 400, ?array $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errorCode !== null) {
            $response['error_code'] = $errorCode;
        }

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return a paginated JSON response
     *
     * @param  mixed  $data  The response data
     * @param  array  $pagination  Pagination metadata
     * @param  string|null  $message  Optional success message
     */
    protected function paginatedResponse($data, array $pagination, ?string $message = null): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => (int) ($pagination['current_page'] ?? 1),
                'last_page' => (int) ($pagination['last_page'] ?? 1),
                'per_page' => (int) ($pagination['per_page'] ?? 15),
                'total' => (int) ($pagination['total'] ?? 0),
                'from' => (int) ($pagination['from'] ?? null),
                'to' => (int) ($pagination['to'] ?? null),
            ],
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response);
    }

    /**
     * Return a not found response
     *
     * @param  string  $message  Error message
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 'NOT_FOUND', 404);
    }

    /**
     * Return a validation error response
     *
     * @param  array  $errors  Validation errors
     * @param  string  $message  Error message
     */
    protected function validationErrorResponse(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->errorResponse($message, 'VALIDATION_ERROR', 422, $errors);
    }

    /**
     * Return an unauthorized response
     *
     * @param  string  $message  Error message
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->errorResponse($message, 'UNAUTHORIZED', 401);
    }

    /**
     * Return a forbidden response
     *
     * @param  string  $message  Error message
     */
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->errorResponse($message, 'FORBIDDEN', 403);
    }
}
