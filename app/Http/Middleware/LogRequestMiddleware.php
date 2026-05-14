<?php

namespace App\Http\Middleware;

use App\Models\RequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogRequestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Skip logging for certain routes (assets, health checks, etc.)
        if ($this->shouldSkipLogging($request)) {
            return $response;
        }

        // Limit response body size to avoid storing huge responses
        $responseBody = $response->getContent();
        if (strlen($responseBody) > 10000) {
            $responseBody = substr($responseBody, 0, 10000) . '... [truncated]';
        }

        RequestLog::create([
            'ip_address' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_agent' => $request->userAgent(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'request_body' => $this->sanitizeRequestBody($request),
            'response_status' => $response->getStatusCode(),
            'response_body' => $responseBody,
            'user_id' => Auth::id(),
            'duration_ms' => $duration,
        ]);

        return $response;
    }

    /**
     * Determine if the request should be skipped from logging.
     */
    private function shouldSkipLogging(Request $request): bool
    {
        $skipPatterns = [
            'telescope',
            'horizon',
            'debugbar',
            '_debugbar',
            'storage',
            'build',
            'assets',
            'favicon',
            'robots.txt',
        ];

        $url = $request->path();

        foreach ($skipPatterns as $pattern) {
            if (str_contains($url, $pattern)) {
                return true;
            }
        }

        // Skip static files
        if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)$/i', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize headers to remove sensitive information.
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveKeys = ['authorization', 'cookie', 'password', 'token'];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $headers[$key] = '[REDACTED]';
            }
        }

        return $headers;
    }

    /**
     * Sanitize request body to remove sensitive information.
     */
    private function sanitizeRequestBody(Request $request): array|null
    {
        if (! $request->isMethod('POST') && ! $request->isMethod('PUT') && ! $request->isMethod('PATCH')) {
            return null;
        }

        $body = $request->all();

        $sensitiveKeys = ['password', 'password_confirmation', 'current_password', 'token', 'secret'];

        foreach ($sensitiveKeys as $key) {
            if (isset($body[$key])) {
                $body[$key] = '[REDACTED]';
            }
        }

        // Limit body size
        $json = json_encode($body);
        if (strlen($json) > 10000) {
            return ['_truncated' => true];
        }

        return $body;
    }
}
