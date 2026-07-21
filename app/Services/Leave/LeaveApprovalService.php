<?php

namespace App\Services\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Notifications\LeaveApprovalNotification;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRejectedNotification;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveApprovalService
{
    /**
     * Build approval chain for a leave request based on organizational hierarchy
     */
    public function buildApprovalChain(Leave $leave): array
    {
        $user = $leave->employee;

        if (! $user) {
            return [];
        }

        $leaveSetting = $leave->leaveSetting;

        // Check if approval is required for this leave type
        if (! $leaveSetting || ! $leaveSetting->requires_approval) {
            return [];
        }

        // Check if auto-approval is enabled for this leave type
        if ($leaveSetting->auto_approve) {
            return [];
        }

        $approvalChain = [];

        // Level 1: Direct Manager (report_to)
        $directManagerId = $user->report_to ?? $user->report_to_id ?? null;

        if ($directManagerId) {
            $approvalChain[] = [
                'level' => 1,
                'approver_id' => $directManagerId,
                'approver_name' => $user->reportsTo->name ?? 'Unknown',
                'status' => 'pending',
                'approved_at' => null,
                'comments' => null,
            ];
        }

        // Level 2: Department Head (if different from direct manager).
        // Head = a user in the same department holding a ROOT designation
        // (designations are hierarchical via parent_id; root = top of the tree).
        $departmentHead = $user->department_id
            ? User::where('department_id', $user->department_id)
                ->where('id', '!=', $user->id)
                ->when($directManagerId, function ($query) use ($directManagerId) {
                    $query->where('id', '!=', $directManagerId);
                })
                ->whereIn('designation_id', function ($query) use ($user) {
                    $query->select('id')
                        ->from('designations')
                        ->where('department_id', $user->department_id)
                        ->whereNull('parent_id')
                        ->whereNull('deleted_at');
                })
                ->orderBy('id')
                ->first()
            : null;

        if ($departmentHead) {
            $approvalChain[] = [
                'level' => 2,
                'approver_id' => $departmentHead->id,
                'approver_name' => $departmentHead->name,
                'status' => 'pending',
                'approved_at' => null,
                'comments' => null,
            ];
        }

        // Level 3: HR Manager (for leaves > 5 days or special leave types)
        if ($leave->no_of_days > 5 || in_array($leave->leave_type, $this->getSpecialLeaveTypes())) {
            $hrManager = User::whereHas('roles', function ($query) {
                $query->where('name', 'HR Manager')
                    ->orWhere('name', 'HR Head')
                    ->orWhere('name', 'Super Admin');
            })->first();

            if ($hrManager) {
                $approvalChain[] = [
                    'level' => 3,
                    'approver_id' => $hrManager->id,
                    'approver_name' => $hrManager->name,
                    'status' => 'pending',
                    'approved_at' => null,
                    'comments' => null,
                ];
            }
        }

        return $approvalChain;
    }

    /**
     * Submit leave request for approval
     */
    public function submitForApproval(Leave $leave): bool
    {
        DB::beginTransaction();
        try {
            // Build approval chain
            $approvalChain = $this->buildApprovalChain($leave);

            if (empty($approvalChain)) {
                // Auto-approve if no approvers found
                $leave->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                ]);

                // An approved leave must consume balance — this path previously
                // approved WITHOUT posting consumption (silent free leave).
                app(LeaveLedgerService::class)->consume($leave->fresh());

                Log::info("Leave #{$leave->id} auto-approved - no approvers in chain");

                DB::commit();

                return true;
            }

            // Update leave with approval chain
            $leave->update([
                'approval_chain' => $approvalChain,
                'current_approval_level' => 1,
                'status' => 'pending',
                'submitted_at' => now(),
            ]);

            // Notify first approver
            $this->notifyCurrentApprover($leave);

