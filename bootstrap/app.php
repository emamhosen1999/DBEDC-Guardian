<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // ADMS routes registered here (no prefix, no CSRF) so ZKTeco devices
        // reach /iclock/cdata directly — the MB460 hardcodes that path.
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('throttle:300,1')
                ->group(base_path('routes/iclock.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\DeviceAuthMiddleware::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\TrackSecurityActivity::class,
            \App\Http\Middleware\CheckSessionExpiry::class, // Add session expiry check
        ]);

        $middleware->append(\App\Http\Middleware\LogRequestMiddleware::class);

        // Register custom middleware aliases
        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'custom_permission' => \App\Http\Middleware\CheckPermission::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'api_security' => \App\Http\Middleware\ApiSecurityMiddleware::class,
            'security_headers' => \App\Http\Middleware\SecurityHeaders::class,
            'enhanced_rate_limit' => \App\Http\Middleware\EnhancedRateLimit::class,
            'attendance.rate_limit' => \App\Http\Middleware\AttendanceRateLimit::class,
            'role_permission_sync' => \App\Http\Middleware\EnsureRolePermissionSync::class,
            'track_security' => \App\Http\Middleware\TrackSecurityActivity::class,
            'session_expiry' => \App\Http\Middleware\CheckSessionExpiry::class, // Register alias
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Standardize all API exception responses
        $exceptions->render(function (\Throwable $e, $request) {
            // Only standardize API requests
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }

            $statusCode = 500;
            $errorCode = 'INTERNAL_SERVER_ERROR';
            $message = 'An unexpected error occurred. Please try again.';

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                if ($e->getStatusCode() === 204) {
                    return null;
                }
                $statusCode = $e->getStatusCode();
                $errorCode = 'HTTP_EXCEPTION';
                $message = $e->getMessage() ?: $message;
            }

            // Determine error code and message based on exception type
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $statusCode = 401;
                $errorCode = 'AUTHENTICATION_REQUIRED';
                $message = 'Authentication required. Please login to continue.';
            } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException || $e instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
                $statusCode = 403;
                $errorCode = 'FORBIDDEN';
                $message = 'You do not have permission to perform this action.';
            } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $statusCode = 404;
                $errorCode = 'NOT_FOUND';
                $message = 'The requested resource was not found.';
            } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                $statusCode = 422;
                $errorCode = 'VALIDATION_ERROR';
                $message = 'The given data was invalid.';
            } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $statusCode = 404;
                $errorCode = 'NOT_FOUND';
                $message = 'The requested endpoint was not found.';
            } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                $statusCode = 405;
                $errorCode = 'METHOD_NOT_ALLOWED';
                $message = 'The request method is not allowed for this endpoint.';
            } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
                $statusCode = 429;
                $errorCode = 'TOO_MANY_REQUESTS';
                $message = 'Too many requests. Please try again later.';
            } elseif ($e instanceof \Illuminate\Session\TokenMismatchException) {
                $statusCode = 419;
                $errorCode = 'TOKEN_MISMATCH';
                $message = 'Your session has expired. Please login again.';
            }

            // Build error response
            $response = [
                'success' => false,
                'message' => $message,
                'error_code' => $errorCode,
            ];

            // Add validation errors if present
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $response['errors'] = $e->errors();
            }

            // Add redirect for authentication errors
            if ($e instanceof \Illuminate\Auth\AuthenticationException || $e instanceof \Illuminate\Session\TokenMismatchException) {
                $response['redirect'] = route('login');
            }

            // Log the error with context
            $logContext = [
                'error_code' => $errorCode,
                'message' => $e->getMessage(),
                'status_code' => $statusCode,
                'user_id' => $request->user()?->id,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ];

            if ($statusCode >= 500) {
                \Illuminate\Support\Facades\Log::error('API Server Exception', $logContext);
            } elseif (!in_array($statusCode, [401, 404, 419, 422])) {
                \Illuminate\Support\Facades\Log::warning('API Client Exception', $logContext);
            }

            return response()->json($response, $statusCode);
        });

        // Handle authentication exceptions for web requests (non-API)
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->header('X-Inertia')) {
                return response()->json([
                    'message' => 'Authentication required. Please login to continue.',
                    'redirect' => route('login'),
                    'session_expired' => true,
                ], 401);
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return null; // Handled by the global handler above
            }

            return redirect()->guest(route('login'))
                ->with('status', 'Please login to access this page.')
                ->with('session_expired', true);
        });

        // Handle session expired exceptions for web requests (non-API)
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            if ($request->header('X-Inertia')) {
                return response()->json([
                    'message' => 'Your session has expired. Please refresh the page and login again.',
                    'redirect' => route('login'),
                    'session_expired' => true,
                ], 419);
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return null; // Handled by the global handler above
            }

            return redirect()->route('login')
                ->with('status', 'Your session has expired. Please login again.')
                ->with('session_expired', true);
        });
    })->create();
