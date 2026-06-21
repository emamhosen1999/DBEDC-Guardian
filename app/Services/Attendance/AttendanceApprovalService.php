<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceApprovalService
{
    public function buildChain(User $requester, bool $escalate = false): array
    {
        // Single, REAL approver: the requester's direct manager. These requests
        // (regularization / overtime) affect only the requester, so one authorization
        // is enough. We deliberately do NOT add a second "department head" level — the
        // old heuristic (first colleague by designation_id) picked an arbitrary employee
        // with no authority and no UI access, which stranded every request.
        $managerId = $requester->report_to ?? $requester->report_to_id ?? null;
        if ($managerId) {
            $name = optional($requester->reportsTo)->name ?? User::find($managerId)?->name ?? 'Manager';

            return [$this->entry(1, $managerId, $name)];
        }

        // No manager on record → route to HR / admin so the request is still actionable
        // (instead of silently auto-approving with an empty chain).
        $hr = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['HR Manager', 'HR Head', 'Super Administrator']))
            ->where('id', '!=', $requester->id)
            ->first();

        return $hr ? [$this->entry(1, $hr->id, $hr->name)] : [];
    }

    private function entry(int $level, int $approverId, string $name): array
    {
        return ['level' => $level, 'approver_id' => $approverId, 'approver_name' => $name, 'status' => 'pending', 'approved_at' => null, 'comments' => null];
    }

    public function submit(Model $m, bool $escalate = false): void
    {
        $requester = User::find($m->user_id);
        $chain = $requester ? $this->buildChain($requester, $escalate) : [];

        if (empty($chain)) {
            $m->update(['status' => 'approved', 'current_approval_level' => 0, 'approval_chain' => [], 'approved_at' => now()]);

            return;
        }

        $m->update(['approval_chain' => $chain, 'current_approval_level' => 1, 'status' => 'pending']);
    }

    public function canApprove(Model $m, User $u): bool
    {
        if ($m->status !== 'pending') {
            return false;
        }
        foreach ($m->approval_chain ?? [] as $lvl) {
            if ($lvl['level'] === $m->current_approval_level && $lvl['approver_id'] === $u->id && $lvl['status'] === 'pending') {
                return true;
            }
        }

        return false;
    }

    public function approve(Model $m, User $approver, ?string $comments = null): array
    {
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
