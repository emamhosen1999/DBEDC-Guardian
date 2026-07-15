<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Guarantees that a human-facing punch request can never assert its own punch
 * moment or claim a trusted device source.
 *
 * AttendancePunchService::resolvePunchTime() honours a client-supplied
 * `punch_time` when `source` is `biometric`/`device`, because the biometric
 * webhook and the offline-sync replay legitimately carry the real moment of a
 * punch that was captured earlier. Those paths build synthetic plain Requests,
 * NOT these FormRequests. A phone or browser hitting the punch endpoint directly
 * must therefore never be able to send those fields — otherwise any authenticated
 * user could back-date a punch and defeat late / overtime / worked-minutes math.
 *
 * This strips both fields before validation so the service falls back to the
 * authoritative server clock, and logs any non-empty attempt as a tamper signal.
 */
trait GuardsServerAuthoritativePunchTime
{
    protected function stripDeviceTrustedPunchFields(): void
    {
        $attempted = [];

        foreach (['source', 'punch_time'] as $field) {
            $value = $this->input($field);

            if ($value !== null && $value !== '') {
                $attempted[$field] = is_scalar($value) ? (string) $value : gettype($value);
            }
        }

        if ($attempted !== []) {
            Log::warning('Stripped client-supplied device-trusted fields from a human punch request.', [
                'user_id' => optional($this->user())->id,
                'ip' => $this->ip(),
                'route' => optional($this->route())->getName(),
                'attempted' => $attempted,
            ]);
        }

        // Force the server clock: null values are falsy in resolvePunchTime(), so
        // the punch records at server "now". merge() writes to the active input
        // source (the JSON body for these requests), so input() reads null back.
        $this->merge([
            'source' => null,
            'punch_time' => null,
        ]);
    }
}