            DB::commit();
            Log::info("Leave #{$leave->id} submitted for approval", [
                'user_id' => $leave->user_id,
                'levels' => count($approvalChain),
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to submit leave #{$leave->id} for approval", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Approve a leave request — THE single approval decision path.
     *
     * Two modes, decided in one place:
     *   - NORMAL manager approval: the actor must be the current-level approver
     *     (canApprove). The chain advances level by level and only finalizes
     *     (status => approved, ledger consumed, employee notified) at the last
     *     level. Multi-level approvals therefore still step through each level.
     *   - ADMIN OVERRIDE: an actor with leaves.manage / Super Admin passing
     *     $opts['force'] => true may FINALIZE a non-terminal leave regardless of
     *     the current chain level. The override is RECORDED in the approval_chain
     *     as an 'admin_override' entry (and any still-pending levels are marked
     *     'superseded') so history stays honest and the leave leaves EVERY
     *     approver's pending queue cleanly — no "manager sees 0 pending" orphan.
     *
     * Side effects (chain, status, ledger, audit, notification, realtime signal)
     * all live here and fire exactly once. Realtime is emitted post-commit so a
     * rolled-back decision never signals.
     *
     * @param  array{force?: bool}  $opts
     */
    public function approve(Leave $leave, User $approver, ?string $comments = null, array $opts = []): array
    {
        DB::beginTransaction();
        try {
            $before = $leave->toArray();
            $isCurrentApprover = $this->canApprove($leave, $approver);
            $override = ($opts['force'] ?? false)
                && $this->canOverride($approver)
                && $this->isNonTerminal($leave);

            if (! $isCurrentApprover && ! $override) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'You are not authorized to approve this leave request.',
                ];
            }

            // ---- ADMIN OVERRIDE: finalize regardless of chain position --------
            if ($override) {
                $chain = $this->recordAdminOverride($leave->approval_chain ?? [], $approver, 'approved', $comments);

                $leave->update([
                    'approval_chain' => $chain,
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => $approver->id,
                ]);

                $this->notifyEmployeeApproved($leave->fresh());
                app(LeaveLedgerService::class)->consume($leave->fresh());
                app(LeaveAuditService::class)->record('admin_override', $leave->id, $before, $leave->fresh()->toArray(), $comments);

                DB::commit();
                Log::info("Leave #{$leave->id} finalized by admin override", ['actor' => $approver->id]);

                $this->signalLeaveChange($approver->id, 'approve');

                return [
                    'success' => true,
                    'message' => 'Leave request finalized by administrator override.',
                    'status' => 'approved',
                ];
            }

            // ---- NORMAL per-level approval ------------------------------------
            $approvalChain = $leave->approval_chain;
            $currentLevel = $leave->current_approval_level;

            foreach ($approvalChain as &$level) {
                if ($level['level'] === $currentLevel && $level['approver_id'] === $approver->id) {
                    $level['status'] = 'approved';
                    $level['approved_at'] = now()->toDateTimeString();
                    $level['comments'] = $comments;
                    break;
                }
            }
            unset($level);

            $hasMoreLevels = collect($approvalChain)
                ->where('level', '>', $currentLevel)
                ->isNotEmpty();

            if ($hasMoreLevels) {
                // Move to next level — leave stays pending for the next approver.
                $leave->update([
                    'approval_chain' => $approvalChain,
                    'current_approval_level' => $currentLevel + 1,
                ]);

                $this->notifyCurrentApprover($leave);
                app(LeaveAuditService::class)->record('approve', $leave->id, $before, $leave->fresh()->toArray(), $comments);

                DB::commit();

                $this->signalLeaveChange($approver->id, 'approve');

                return [
                    'success' => true,
                    'message' => 'Leave approved. Forwarded to next level.',
                    'status' => 'pending',
                ];
            }

            // Final level cleared — mark approved.
            $leave->update([
                'approval_chain' => $approvalChain,
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            $this->notifyEmployeeApproved($leave->fresh());
            app(LeaveLedgerService::class)->consume($leave->fresh());
            app(LeaveAuditService::class)->record('approve', $leave->id, $before, $leave->fresh()->toArray(), $comments);

            DB::commit();
            Log::info("Leave #{$leave->id} fully approved", ['final_approver' => $approver->id]);

            $this->signalLeaveChange($approver->id, 'approve');

            return [
                'success' => true,
                'message' => 'Leave request approved successfully.',
                'status' => 'approved',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to approve leave #{$leave->id}", [
                'error' => $e->getMessage(),
                'approver' => $approver->id,
            ]);

            return [
                'success' => false,
                'message' => 'Failed to approve leave request.',
            ];
        }
    }

    /**
     * Reject a leave request — THE single rejection decision path.
     *
     * Normal path: the actor must be the current-level approver. Admin override
     * ($opts['force'] with leaves.manage / Super Admin) may reject a non-terminal
     * leave regardless of chain position, recorded as an 'admin_override' entry.
     * A rejection is terminal at any level, so the leave leaves every approver's
     * pending queue. Side effects fire exactly once; realtime is post-commit.
     *
     * @param  array{force?: bool}  $opts
     */
    public function reject(Leave $leave, User $approver, string $reason, array $opts = []): array
    {
        DB::beginTransaction();
        try {
            $before = $leave->toArray();
            $isCurrentApprover = $this->canApprove($leave, $approver);
            $override = ($opts['force'] ?? false)
                && $this->canOverride($approver)
                && $this->isNonTerminal($leave);

            if (! $isCurrentApprover && ! $override) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'You are not authorized to reject this leave request.',
                ];
            }

            if ($override && ! $isCurrentApprover) {
                // Admin rejects a leave they are not the chain approver for.
                $approvalChain = $this->recordAdminOverride($leave->approval_chain ?? [], $approver, 'rejected', $reason);
                $auditAction = 'admin_override';
            } else {
                $approvalChain = $leave->approval_chain;
                $currentLevel = $leave->current_approval_level;

                foreach ($approvalChain as &$level) {
                    if ($level['level'] === $currentLevel && $level['approver_id'] === $approver->id) {
                        $level['status'] = 'rejected';
                        $level['approved_at'] = now()->toDateTimeString();
                        $level['comments'] = $reason;
                        break;
                    }
                }
                unset($level);
                $auditAction = 'reject';
            }

            $leave->update([
                'approval_chain' => $approvalChain,
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'rejected_by' => $approver->id,
            ]);

            $this->notifyEmployeeRejected($leave->fresh(), $reason);

            // A rejection frees any previously-consumed balance (no-op if never consumed).
            app(LeaveLedgerService::class)->reverseConsumption($leave->id, 'Leave rejected');

            app(LeaveAuditService::class)->record($auditAction, $leave->id, $before, $leave->fresh()->toArray(), $reason);

            DB::commit();
            Log::info("Leave #{$leave->id} rejected", [
                'rejector' => $approver->id,
                'reason' => $reason,
            ]);

            $this->signalLeaveChange($approver->id, 'reject');

            return [
                'success' => true,
                'message' => 'Leave request rejected.',
                'status' => 'rejected',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to reject leave #{$leave->id}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to reject leave request.',
            ];
        }
    }

    /**
     * Admin-only status override for target statuses that are not a plain
     * approve/reject decision (used by the web-admin updateStatus / bulk status
     * tools). Approve/reject delegate to the decision pipeline above (with force)
     * so their side effects stay identical; cancel/reopen are handled here.
     * Requires leaves.manage / Super Admin.
     */
    public function overrideStatus(Leave $leave, User $actor, string $targetStatus, ?string $reason = null): array
    {
        $target = strtolower(trim($targetStatus));
        $canOverride = $this->canOverride($actor);

        // approve/reject targets flow through the decision pipeline. Force is
        // honoured only for an override-capable actor; a plain current-level
        // approver still advances/finalizes normally without it.
        if ($target === 'approved') {
            $result = $this->approve($leave, $actor, $reason, ['force' => $canOverride]);
            $result['updated'] = $result['success'] ?? false;

            return $result;
        }

        if (in_array($target, ['rejected', 'declined'], true)) {
            $result = $this->reject($leave, $actor, $reason ?: 'Reviewed by an administrator.', ['force' => $canOverride]);
            $result['updated'] = $result['success'] ?? false;

            return $result;
        }

        if (! in_array($target, ['pending', 'cancelled'], true)) {
            return [
                'success' => false,
                'updated' => false,
                'message' => "Unsupported target status '{$target}'.",
            ];
        }

        // Re-open / cancel are administrative moves — override-capable actors only.
        if (! $canOverride) {
            return [
                'success' => false,
                'updated' => false,
                'message' => 'You are not authorized to override this leave status.',
            ];
        }

        if (strtolower((string) $leave->status) === $target) {
            return [
                'success' => false,
                'updated' => false,
                'message' => 'Leave status remains unchanged.',
            ];
        }

        DB::beginTransaction();
        try {
            $before = $leave->toArray();
            $wasApproved = strtolower((string) $leave->status) === 'approved';

            if ($target === 'cancelled') {
                $chain = $this->recordAdminOverride($leave->approval_chain ?? [], $actor, 'cancelled', $reason);
                $leave->update([
                    'approval_chain' => $chain,
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                ]);

                if ($wasApproved) {
                    app(LeaveLedgerService::class)->reverseConsumption($leave->id, $reason ?? 'Cancelled by administrator');
                }

                $signalAction = 'cancel';
            } else {
                // Re-open: hand the leave back to the level-1 approver's queue.
                $chain = $this->reopenChain($leave->approval_chain ?? [], $actor, $reason);
                $leave->update([
                    'approval_chain' => $chain,
                    'current_approval_level' => 1,
                    'status' => 'pending',
                    'approved_at' => null,
                    'rejection_reason' => null,
                    'rejected_by' => null,
                ]);

                if ($wasApproved) {
                    app(LeaveLedgerService::class)->reverseConsumption($leave->id, $reason ?? 'Re-opened by administrator');
                }

                $this->notifyCurrentApprover($leave->fresh());
                $signalAction = 'update';
            }

            app(LeaveAuditService::class)->record('admin_override', $leave->id, $before, $leave->fresh()->toArray(), $reason);

            DB::commit();

            $this->signalLeaveChange($actor->id, $signalAction);

            return [
                'success' => true,
                'updated' => true,
                'message' => 'Leave status updated to '.$target.'.',
                'status' => $target,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to override leave #{$leave->id} status", [
                'error' => $e->getMessage(),
                'target' => $target,
            ]);

            return [
                'success' => false,
                'updated' => false,
                'message' => 'Failed to update leave status.',
            ];
        }
    }

    /**
     * True for a leave that can still be acted on (not already closed).
     */
    protected function isNonTerminal(Leave $leave): bool
    {
        return ! in_array(strtolower((string) $leave->status), ['approved', 'rejected', 'declined', 'cancelled'], true);
    }

    /**
     * May this actor finalize a leave regardless of chain position?
     * (leaves.manage permission or the Super Admin role.)
     */
    public function canOverride(User $actor): bool
    {
        return $actor->can('leaves.manage') || $actor->hasRole('Super Admin');
    }

    /**
     * Close out an approval chain for an admin override: mark every still-pending
     * level 'superseded' (so no approver is left waiting) and append a dedicated
     * 'admin_override' entry recording who acted and to what target. Keeps chain
     * history honest and guarantees the leave exits every pending queue.
     *
     * @param  array<int, array<string, mixed>>  $chain
     * @return array<int, array<string, mixed>>
     */
    protected function recordAdminOverride(array $chain, User $actor, string $action, ?string $reason): array
    {
        foreach ($chain as &$level) {
            if (strtolower((string) ($level['status'] ?? '')) === 'pending') {
                $level['status'] = 'superseded';
            }
        }
        unset($level);

        $chain[] = [
            'level' => 'override',
            'approver_id' => $actor->id,
            'approver_name' => $actor->name,
            'status' => 'admin_override',
            'action' => $action,
            'approved_at' => now()->toDateTimeString(),
            'comments' => $reason,
        ];

        return $chain;
    }

    /**
     * Reset a chain so level 1 is pending again (admin re-open), appending an
     * 'admin_override' reopen marker for the audit trail.
     *
     * @param  array<int, array<string, mixed>>  $chain
     * @return array<int, array<string, mixed>>
     */
    protected function reopenChain(array $chain, User $actor, ?string $reason): array
    {
        foreach ($chain as &$level) {
            if (($level['level'] ?? null) === 1) {
                $level['status'] = 'pending';
                $level['approved_at'] = null;
                $level['comments'] = null;
            }
        }
        unset($level);

        $chain[] = [
            'level' => 'override',
            'approver_id' => $actor->id,
            'approver_name' => $actor->name,
            'status' => 'admin_override',
            'action' => 'reopen',
            'approved_at' => now()->toDateTimeString(),
            'comments' => $reason,
        ];

        return $chain;
    }

    /**
     * Emit the single canonical realtime marker for a leave change. Called
     * post-commit so a rolled-back decision never signals. The bucket is fixed
     * at ('leave','all') — the mobile My Leaves screen and the web AdminLeavesPanel
     * both subscribe there. actorId is the acting user, so their own device
     * self-suppresses while every other viewer refetches.
     */
    protected function signalLeaveChange(?int $actorId, string $action): void
    {
        try {
            app(RealtimeSignal::class)->touch('leave', 'all', $actorId, $action);
        } catch (\Throwable $e) {
            // Realtime is best-effort and must never break an already-committed write.
            Log::warning('Leave realtime signal failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Fail-soft: notify the employee their leave was approved.
     */
    protected function notifyEmployeeApproved(Leave $leave): void
    {
        // Explicit eager-load: strict mode (preventLazyLoading) would otherwise
        // turn a lazy `->employee` access into a violation that this method's
        // catch would silently swallow — dropping the notification.
        $leave->loadMissing('employee', 'leaveSetting');

        if (! $leave->employee) {
            return;
        }

        try {
            $leave->employee->notify(new LeaveApprovedNotification($leave));
        } catch (\Throwable $exception) {
            Log::warning("Leave #{$leave->id} approved but employee notification failed", [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Fail-soft: notify the employee their leave was rejected.
     */
    protected function notifyEmployeeRejected(Leave $leave, string $reason): void
    {
        $leave->loadMissing('employee', 'leaveSetting');

        if (! $leave->employee) {
            return;
        }

        try {
            $leave->employee->notify(new LeaveRejectedNotification($leave, $reason));
        } catch (\Throwable $exception) {
            Log::warning("Leave #{$leave->id} rejected but employee notification failed", [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Check if user can approve this leave at current level
     */
    public function canApprove(Leave $leave, User $user): bool
    {
        if ($leave->status !== 'pending') {
            return false;
        }

        $approvalChain = $leave->approval_chain;
        $currentLevel = $leave->current_approval_level;

        foreach ($approvalChain as $level) {
            if ($level['level'] === $currentLevel && $level['approver_id'] === $user->id && $level['status'] === 'pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get current approver for a leave request
     */
    public function getCurrentApprover(Leave $leave): ?User
    {
        if ($leave->status !== 'pending' || ! $leave->approval_chain) {
            return null;
        }

        $currentLevel = $leave->current_approval_level;
        $approvalChain = $leave->approval_chain;

        foreach ($approvalChain as $level) {
            if ($level['level'] === $currentLevel && $level['status'] === 'pending') {
                return User::find($level['approver_id']);
            }
        }

        return null;
    }

    /**
     * Notify current approver
     */
    protected function notifyCurrentApprover(Leave $leave): void
    {
        $approver = $this->getCurrentApprover($leave);

        if ($approver) {
            try {
                $approver->notify(new LeaveApprovalNotification($leave));
            } catch (\Throwable $exception) {
                Log::warning("Leave #{$leave->id} approver notification failed", [
                    'approver_id' => $approver->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get leave types requiring HR approval
     */
    protected function getSpecialLeaveTypes(): array
    {
        // Get IDs for maternity, paternity, unpaid leave, etc.
        return LeaveSetting::whereIn('type', [
            'Maternity Leave',
            'Paternity Leave',
            'Unpaid Leave',
            'Sabbatical',
        ])->pluck('id')->toArray();
    }

    /**
     * Get leaves pending approval for a user
     */
    public function getPendingApprovalsForUser(User $user): Collection
    {
        return Leave::where('status', 'pending')
            ->whereNotNull('approval_chain')
            ->get()
            ->filter(function ($leave) use ($user) {
                return $this->canApprove($leave, $user);
            });
    }

    /**
     * Get approval statistics for a user
     */
    public function getApprovalStats(User $user): array
    {
        $pending = $this->getPendingApprovalsForUser($user)->count();

        $approved = Leave::whereNotNull('approval_chain')
            ->where('status', 'approved')
            ->get()
            ->filter(function ($leave) use ($user) {
                foreach ($leave->approval_chain as $level) {
                    if ($level['approver_id'] === $user->id && $level['status'] === 'approved') {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        $rejected = Leave::whereNotNull('approval_chain')
            ->where('status', 'rejected')
            ->get()
            ->filter(function ($leave) use ($user) {
                foreach ($leave->approval_chain as $level) {
                    if ($level['approver_id'] === $user->id && $level['status'] === 'rejected') {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
        ];
    }
}
