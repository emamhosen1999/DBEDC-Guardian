<?php

namespace App\Http\Middleware;

use App\Models\RequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Audit log of requests. The row is written in terminate(), i.e. after the response has been sent to the
 * client (FastCGI finishes the request first), so logging adds no latency and works with QUEUE_CONNECTION=sync.
 * Logging must never affect the request: every failure is swallowed and reported.
 */
class LogRequestMiddleware
{
    private const START = 'request_log.start';

    private const USER = 'request_log.user_id';

    private const REDACTED = '[REDACTED]';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::START, microtime(true));

        $response = $next($request);

        // Resolve the user now: terminate() runs after the session may have been written and closed.
        try {
            $request->attributes->set(self::USER, Auth::id());
        } catch (Throwable) {
            // ignore: the log row is simply anonymous
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! config('request-logs.enabled', true) || $this->shouldSkip($request)) {
                return;
            }

            $start = $request->attributes->get(self::START, microtime(true));

            RequestLog::create([
                'ip_address' => $request->ip(),
                'method' => $request->method(),
                'url' => $this->redactedUrl($request),
                'user_agent' => $request->userAgent() !== null ? mb_substr($request->userAgent(), 0, 1000) : null,
                'headers' => $this->redact($request->headers->all()),
                'request_body' => $this->requestBody($request),
                'response_status' => $response->getStatusCode(),
                'response_body' => $this->responseBody($request, $response),
                'user_id' => $request->attributes->get(self::USER),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
        } catch (Throwable $e) {
            // Never break the application over a log row. Log the class and message only (no payloads).
            try {
                Log::error('RequestLogMiddleware error: '.$e->getMessage(), ['url' => $request->path()]);
            } catch (Throwable) {
                // nothing left to do
            }
        }
    }

    private function shouldSkip(Request $request): bool
    {
        if ($request->is(config('request-logs.exclude', []))) {
            return true;
        }

        $extension = strtolower(pathinfo($request->path(), PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, config('request-logs.exclude_extensions', []), true);
    }

    private function isSensitiveKey(int|string $key): bool
    {
        $key = strtolower((string) $key);

        foreach (config('request-logs.redact_keys', []) as $fragment) {
            if (str_contains($key, strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively replace the value of every sensitive key.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function redactedUrl(Request $request): string
    {
        $query = $request->query();
        $url = $request->url();

        if ($query === []) {
            return mb_substr($url, 0, 2000);
        }

        return mb_substr($url.'?'.http_build_query($this->redact($query)), 0, 2000);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function requestBody(Request $request): ?array
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $body = $this->redact($request->except(array_keys($request->allFiles())));

        $json = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || strlen($json) > config('request-logs.max_request_bytes', 10000)) {
            return ['_truncated' => true];
        }

        return $body;
    }

    private function responseBody(Request $request, Response $response): ?string
    {
        if ($response->getStatusCode() < config('request-logs.response_body_min_status', 400)) {
            return null;
        }

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse
            || $request->is(config('request-logs.omit_response_body', []))) {
            return null;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return null;
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            return '[Binary Data]';
        }

        $max = config('request-logs.max_response_bytes', 10000);

        return strlen($content) > $max ? mb_strcut($content, 0, $max, 'UTF-8').'... [truncated]' : $content;
    }
}
