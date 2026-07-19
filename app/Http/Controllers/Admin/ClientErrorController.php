<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientErrorLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Admin surface for mobile client crash telemetry ("Client Diagnostics").
 *
 * Reads the FINGERPRINT GROUPS written by the v1 ingest endpoint: one row per
 * distinct bug, carrying an occurrence counter and blast-radius aggregates, so
 * this list is a triage queue (bugs) rather than a firehose (occurrences).
 *
 * Authorization mirrors admin device sessions exactly:
 *   users.view   -> read the list / detail
 *   users.update -> mark resolved / reopen
 * enforced by route middleware in routes/web.php.
 */
class ClientErrorController extends Controller
{
    /**
     * Grouped, filtered, paginated triage list.
     */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $filters = $this->filters($request);

        $query = ClientErrorLog::query()->with('resolver:id,name');

        $this->applyFilters($query, $filters);

        $errors = $query
            // Unresolved first, then most recently seen: the triage order.
            ->orderByRaw('CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        $rows = collect($errors->items())
            ->map(fn (ClientErrorLog $error) => $this->summaryRow($error))
            ->values();

        $payload = [
            'errors' => $rows,
            'pagination' => [
                'current_page' => $errors->currentPage(),
                'last_page' => $errors->lastPage(),
                'per_page' => $errors->perPage(),
                'total' => $errors->total(),
                'from' => $errors->firstItem(),
                'to' => $errors->lastItem(),
            ],
            'filters' => $filters,
            'options' => $this->filterOptions(),
            'summary' => $this->summary(),
            'can' => [
                'resolve' => (bool) $request->user()?->can('users.update'),
            ],
        ];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['success' => true] + $payload);
        }

        return Inertia::render('Admin/ClientErrors', $payload);
    }

    /**
     * Full detail for one group: complete stack, breadcrumbs, device/version/user.
     *
     * JSON only — the list page opens it in a drawer, so there is no separate
     * Inertia page to keep in sync.
     */
    public function show(Request $request, int $error): JsonResponse
    {
        /** @var ClientErrorLog $group */
        $group = ClientErrorLog::with(['user:id,name,email', 'resolver:id,name'])->findOrFail($error);

        $affectedUsers = User::query()
            ->select('id', 'name', 'email')
            ->whereIn('id', array_slice($group->affected_users ?? [], 0, 100))
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'error' => $this->summaryRow($group) + [
                'stack' => $group->stack,
                'breadcrumbs' => $this->normalizeBreadcrumbs($group->breadcrumbs),
                'context' => $group->context,
                'session_id' => $group->session_id,
                'device_id' => $group->device_id,
                'os_version' => $group->os_version,
                'device_model' => $group->device_model,
                'build' => $group->build,
                'affected_device_ids' => array_slice($group->affected_devices ?? [], 0, 100),
                'affected_user_list' => $affectedUsers,
                'latest_user' => $group->user ? [
                    'id' => $group->user->id,
                    'name' => $group->user->name,
                    'email' => $group->user->email,
                ] : null,
            ],
        ]);
    }

    /**
     * Mark a group resolved, or reopen it.
     *
     * Note the ingest side deliberately REOPENS a resolved group when the same
     * fingerprint reappears, so "resolved" means "fixed as of the last sighting",
     * never "silenced".
     */
    public function resolve(Request $request, int $error): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'resolved' => ['nullable', 'boolean'],
        ]);

        $resolved = (bool) ($validated['resolved'] ?? true);

        /** @var ClientErrorLog $group */
        $group = ClientErrorLog::findOrFail($error);

        $group->forceFill([
            'resolved_at' => $resolved ? Carbon::now() : null,
            'resolved_by' => $resolved ? $request->user()?->id : null,
        ])->save();

        $message = $resolved
            ? 'Error marked resolved.'
            : 'Error reopened.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'resolved' => $resolved,
            ]);
        }

        return back()->with('success', $message);
    }

    /* ─────────────────────────────── filters ─────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        $status = (string) $request->input('status', 'unresolved');
        $severity = (string) $request->input('severity', 'all');

        return [
            'search' => trim((string) $request->input('search', '')),
            'status' => in_array($status, ['all', 'resolved', 'unresolved'], true) ? $status : 'unresolved',
            'severity' => in_array($severity, ClientErrorLog::SEVERITIES, true) ? $severity : 'all',
            'platform' => trim((string) $request->input('platform', '')),
            'app_version' => trim((string) $request->input('app_version', '')),
            'screen' => trim((string) $request->input('screen', '')),
            'from' => trim((string) $request->input('from', '')),
            'to' => trim((string) $request->input('to', '')),
            'per_page' => min(max((int) $request->input('per_page', 15), 5), 100),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): void
    {
        if ($filters['status'] === 'resolved') {
            $query->whereNotNull('resolved_at');
        } elseif ($filters['status'] === 'unresolved') {
            $query->whereNull('resolved_at');
        }

        if ($filters['severity'] !== 'all') {
            $query->where('severity', $filters['severity']);
        }

        if ($filters['platform'] !== '') {
            $query->where('platform', $filters['platform']);
        }

        if ($filters['app_version'] !== '') {
            $query->where('app_version', $filters['app_version']);
        }

        if ($filters['screen'] !== '') {
            $query->where('screen', 'like', '%'.$filters['screen'].'%');
        }

        // Date window filters on LAST SEEN — "what is burning now", not
        // "what was first discovered then".
        if ($filters['from'] !== '' && ($from = $this->parseDate($filters['from'])) !== null) {
            $query->where('last_seen_at', '>=', $from->startOfDay());
        }

        if ($filters['to'] !== '' && ($to = $this->parseDate($filters['to'])) !== null) {
            $query->where('last_seen_at', '<=', $to->endOfDay());
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($outer) use ($search) {
                $outer->where('message', 'like', "%{$search}%")
                    ->orWhere('error_type', 'like', "%{$search}%")
                    ->orWhere('screen', 'like', "%{$search}%")
                    ->orWhere('device_id', 'like', "%{$search}%")
                    ->orWhere('fingerprint', 'like', "%{$search}%");
            });
        }
    }

    protected function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /* ─────────────────────────────── shaping ─────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    protected function summaryRow(ClientErrorLog $error): array
    {
        return [
            'id' => $error->id,
            'fingerprint' => $error->fingerprint,
            'short_fingerprint' => substr((string) $error->fingerprint, 0, 8),
            'message' => $error->message,
            'error_type' => $error->error_type,
            'severity' => $error->severity,
            'screen' => $error->screen,
            'platform' => $error->platform,
            'app_version' => $error->app_version,
            'count' => (int) $error->count,
            'affected_devices' => $error->affectedDeviceCount(),
            'affected_users' => $error->affectedUserCount(),
            'platform_counts' => $error->platform_counts ?? [],
            'first_seen_at' => optional($error->received_at)->toIso8601String(),
            'last_seen_at' => optional($error->last_seen_at)->toIso8601String(),
            'occurred_at' => optional($error->occurred_at)->toIso8601String(),
            'resolved_at' => optional($error->resolved_at)->toIso8601String(),
            'is_resolved' => $error->isResolved(),
            'resolved_by' => $error->resolver?->name,
        ];
    }

    /**
     * Breadcrumbs are free-form client JSON. Normalize defensively so the UI can
     * render a consistent timeline without trusting the client's shape.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeBreadcrumbs(mixed $breadcrumbs): array
    {
        if (! is_array($breadcrumbs)) {
            return [];
        }

        return collect($breadcrumbs)
            ->map(function ($crumb) {
                if (! is_array($crumb)) {
                    return ['message' => (string) (is_scalar($crumb) ? $crumb : ''), 'type' => null, 'at' => null];
                }

                return [
                    'message' => (string) ($crumb['message'] ?? $crumb['msg'] ?? ''),
                    'type' => isset($crumb['type']) ? (string) $crumb['type'] : (isset($crumb['category']) ? (string) $crumb['category'] : null),
                    'at' => isset($crumb['at']) ? (string) $crumb['at'] : (isset($crumb['timestamp']) ? (string) $crumb['timestamp'] : null),
                    'data' => is_array($crumb['data'] ?? null) ? $crumb['data'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Distinct values for the filter dropdowns.
     *
     * @return array<string, array<int, string>>
     */
    protected function filterOptions(): array
    {
        return [
            'severities' => ClientErrorLog::SEVERITIES,
            'platforms' => ClientErrorLog::query()
                ->whereNotNull('platform')
                ->distinct()
                ->orderBy('platform')
                ->pluck('platform')
                ->filter()
                ->values()
                ->all(),
            'app_versions' => ClientErrorLog::query()
                ->whereNotNull('app_version')
                ->distinct()
                ->orderByDesc('app_version')
                ->limit(50)
                ->pluck('app_version')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * Header counters. Deliberately UNFILTERED — the fleet's true state, so the
     * header does not silently change meaning as filters are applied.
     *
     * @return array<string, int>
     */
    protected function summary(): array
    {
        $last24h = Carbon::now()->subDay();

        return [
            'total_groups' => ClientErrorLog::query()->count(),
            'unresolved' => ClientErrorLog::query()->whereNull('resolved_at')->count(),
            'fatal_unresolved' => ClientErrorLog::query()
                ->whereNull('resolved_at')
                ->where('severity', 'fatal')
                ->count(),
            'total_occurrences' => (int) ClientErrorLog::query()->sum('count'),
            'groups_last_24h' => ClientErrorLog::query()
                ->where('last_seen_at', '>=', $last24h)
                ->count(),
            'occurrences_last_24h' => (int) ClientErrorLog::query()
                ->where('last_seen_at', '>=', $last24h)
                ->sum('count'),
        ];
    }
}
