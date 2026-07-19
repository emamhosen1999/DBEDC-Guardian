<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreClientErrorsRequest;
use App\Models\ClientErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Mobile crash / error telemetry ingest ("Client Diagnostics").
 *
 * Contract — POST /api/v1/client-errors
 *   { "events": [ { message, error_type, severity, stack, screen, platform,
 *                   os_version, model, app_version, build, device_id,
 *                   session_id, breadcrumbs, context, occurred_at } ] }
 *
 * Design rules this endpoint lives by:
 *
 *  1. PUBLIC + THROTTLED, not sanctum-gated. The authenticated v1 group also
 *     runs ApiDeviceAuthMiddleware, which can itself reject a request — and a
 *     crash reporter that only works while auth is healthy is useless for the
 *     exact class of bugs (login failures, token refresh loops, cold-start
 *     crashes) you most want to see. A bearer token is USED when present
 *     (attributing the group to a user) and simply absent otherwise.
 *
 *  2. NEVER 500 on bad input. A malformed event is skipped and counted, not
 *     fatal. The mobile client flushes a persisted offline queue; if one
 *     poisoned row could fail the batch, the client would retry it forever and
 *     never drain. The response tells the client how many landed so it can
 *     clear its queue with confidence.
 *
 *  3. Fingerprinting is SERVER-side (see ClientErrorLog::fingerprintFor).
 */
class ClientErrorLogController extends Controller
{
    /**
     * Ingest a batch of client error events.
     */
    public function store(StoreClientErrorsRequest $request): JsonResponse
    {
        $events = $request->validated()['events'] ?? [];

        // Resolve the user from a bearer token when one is present and valid.
        // The guard MUST be named explicitly: this route is public, so the
        // default guard is `web` (session) and would silently ignore the
        // Authorization header, leaving every group unattributed.
        // Pre-login crashes carry only a device_id and are still accepted.
        $userId = $this->resolveUserId($request);

        $accepted = 0;
        $skipped = 0;
        $fingerprints = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                $skipped++;

                continue;
            }

            $validator = Validator::make($event, StoreClientErrorsRequest::eventRules());

            if ($validator->fails()) {
                $skipped++;

                continue;
            }

            try {
                $group = ClientErrorLog::record($validator->validated(), $userId);
                $accepted++;
                $fingerprints[] = $group->fingerprint;
            } catch (Throwable $e) {
                // Telemetry must never take the app down or wedge the queue.
                // Swallow, count, and leave a server-side breadcrumb.
                $skipped++;
                Log::warning('client-error ingest: event dropped', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'accepted' => $accepted,
            'skipped' => $skipped,
            'fingerprints' => array_values(array_unique($fingerprints)),
        ]);
    }

    /**
     * Best-effort user attribution from an optional bearer token.
     *
     * An expired or forged token must NOT fail the request — the crash report is
     * still worth keeping, just anonymously.
     */
    protected function resolveUserId(StoreClientErrorsRequest $request): ?int
    {
        try {
            $user = $request->user('sanctum') ?? $request->user();

            return $user?->id;
        } catch (Throwable) {
            return null;
        }
    }
}
