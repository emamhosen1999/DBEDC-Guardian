<?php

namespace App\Http\Requests\Api;

use App\Models\ClientErrorLog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Batch envelope validation for POST /api/v1/client-errors.
 *
 * Deliberately validates only the ENVELOPE strictly (events must be a non-empty
 * array of at most MAX_BATCH objects). Individual events are validated
 * PER-EVENT inside the controller so that one malformed event is SKIPPED rather
 * than 422-ing the whole batch — a mobile client flushing an offline queue must
 * never be permanently wedged by a single poisoned row it cannot drop.
 */
class StoreClientErrorsRequest extends FormRequest
{
    /** Hard cap on events accepted per request. */
    public const MAX_BATCH = 50;

    public function authorize(): bool
    {
        // Intentionally open: the route is reachable pre-login (crashes on the
        // login screen are exactly the ones worth catching). Abuse is bounded by
        // the route throttle + batch cap, not by auth.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'events.*' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.max' => 'A batch may contain at most '.self::MAX_BATCH.' events.',
        ];
    }

    /**
     * Per-event rules, applied one event at a time by the controller.
     *
     * Every field except `message` is optional: telemetry is best-effort and a
     * crash report missing its screen name is still worth keeping. Lengths are
     * capped so a runaway client cannot post megabytes into an indexed column.
     *
     * @return array<string, mixed>
     */
    public static function eventRules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'error_type' => ['nullable', 'string', 'max:191'],
            'severity' => ['nullable', 'string', 'in:'.implode(',', ClientErrorLog::SEVERITIES)],
            'stack' => ['nullable', 'string', 'max:20000'],
            'screen' => ['nullable', 'string', 'max:191'],
            'platform' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:191'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'build' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'session_id' => ['nullable', 'string', 'max:191'],
            'breadcrumbs' => ['nullable', 'array', 'max:200'],
            'context' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'string', 'max:64'],
        ];
    }
}
