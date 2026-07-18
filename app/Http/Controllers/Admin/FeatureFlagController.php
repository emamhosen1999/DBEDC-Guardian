<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Role;

/**
 * Admin surface for server-controlled flags / remote config.
 *
 * Authorisation MIRRORS admin device sessions: read behind `users.view`, write
 * behind `users.update` (wired in routes/web.php). Flipping a flag can disable
 * a fleet-wide capability, so it sits at the same trust level as revoking a
 * user's session — not a new, looser gate.
 */
class FeatureFlagController extends Controller
{
    public function __construct(protected FeatureFlagService $flags) {}

    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $flags = FeatureFlag::query()
            ->orderBy('key')
            ->orderByRaw('CASE WHEN role IS NULL THEN 0 ELSE 1 END')
            ->orderBy('role')
            ->get()
            ->map(fn (FeatureFlag $flag) => $this->present($flag))
            ->values();

        $payload = [
            'flags' => $flags,
            'roles' => Role::query()->orderBy('name')->pluck('name')->values(),
            'summary' => [
                'total' => $flags->count(),
                'enabled' => $flags->where('is_enabled', true)->count(),
                'disabled' => $flags->where('is_enabled', false)->count(),
                'role_scoped' => $flags->whereNotNull('role')->count(),
            ],
        ];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['success' => true] + $payload);
        }

        return Inertia::render('Admin/FeatureFlags', $payload);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);

        $this->assertScopeIsFree($data['key'], $data['role'], null);

        $flag = FeatureFlag::create($data);

        return $this->respond($request, $flag, sprintf('Flag "%s" created.', $flag->key));
    }

    public function update(Request $request, int $flag): JsonResponse|RedirectResponse
    {
        /** @var FeatureFlag $model */
        $model = FeatureFlag::findOrFail($flag);

        $data = $this->validated($request);

        $this->assertScopeIsFree($data['key'], $data['role'], $model->id);

        $model->fill($data)->save();

        return $this->respond($request, $model, sprintf('Flag "%s" updated.', $model->key));
    }

    /**
     * One-click on/off — the action an operator actually reaches for during an
     * incident, without opening the full editor.
     */
    public function toggle(Request $request, int $flag): JsonResponse|RedirectResponse
    {
        /** @var FeatureFlag $model */
        $model = FeatureFlag::findOrFail($flag);

        $model->forceFill(['is_enabled' => ! $model->is_enabled])->save();

        return $this->respond(
            $request,
            $model,
            sprintf('Flag "%s" %s.', $model->key, $model->is_enabled ? 'enabled' : 'disabled'),
        );
    }

    public function destroy(Request $request, int $flag): JsonResponse|RedirectResponse
    {
        /** @var FeatureFlag $model */
        $model = FeatureFlag::findOrFail($flag);
        $key = $model->key;

        $model->delete();

        $this->flags->forgetMemo();

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => sprintf('Flag "%s" deleted.', $key)]);
        }

        return back()->with('success', sprintf('Flag "%s" deleted.', $key));
    }

    /**
     * @return array{key: string, value: mixed, description: string|null, is_enabled: bool, role: string|null}
     */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+([._-][a-z0-9]+)*$/'],
            // Sent as a JSON *string* from the editor so any shape (scalar,
            // array, object, null) survives an HTML form field.
            'value' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['required', 'boolean'],
            'role' => ['nullable', 'string', 'max:191', Rule::exists('roles', 'name')],
        ], [
            'key.regex' => 'Use a dotted lower-case key, e.g. mobile.offline_sync_push_enabled.',
        ]);

        return [
            'key' => $validated['key'],
            'value' => $this->decodeValueInput($validated['value'] ?? null),
            'description' => $validated['description'] ?? null,
            'is_enabled' => (bool) $validated['is_enabled'],
            'role' => ($validated['role'] ?? null) ?: null,
        ];
    }

    /**
     * The editor posts raw JSON text. Invalid JSON is a validation error rather
     * than a silently stored string, so an operator cannot ship `treu` to the
     * fleet and wonder why nothing changed.
     */
    protected function decodeValueInput(?string $raw): mixed
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages([
                'value' => 'Value must be valid JSON (e.g. true, 300, "text", {"a":1}).',
            ]);
        }

        return $decoded;
    }

    /**
     * (key, role) must be unique. Enforced here because NULL never collides in
     * a SQL unique index — two global rows for one key would otherwise be legal
     * and the overlay order would decide the winner arbitrarily.
     */
    protected function assertScopeIsFree(string $key, ?string $role, ?int $ignoreId): void
    {
        $exists = FeatureFlag::query()
            ->where('key', $key)
            ->when($role === null, fn ($q) => $q->whereNull('role'), fn ($q) => $q->where('role', $role))
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => $role === null
                    ? sprintf('A global row for "%s" already exists.', $key)
                    : sprintf('A row for "%s" scoped to %s already exists.', $key, $role),
            ]);
        }
    }

    protected function respond(Request $request, FeatureFlag $flag, string $message): JsonResponse|RedirectResponse
    {
        // The resolver memoises within a request; drop it so a follow-up read in
        // the same request cycle sees the write.
        $this->flags->forgetMemo();

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'flag' => $this->present($flag),
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(FeatureFlag $flag): array
    {
        return [
            'id' => $flag->id,
            'key' => $flag->key,
            'value' => $flag->value,
            // Pre-rendered for the editor textarea.
            'value_json' => $flag->value === null ? '' : json_encode($flag->value, JSON_UNESCAPED_SLASHES),
            'description' => $flag->description,
            'is_enabled' => (bool) $flag->is_enabled,
            'role' => $flag->role,
            'updated_at' => optional($flag->updated_at)->toIso8601String(),
        ];
    }
}
