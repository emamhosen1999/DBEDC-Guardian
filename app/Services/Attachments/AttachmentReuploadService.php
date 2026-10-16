<?php

namespace App\Services\Attachments;

use App\Models\Attachments\AttachmentEvent;
use App\Models\HRM\Leave;
use App\Models\HRM\ReplacementLeaveRequest;
use App\Models\User;
use App\Notifications\Attachments\ReturnedForReuploadNotification;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Request re-upload": an approver returns an application because its document is not clear enough. The application
 * stays pending at the SAME approval step (status and chain untouched); the employee is told why on web and push,
 * re-uploads (the old file stays in history) and it is decidable again. Audited in attachment_events.
 */
class AttachmentReuploadService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AttendanceApprovalService $approvals,
        private readonly LeaveApprovalService $leaveApprovals,
        private readonly RealtimeSignal $signal,
    ) {}

    public static function subjectOf(Model $application): string
    {
        return match (true) {
            $application instanceof Leave => 'leave',
            $application instanceof ReplacementLeaveRequest => 'replacement',
            default => 'overtime',
        };
    }

    /** The permission that gates the action for this kind of application. */
    public static function permissionFor(Model $application, string $action = 'request_reupload'): string
    {
        return (self::subjectOf($application) === 'leave' ? 'leaves' : 'overtime').'.'.($action === 'view' ? 'attachments.view' : $action);
    }

    public function request(Model $application, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new AttachmentRejected(__('attachments.reupload.reason_required'), 'reason_required');
        }
        if (strtolower((string) $application->status) !== 'pending') {
            throw new AttachmentRejected(__('attachments.reupload.not_pending'), 'not_pending');
        }
        $allowed = $application instanceof Leave ? $actor->can('leaves.request_reupload') : $actor->can('overtime.request_reupload');
        if (! $allowed) {
            throw new AttachmentRejected('You are not allowed to request a re-upload.', 'forbidden');
        }
        $canAct = $application instanceof Leave ? $this->leaveApprovals->canApprove($application, $actor) : $this->approvals->canApprove($application, $actor);
        if (! $canAct) {
            throw new AttachmentRejected('You are not an approver of this application.', 'forbidden');
        }

        DB::transaction(function () use ($application, $actor, $reason) {
            $application->forceFill([
                'reupload_requested_at' => now(), 'reupload_requested_by' => (string) $actor->employee_id,
                'reupload_reason' => $reason, 'reupload_count' => (int) $application->reupload_count + 1,
            ])->save();
            AttachmentEvent::create([
                'subject_type' => $application::attachmentSubjectType(), 'subject_id' => $application->getKey(),
                'actor_id' => (string) $actor->employee_id, 'action' => 'reupload_requested', 'reason' => $reason,
                'meta' => ['approval_level' => $application->current_approval_level],
            ]);
        });

        $employee = User::find($application->user_id);
        if ($employee) {
            try {
                $employee->notify(new ReturnedForReuploadNotification(self::subjectOf($application), (int) $application->getKey(), $reason));
            } catch (\Throwable $e) {
                Log::error('ReturnedForReuploadNotification failed', ['error' => $e->getMessage()]);
            }
        }
        $this->signal->touch(self::subjectOf($application) === 'leave' ? 'leave' : 'attendance', 'all', $actor->employee_id, 'reupload_requested');
    }

    /**
     * The employee's re-upload.
     *
     * @param  list<string>  $tokens
     */
    public function reupload(Model $application, User $employee, array $tokens): void
    {
        if ((string) $application->user_id !== (string) $employee->employee_id) {
            throw new AttachmentRejected('Only the applicant can re-upload.', 'forbidden');
        }
        if (! $application->isReturnedForReupload()) {
            throw new AttachmentRejected(__('attachments.reupload.not_returned'), 'not_returned');
        }
        $staged = $this->attachments->resolve($tokens, (string) $employee->employee_id);
        if ($staged->isEmpty()) {
            throw new AttachmentRejected(__('attachments.errors.required'), 'required');
        }

        DB::transaction(function () use ($application, $employee, $staged) {
            $this->attachments->replace($application, $staged, (string) $employee->employee_id);
            $application->forceFill(['reupload_requested_at' => null, 'reupload_reason' => null])->save();
        });

        $this->signal->touch(self::subjectOf($application) === 'leave' ? 'leave' : 'attendance', 'all', $employee->employee_id, 'reuploaded');
    }
}
