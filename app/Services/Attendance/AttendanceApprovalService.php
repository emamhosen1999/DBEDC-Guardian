<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Access\SelfAdministration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceApprovalService
{
    /** Roles allowed to act on a request that has no approver chain. */
    private const GLOBAL_APPROVER_ROLES = ['Super Administrator', 'Administrator', 'HR Manager'];

    public function buildChain(User $requester, bool $escalate = false): array
    {
        // Single, REAL approver: the requester's direct manager. These requests
        // (regularization / overtime) affect only the requester, so one authorization
        // is enough. We deliberately do NOT add a second "department head" level — the
        // old heuristic (first colleague by designation_id) picked an arbitrary employee
        // with no authority and no UI access, which stranded every request.
        $managerId = $requester->report_to ?? $requester->report_to_id ?? null;
        // find() honours SoftDeletes: a manager who was offboarded (or a dangling
        // report_to) must not become a ghost approver nobody can act as.
        $manager = $managerId ? User::find($managerId) : null;
        if ($manager) {
            return [$this->entry(1, $manager->employee_id, $manager->name ?? 'Manager')];
        }

        // No (live) manager on record → route to HR / admin so the request is still actionable.
        $hr = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['HR Manager', 'HR Head', 'Super Administrator']))
            ->where('employee_id', '!=', $requester->employee_id)
            ->first();

        return $hr ? [$this->entry(1, $hr->employee_id, $hr->name)] : [];
    }

    private function entry(int $level, string $approverId, string $name): array
    {
        return ['level' => $level, 'approver_id' => $approverId, 'approver_name' => $name, 'status' => 'pending', 'approved_at' => null, 'comments' => null];
    }

    public function submit(Model $m, bool $escalate = false): void
    {
        $requester = User::find($m->user_id);
        $chain = $requester ? $this->buildChain($requester, $escalate) : [];

        // Never auto-approve: with no approver at all the request stays pending with an
        // empty chain, and canApprove() lets a global-role user (never the requester) act.
        $m->update(['approval_chain' => $chain, 'current_approval_level' => empty($chain) ? 0 : 1, 'status' => 'pending']);
    }

    public function canApprove(Model $m, User $u): bool
    {
        if ($m->status !== 'pending') {
            return false;
        }
        // Nobody approves their own OT / regularization request - except the governed exception
        // (access.self-administration; every use is logged and announced to the global admins).
        if ((string) $m->user_id === (string) $u->employee_id) {
            return app(SelfAdministration::class)->allows($u, $u);
        }
        if (empty($m->approval_chain)) {
            return $u->hasAnyRole(self::GLOBAL_APPROVER_ROLES) || $this->scopeApproves($m, $u);
        }
        foreach ($m->approval_chain ?? [] as $lvl) {
            if ($lvl['level'] === $m->current_approval_level && $lvl['approver_id'] === $u->id && $lvl['status'] === 'pending') {
                return true;
            }
        }

        return $this->scopeApproves($m, $u);
    }

    /**
     * A department admin / manager may also decide requests of employees in their
     * DepartmentScope (managed departments + reporting subtree) when they are not the
     * named approver — but only for targets they outrank, never their own request.
     * Global actors are unaffected: they keep acting through the chain / empty-chain rule.
     */
    private function scopeApproves(Model $m, User $u): bool
    {
        $scope = app(DepartmentScope::class);

        return ! $scope->isGlobal($u) && $scope->canManage($u, (string) $m->user_id);
    }

    public function approve(Model $m, User $approver, ?string $comments = null): array
    {
        $result = $this->performApprove($m, $approver, $comments);
        $this->auditSelf($m, $approver, 'approved', $result);

        return $result;
    }

    /** Log + announce a decided request of one's own (only reachable with access.self-administration). */
    private function auditSelf(Model $m, User $actor, string $what, array $result): void
    {
        if ((string) $m->user_id === (string) $actor->employee_id && ($result['success'] ?? false)) {
            app(SelfAdministration::class)->record($actor, class_basename($m).'.'.$what, "{$what} his own ".strtolower(class_basename($m)).' request', class_basename($m), $m->getKey(), ['status' => ['pending', $what]]);
        }
    }

    private function performApprove(Model $m, User $approver, ?string $comments = null): array
    {
        if ((string) $m->user_id === (string) $approver->employee_id && ! app(SelfAdministration::class)->allows($approver, $approver)) {
            return ['success' => false, 'message' => 'You cannot approve or reject your own request.', 'status' => $m->status];
        }
        if (! $this->canApprove($m, $approver)) {
            return ['success' => false, 'message' => 'Not authorized to approve.', 'status' => $m->status];
        }

        return DB::transaction(function () use ($m, $approver, $comments) {
            $chain = $m->approval_chain;
            foreach ($chain as &$lvl) {
                if ($lvl['level'] === $m->current_approval_level && $lvl['approver_id'] === $approver->id) {
                    $lvl['status'] = 'approved';
                    $lvl['approved_at'] = now()->toDateTimeString();
                    $lvl['comments'] = $comments;
                    break;
                }
            }
            unset($lvl);

            $more = collect($chain)->firstWhere('level', $m->current_approval_level + 1);
            if ($more) {
                $m->update(['approval_chain' => $chain, 'current_approval_level' => $m->current_approval_level + 1]);

                return ['success' => true, 'message' => 'Approved; forwarded to next level.', 'status' => 'pending'];
            }

            $m->update(['approval_chain' => $chain, 'status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);

            return ['success' => true, 'message' => 'Approved.', 'status' => 'approved'];
        });
    }

    public function reject(Model $m, User $approver, string $reason): array
    {
        $result = $this->performReject($m, $approver, $reason);
        $this->auditSelf($m, $approver, 'rejected', $result);

        return $result;
    }

    private function performReject(Model $m, User $approver, string $reason): array
    {
        if ((string) $m->user_id === (string) $approver->employee_id && ! app(SelfAdministration::class)->allows($approver, $approver)) {
            return ['success' => false, 'message' => 'You cannot approve or reject your own request.', 'status' => $m->status];
        }
        if (! $this->canApprove($m, $approver)) {
            return ['success' => false, 'message' => 'Not authorized to reject.', 'status' => $m->status];
        }

        return DB::transaction(function () use ($m, $approver, $reason) {
            $chain = $m->approval_chain;
            foreach ($chain as &$lvl) {
                if ($lvl['level'] === $m->current_approval_level && $lvl['approver_id'] === $approver->id) {
                    $lvl['status'] = 'rejected';
                    $lvl['approved_at'] = now()->toDateTimeString();
                    $lvl['comments'] = $reason;
                    break;
                }
            }
            unset($lvl);
            $m->update(['approval_chain' => $chain, 'status' => 'rejected', 'approved_by' => $approver->id]);

            return ['success' => true, 'message' => 'Rejected.', 'status' => 'rejected'];
        });
    }

    public function pendingFor(User $u, string $modelClass): Collection
    {
        return $modelClass::where('status', 'pending')->whereNotNull('approval_chain')->get()
            ->filter(fn ($m) => $this->canApprove($m, $u))->values();
    }

    /**
     * Requests this user can see in their approvals view, filtered by status.
     * - 'pending'  → exactly pendingFor() (current actionable items).
     * - other/'all' → requests of that status where this user appears anywhere in the
     *   approval_chain (i.e. requests they are/were an approver on) — for history review.
     */
    public function forApprover(User $u, string $modelClass, string $status = 'pending'): Collection
    {
        if ($status === 'pending') {
            return $this->pendingFor($u, $modelClass);
        }

        $query = $modelClass::whereNotNull('approval_chain');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return $query->get()
            ->filter(fn ($m) => collect($m->approval_chain ?? [])
                ->contains(fn ($lvl) => ($lvl['approver_id'] ?? null) === $u->id))
            ->values();
    }
}
