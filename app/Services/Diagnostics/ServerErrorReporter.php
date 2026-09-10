<?php

namespace App\Services\Diagnostics;

use App\Models\ClientErrorLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Http\Exceptions\MaintenanceModeException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Captures SERVER-side exceptions into the same grouped triage model the mobile
 * app already feeds (see ClientErrorLog + /admin/client-errors).
 *
 * WHY THIS EXISTS
 * ---------------
 * Laravel's own 500s land in storage/logs/laravel.log on a shared cPanel host
 * that nobody opens. So when a user reports "the punch button failed", the
 * mobile half of the incident is visible in the triage board and the backend
 * half — the actual QueryException that caused it — is invisible. This closes
 * that loop into ONE screen, correlated by time and (where available) user.
 *
 * THREE HARD RULES
 * ----------------
 *  1. NEVER break a request. Every path is wrapped: if telemetry cannot be
 *     written (table missing mid-migration, DB down — which is *exactly* when
 *     exceptions spike), the failure is swallowed. A crash reporter that can
 *     crash the app is worse than no crash reporter.
 *  2. NEVER recurse. Writing the row runs queries that can themselves throw,
 *     which would re-enter the reporting hook and loop until the process dies.
 *     A re-entrancy flag makes the second entry a no-op.
 *  3. NEVER log expected client faults (see shouldCapture) — a triage board that
 *     fills with 404s from vulnerability scanners is a triage board nobody reads.
 */
class ServerErrorReporter
{
    /**
     * Re-entrancy flag (rule 2).
     *
     * Static, not a container binding: the guard has to work even when the
     * container itself is in a bad state, which is a live possibility given
     * *when* this code runs.
     */
    protected static bool $capturing = false;

    /** Stable per-request id so several exceptions in one request correlate. */
    protected static ?string $requestId = null;

    /**
     * Exception types that are EXPECTED outcomes, not faults. Skipping them is
     * the difference between a signal and a firehose.
     *
     * Each entry, and why it is noise rather than a bug:
     *
     *  - ModelNotFoundException / RecordsNotFoundException (404) — a stale link,
     *    a deleted record, a guessed id. Route-model binding raises these by
     *    design; scanners generate them by the thousand.
     *  - AuthenticationException (401) — an expired session or token. The single
     *    most common exception in any app with mobile clients; it means the auth
     *    system worked.
     *  - AuthorizationException (403) — a policy denied someone. That is the
     *    policy doing its job, and it is already recorded by the security/audit
     *    trail, which is the right place for it.
     *  - ValidationException (422) — user typed something invalid. By definition
     *    an expected branch; it is a form message, not an incident.
     *  - TokenMismatchException (419) — CSRF token aged out on a tab left open.
     *    Expected on a session-based app with an 8-hour lifetime.
     *  - MaintenanceModeException (503) — WE put the app in maintenance mode.
     *
     * Everything else below 500 is caught by the generic HttpExceptionInterface
     * rule in shouldCapture(): 404/405/410/413/429 and friends are client-fault
     * or rate-limiting-by-design.
     *
     * FRAMEWORK BOUNDARY: Laravel's handler lists HttpException in its own
     * `$internalDontReport`, so anything raised by abort() never reaches a
     * report() callback at all. That is fine — this board exists for the 500s
     * NOBODY chose, and the HttpExceptionInterface rule below still guards the
     * paths that do reach us (an explicit report($e), a custom 4xx exception
     * class). See test_a_deliberate_abort_is_not_reported_by_the_framework.
     *
     * @var array<int, class-string>
     */
    protected const IGNORED = [
        ModelNotFoundException::class,
        RecordsNotFoundException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        TokenMismatchException::class,
        MaintenanceModeException::class,
    ];

    /**
     * Entry point for the bootstrap/app.php reporting hook.
     *
     * Returns void and swallows everything — the caller must never have to
     * think about whether reporting can fail.
     */
    public static function capture(Throwable $e, ?Request $request = null): void
    {
        if (static::$capturing) {
            // Rule 2: an exception raised while recording an exception. Drop it
            // on the floor; the original is already being handled.
            return;
        }

        try {
            if (! static::shouldCapture($e)) {
                return;
            }

            static::$capturing = true;

            $request ??= static::currentRequest();

            ClientErrorLog::recordServer(
                static::sampleFor($e, $request),
                static::userId($request),
            );
        } catch (Throwable $failure) {
            // Rule 1. Nothing here may escape. Even the fallback log is guarded,
            // because a broken log driver is a plausible cause of getting here.
            try {
                error_log('[client-errors] server exception capture failed: '.$failure->getMessage());
            } catch (Throwable) {
                // Truly nothing left to do.
            }
        } finally {
            static::$capturing = false;
        }
    }

