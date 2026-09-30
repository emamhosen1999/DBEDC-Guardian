<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserDepartmentScopeRequest;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Services\Access\DepartmentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Grant / revoke department scopes (standing admin or time-boxed acting charge).
 *
 * Route middleware requires `department.scopes.manage`; on top of that only a
 * GLOBAL actor who strictly outranks the grantee may list, grant or revoke —
 * a department admin can never widen their own (or a peer's) reach.
 *
 * Every change bumps the grantee's mobile sync epoch so their devices
 * re-bootstrap. Expiry fires no event; that is safe because every scope check
 * (web and mobile) evaluates grant windows at request time.
 */
class UserDepartmentScopeController extends Controller
{
    /** Resolved per call: route-cached controllers outlive the per-request memo. */
    private function scope(): DepartmentScope
    {
        return app(DepartmentScope::class);
    }

    public function index(Request $request, string $id): JsonResponse
    {
        $grantee = $this->authorizedGrantee($request, $id);

        $grants = UserDepartmentScope::query()
            ->with(['department:id,name', 'grantedBy:employee_id,name'])
            ->where('user_id', $grantee->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserDepartmentScope $grant) => $this->present($grant))
            ->values();

        return response()->json([
            'scopes' => $grants,
            'managed_department_ids' => $this->scope()->managedDepartmentIds($grantee),
        ]);
    }

    public function store(StoreUserDepartmentScopeRequest $request, string $id): JsonResponse
    {
        $grantee = $this->authorizedGrantee($request, $id);
        $data = $request->validated();

        // Re-granting the same department + type renews the existing grant.
        $grant = UserDepartmentScope::updateOrCreate(
            [
                'user_id' => $grantee->getKey(),
                'department_id' => (int) $data['department_id'],
                'scope_type' => $data['scope_type'],
            ],
            [
                'starts_at' => $data['starts_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'reason' => $data['reason'] ?? null,
                'granted_by' => $request->user()->getKey(),
            ],
        );

        $this->afterChange($grantee);

        Log::info('Department scope granted', [
            'grant_id' => $grant->id,
            'user_id' => $grantee->getKey(),
            'department_id' => $grant->department_id,
            'scope_type' => $grant->scope_type,
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'granted_by' => $request->user()->getKey(),
        ]);

        return response()->json([
            'message' => 'Department scope granted.',
            'scope' => $this->present($grant->load(['department:id,name', 'grantedBy:employee_id,name'])),
        ], $grant->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $id, int $scopeId): JsonResponse
    {
        $grantee = $this->authorizedGrantee($request, $id);

        $grant = UserDepartmentScope::query()
            ->where('user_id', $grantee->getKey())
            ->findOrFail($scopeId);

        $grant->delete();
        $this->afterChange($grantee);

        Log::info('Department scope revoked', [
            'grant_id' => $scopeId,
            'user_id' => $grantee->getKey(),
            'department_id' => $grant->department_id,
            'scope_type' => $grant->scope_type,
            'revoked_by' => $request->user()->getKey(),
        ]);

        return response()->json(['message' => 'Department scope revoked.']);
    }

    private function authorizedGrantee(Request $request, string $id): User
    {
        $grantee = User::findOrFail($id);
        $actor = $request->user();

        if (! $this->scope()->isGlobal($actor) || ! $this->scope()->outranks($actor, $grantee)) {
            abort(403, 'Only a company-wide administrator who outranks this user may manage their department scope.');
        }

        return $grantee;
    }

    private function afterChange(User $grantee): void
    {
        $this->scope()->forget($grantee);

        try {
            $grantee->bumpSyncEpoch();
        } catch (\Throwable $exception) {
            // A sync side-effect must never undo the grant change that triggered it.
            report($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UserDepartmentScope $grant): array
    {
        return [
            'id' => $grant->id,
            'department_id' => $grant->department_id,
            'department_name' => $grant->department?->name,
            'scope_type' => $grant->scope_type,
            'starts_at' => $grant->starts_at?->toIso8601String(),
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'reason' => $grant->reason,
            'status' => $grant->status(),
            'granted_by' => $grant->grantedBy ? [
                'id' => (string) $grant->grantedBy->getKey(),
                'name' => $grant->grantedBy->name,
            ] : null,
            'created_at' => $grant->created_at?->toIso8601String(),
        ];
    }
}
