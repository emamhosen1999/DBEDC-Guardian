<?php

use App\Http\Middleware\ApiSecurityMiddleware;
use App\Http\Middleware\AttendanceRateLimit;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\DeviceAuthMiddleware;
use App\Http\Middleware\EnhancedRateLimit;
use App\Http\Middleware\EnsureRolePermissionSync;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogRequestMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackSecurityActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // ADMS routes registered here (no prefix, no CSRF) so ZKTeco devices
        // reach /iclock/cdata directly — the MB460 hardcodes that path.
        then: function () {
            Route::middleware('throttle:300,1')
                ->group(base_path('routes/iclock.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetLocale::class,
            DeviceAuthMiddleware::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackSecurityActivity::class,
            \App\Http\Middleware\DisableCacheHeaders::class,
            // NOTE: The custom CheckSessionExpiry sliding-window middleware was
            // removed. It duplicated Laravel's native database-session idle
            // lifetime (config('session.lifetime')) using a separate payload key,
            // creating a second, competing web-only expiry timer. Native session
            // lifetime is now the single source of truth for web idle timeout,
            // matching the mobile token sliding window. See docs/session-expiry-policy.md.
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\DisableCacheHeaders::class,
        ]);

        $middleware->append(LogRequestMiddleware::class);

        // Register custom middleware aliases
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'custom_permission' => CheckPermission::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'api_security' => ApiSecurityMiddleware::class,
            'security_headers' => SecurityHeaders::class,
            'enhanced_rate_limit' => EnhancedRateLimit::class,
            'attendance.rate_limit' => AttendanceRateLimit::class,
            'role_permission_sync' => EnsureRolePermissionSync::class,
            'track_security' => TrackSecurityActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Standardize all API exception responses
        $exceptions->render(function (Throwable $e, $request) {
            // Only standardize API requests
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            $statusCode = 500;
            $errorCode = 'INTERNAL_SERVER_ERROR';
            $message = 'An unexpected error occurred. Please try again.';

            if ($e instanceof HttpExceptionInterface) {
                if ($e->getStatusCode() === 204) {
                    return null;
                }
                $statusCode = $e->getStatusCode();
                $errorCode = 'HTTP_EXCEPTION';
                $message = $e->getMessage() ?: $message;
            }

            // Determine error code and message based on exception type
            if ($e instanceof AuthenticationException) {
                $statusCode = 401;
                $errorCode = 'AUTHENTICATION_REQUIRED';
                $message = 'Authentication required. Please login to continue.';
            } elseif ($e instanceof AuthorizationException || $e instanceof UnauthorizedException) {
                $statusCode = 403;
                $errorCode = 'FORBIDDEN';
                $message = 'You do not have permission to perform this action.';
            } elseif ($e instanceof ModelNotFoundException) {
                $statusCode = 404;
                $errorCode = 'NOT_FOUND';
                $message = 'The requested resource was not found.';
            } elseif ($e instanceof ValidationException) {
                $statusCode = 422;
                $errorCode = 'VALIDATION_ERROR';
                $message = 'The given data was invalid.';
            } elseif ($e instanceof NotFoundHttpException) {
                $statusCode = 404;
                $errorCode = 'NOT_FOUND';
                $message = 'The requested endpoint was not found.';
            } elseif ($e instanceof MethodNotAllowedHttpException) {
                $statusCode = 405;
                $errorCode = 'METHOD_NOT_ALLOWED';
                $message = 'The request method is not allowed for this endpoint.';
            } elseif ($e instanceof TooManyRequestsHttpException) {
                $statusCode = 429;
                $errorCode = 'TOO_MANY_REQUESTS';
                $message = 'Too many requests. Please try again later.';
            } elseif ($e instanceof TokenMismatchException) {
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
            if ($e instanceof ValidationException) {
                $response['errors'] = $e->errors();
            }

            // Add redirect for authentication errors
            if ($e instanceof AuthenticationException || $e instanceof TokenMismatchException) {
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
                Log::error('API Server Exception', $logContext);
            } elseif (! in_array($statusCode, [401, 404, 419, 422])) {
                Log::warning('API Client Exception', $logContext);
            }

            return response()->json($response, $statusCode);
        });

        // Handle authentication exceptions for web requests (non-API)
        $exceptions->render(function (AuthenticationException $e, $request) {
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
        $exceptions->render(function (TokenMismatchException $e, $request) {
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