    /**
     * Is this a genuine fault worth an operator's attention?
     */
    public static function shouldCapture(Throwable $e): bool
    {
        // Console faults (queue workers, the scheduler, artisan) are out of
        // scope for this board: it triages *request* incidents, and console
        // output already has its own log destinations per scheduled command.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        // Never capture faults from the telemetry ingest endpoint itself to prevent meta-loops
        $request = static::currentRequest();
        if ($request && $request->is('api/v1/client-errors*')) {
            return false;
        }

        // Explicitly capture validation and data-integrity failures so invalid data
        // errors from clients/forms are visible in Client Diagnostics.
        if ($e instanceof ValidationException) {
            return true;
        }

        // Explicitly capture optimistic concurrency conflicts (stale writes).
        if ($e instanceof \App\Exceptions\StaleModelVersionException) {
            return true;
        }

        foreach (static::IGNORED as $ignored) {
            if ($e instanceof $ignored) {
                return false;
            }
        }

        // For HTTP exceptions: capture actionable 4xx errors (400 Bad Request, 409 Conflict, 422 Unprocessable),
        // while continuing to ignore background noise (404, 401, 403, 419, 429).
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            $status = $e->getStatusCode();
            if (in_array($status, [400, 409, 422], true)) {
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * Shape the server sample columns.
     *
     * @return array<string, mixed>
     */
    protected static function sampleFor(Throwable $e, ?Request $request): array
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $severity = $e instanceof \Error ? 'fatal' : ($status < 500 ? 'warning' : 'error');
        $context = [];

        // Specialized handling for ValidationException: surface failing fields & sanitized input
        if ($e instanceof ValidationException) {
            $status = 422;
            $severity = 'warning';
            $errors = $e->errors();
            $firstField = array_key_first($errors);
            $firstMsg = $firstField && ! empty($errors[$firstField]) ? (string) $errors[$firstField][0] : 'Validation failed.';
            $fieldCount = count($errors);
            $message = "Validation failed on [{$firstField}]: {$firstMsg}".($fieldCount > 1 ? ' (+'.($fieldCount - 1).' other fields)' : '');

            $context['validation_errors'] = $errors;
            $context['invalid_fields'] = array_keys($errors);
            if ($request) {
                try {
                    $context['input'] = \Illuminate\Support\Arr::except(
                        $request->except(['password', 'password_confirmation', 'secret', 'token', '_token', 'device_secret', 'pin']),
                        ['password', 'token']
                    );
                } catch (Throwable) {}
            }
        } elseif ($e instanceof \App\Exceptions\StaleModelVersionException) {
            $status = 409;
            $severity = 'warning';
            $message = $e->getMessage();
            $context['current_version'] = $e->currentVersion;
            $context['requested_version'] = $request?->input('version');
        } else {
            $message = (string) mb_substr($e->getMessage() !== '' ? $e->getMessage() : get_class($e), 0, 2000);
            if ($e instanceof \Illuminate\Database\QueryException) {
                $context['sql'] = $e->getSql();
            }
        }

        return [
            // `error_type` carries the exception CLASS for server rows — the
            // same column the mobile stream uses for its JS error name, so the
            // admin table and search need no special-casing.
            'error_type' => static::truncate(class_basename($e), 191),
            'message' => $message,
            'severity' => $severity,
            'stack' => static::stackFor($e),
            'file' => static::truncate(ClientErrorLog::relativePath($e->getFile()), 255),
            'line' => $e->getLine() ?: null,
            'http_method' => $request ? static::truncate($request->method(), 10) : null,
            'path' => $request ? static::truncate('/'.ltrim($request->path(), '/'), 255) : null,
            'route_name' => $request?->route()?->getName(),
            'status_code' => $status,
            'request_id' => static::requestId($request),
            // Server rows carry no device/platform facts; `screen` doubles as the
            // human-readable location so the existing list column stays useful.
            'screen' => $request ? static::truncate($request->method().' /'.ltrim($request->path(), '/'), 191) : null,
            'context' => ! empty($context) ? $context : null,
        ];
    }

    /**
     * A trimmed trace: deep enough to debug, bounded so a recursion crash cannot
     * write a megabyte per group. The first line is the throw site, which is
     * what the fingerprint keys on.
     */
    protected static function stackFor(Throwable $e): string
    {
        $head = sprintf(
            '%s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            ClientErrorLog::relativePath($e->getFile()),
            $e->getLine(),
        );

        $trace = $e->getTraceAsString();

        // Previous exceptions carry the real cause (a QueryException wrapping a
        // PDOException, for example) — keep one level.
        $previous = $e->getPrevious();
        if ($previous !== null) {
            $trace .= sprintf(
                "\n\nCaused by: %s: %s in %s:%d",
                get_class($previous),
                $previous->getMessage(),
                ClientErrorLog::relativePath($previous->getFile()),
                $previous->getLine(),
            );
        }

        return (string) mb_substr($head."\n".$trace, 0, 20000);
    }

    /**
     * Attribution. Must never itself throw — resolving the user touches the
     * session/DB, which may be the very thing that is broken.
     */
    protected static function userId(?Request $request): ?string
    {
        try {
            $user = $request?->user();

            return $user ? (string) $user->id : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected static function requestId(?Request $request): ?string
    {
        if (static::$requestId !== null) {
            return static::$requestId;
        }

        try {
            $header = $request?->headers->get('X-Request-Id');
        } catch (Throwable) {
            $header = null;
        }

        return static::$requestId = static::truncate(
            $header !== null && $header !== '' ? $header : (string) Str::uuid(),
            64,
        );
    }

    protected static function currentRequest(): ?Request
    {
        try {
            return app()->bound('request') ? app('request') : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected static function truncate(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : (string) mb_substr($value, 0, $limit);
    }

    /**
     * Test seam: reset the per-request id so a test asserting correlation is not
     * polluted by an earlier request in the same process.
     */
    public static function flushRequestId(): void
    {
        static::$requestId = null;
    }
}
