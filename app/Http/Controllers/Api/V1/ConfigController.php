<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/config — the mobile app's runtime remote config.
 *
 * The app is a store binary; native behaviour cannot be changed without a
 * release. This endpoint is the escape hatch: the client fetches it after login
 * and on every foreground, and adapts without a new build.
 *
 * Cheap re-check: the response carries an ETag. A client that sends
 * `If-None-Match: "<etag>"` and is already current gets a bodiless 304, so the
 * foreground poll costs almost nothing on metered rural connections.
 */
class ConfigController extends Controller
{
    use ApiResponse;

    public function __construct(protected FeatureFlagService $flags) {}

    public function show(Request $request): JsonResponse|Response
    {
        $payload = $this->flags->payloadFor($request->user());

        $etag = '"'.$payload['etag'].'"';

        if ($this->matchesIfNoneMatch($request, $payload['etag'])) {
            return response()->noContent(304)->setEtag($payload['etag']);
        }

        return $this->successResponse([
            'flags' => (object) $payload['flags'],
            'updated_at' => $payload['updated_at'],
            'etag' => $payload['etag'],
            'server_time' => now()->toAtomString(),
        ])->header('ETag', $etag)->header('Cache-Control', 'private, max-age=0, must-revalidate');
    }

    /**
     * Tolerant If-None-Match match: accepts quoted, unquoted and weak forms,
     * plus a comma-separated list, because RN/OkHttp and fetch differ here.
     */
    protected function matchesIfNoneMatch(Request $request, string $etag): bool
    {
        $header = trim((string) $request->header('If-None-Match', ''));

        if ($header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $normalized = trim($candidate);
            $normalized = preg_replace('/^W\//i', '', $normalized) ?? $normalized;
            $normalized = trim(trim($normalized), '"');

            if ($normalized === $etag || $normalized === '*') {
                return true;
            }
        }

        return false;
    }
}
