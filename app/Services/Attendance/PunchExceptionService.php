<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Access\SelfAdministration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PunchExceptionService
{
    public function __construct(private readonly AttendanceAuditService $audit, private readonly DepartmentScope $scope) {}

    /** Pending exceptions the actor may decide (their department scope; never their own). */
    public function pending(User $actor): Collection
    {
        $visible = $this->scope->visibleEmployeeIds($actor);

        return Attendance::where('needs_approval', true)->where('policy_status', 'provisional')
            ->when($visible !== null, fn ($q) => $q->whereIn('user_id', $visible === [] ? ['__NONE__'] : $visible)
                ->where('user_id', '!=', (string) $actor->getKey()))
            ->with('user:employee_id,name')->orderByDesc('date')->get();
    }

    /**
     * Non-global actors may decide only exceptions of employees in their scope, and
     * never their own punch (mirrors AttendanceApprovalService::canApprove()).
     */
    private function assertMayDecide(Attendance $att, User $actor): void
    {
        if ($this->scope->isGlobal($actor)) {
            return;
        }
        if (! $this->scope->canActOn($actor, (string) $att->user_id)) {
            abort(403, 'You do not have access to this employee.');
        }

        // Own punch exception: only the governed exception gets here (canActOn is false for oneself otherwise);
        // it runs inside the caller's transaction, so a failed decision rolls the log and the notification back.
        if ((string) $actor->getKey() === (string) $att->user_id) {
            app(SelfAdministration::class)->record($actor, 'punch_exception.decide', 'decided his own punch exception', 'attendance', $att->id);
        }
    }

    public function approve(int $attendanceId, User $approver): array
    {
        return DB::transaction(function () use ($attendanceId, $approver) {
            $att = Attendance::findOrFail($attendanceId);
            $this->assertMayDecide($att, $approver);
            $before = $att->only(['policy_status', 'needs_approval']);
            $att->update(['policy_status' => 'accepted', 'needs_approval' => false]);
            $this->audit->record('policy.exception.approve', $att->id, $before, $att->only(['policy_status', 'needs_approval']), 'Punch exception approved by '.($approver->employee_id ?? $approver->getKey()), null);

            return ['success' => true, 'status' => 'accepted'];
        });
    }

    public function reject(int $attendanceId, User $approver, string $reason): array
    {
        return DB::transaction(function () use ($attendanceId, $approver, $reason) {
            $att = Attendance::findOrFail($attendanceId);
            $this->assertMayDecide($att, $approver);
            $before = $att->only(['policy_status', 'needs_approval']);
            $att->update(['policy_status' => 'rejected', 'needs_approval' => false, 'policy_exception_reason' => $reason]);
            $this->audit->record('policy.exception.reject', $att->id, $before, $att->only(['policy_status', 'needs_approval']), $reason, null);

            return ['success' => true, 'status' => 'rejected'];
        });
    }
}
