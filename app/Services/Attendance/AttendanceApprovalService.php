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
        $chain = [];
        $managerId = $requester->report_to ?? $requester->report_to_id ?? null;

        if ($managerId) {
            $chain[] = $this->entry(1, $managerId, optional($requester->reportsTo)->name ?? User::find($managerId)?->name ?? 'Manager');
        }

        if ($requester->department_id) {
            $head = User::where('department_id', $requester->department_id)
                ->where('id', '!=', $requester->id)
                ->when($managerId, fn ($q) => $q->where('id', '!=', $managerId))
                ->whereNotNull('designation_id')
                ->orderBy('designation_id')
                ->first();
            if ($head) {
                $chain[] = $this->entry(2, $head->id, $head->name);
            }
        }

        if ($escalate) {
            $hr = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['HR Manager', 'HR Head', 'Super Administrator']))
                ->where('id', '!=', $requester->id)
                ->first();
            if ($hr && ! collect($chain)->pluck('approver_id')->contains($hr->id)) {
                $chain[] = $this->entry(count($chain) + 1, $hr->id, $hr->name);
            }
        }

        return $chain;
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
}
