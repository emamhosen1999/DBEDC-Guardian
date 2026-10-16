<?php

namespace App\Services\Attendance;

use App\Models\HRM\Leave;
use App\Models\HRM\ReplacementLeaveRequest;
use App\Models\HRM\RosterDay;
use App\Models\HRM\RosterDayChange;
use App\Models\User;
use App\Notifications\Attendance\RosterChangedNotification;
use App\Notifications\Attendance\TimeCorrectionDecidedNotification;
use App\Notifications\Attendance\TimeCorrectionRequestedNotification;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attachments\AttachmentService;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Realtime\RealtimeSignal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Taking the replacement day off. The employee picks a rostered WORKING day; the days come first-expiring-first from the
 * replacement credit. It needs a clear application image, goes through the normal approval chain and, once approved, the
 * date becomes the employee's weekly off: a locked roster day with no shift, audited in roster_day_changes.
 */
class ReplacementLeaveService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly CompOffService $compOff,
        private readonly ScheduleResolver $schedules,
        private readonly HolidayService $holidays,
        private readonly AttachmentService $attachments,
        private readonly RealtimeSignal $signal,
    ) {}

    /**
     * @param  array{date: string, days: float|int|string, reason?: ?string, attachment_tokens?: list<string>}  $data
     *
     * @throws ValidationException
     * @throws AttachmentRejected
     */
    public function request(string $userId, array $data): ReplacementLeaveRequest
    {
        $date = Carbon::parse($data['date'])->startOfDay();
        $days = (float) $data['days'];
        if (! in_array($days, [0.5, 1.0], true)) {
            throw ValidationException::withMessages(['days' => ['Choose a full day or a half day.']]);
        }
        if ($date->lt(Carbon::today())) {
            throw ValidationException::withMessages(['date' => [__('overtime.replacement.in_past')]]);
        }
        if ($this->holidays->isHoliday($date)) {
            throw ValidationException::withMessages(['date' => [__('overtime.replacement.holiday')]]);
        }
        if (! $this->schedules->resolve($userId, $date)->isWorkingDay) {
            throw ValidationException::withMessages(['date' => [__('overtime.replacement.not_working_day')]]);
        }
        if (ReplacementLeaveRequest::where('user_id', $userId)->whereDate('replacement_date', $date->toDateString())->whereIn('status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['date' => [__('overtime.replacement.duplicate')]]);
        }
        $onLeave = Leave::where('user_id', $userId)->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $date->toDateString())->whereDate('to_date', '>=', $date->toDateString())->exists();
        if ($onLeave) {
            throw ValidationException::withMessages(['date' => [__('overtime.replacement.on_leave')]]);
        }

        // A half-day credit can only be used as a half day: a whole day needs whole-day credit.
        $allocations = $this->compOff->allocate($userId, $days, $date);
        if ($allocations === null) {
            $halfOnly = $days === 1.0 && $this->compOff->available($userId, $date) > 0;
            throw ValidationException::withMessages(['days' => [$halfOnly ? __('overtime.replacement.half_day_only') : __('overtime.replacement.no_credit')]]);
        }

        // The application image is required and must be clear.
        $staged = $this->attachments->resolve((array) ($data['attachment_tokens'] ?? []), $userId);
        if ($staged->isEmpty()) {
            throw new AttachmentRejected(__('attachments.errors.required'), 'required');
        }

        $request = DB::transaction(function () use ($userId, $date, $days, $data, $allocations, $staged) {
            $r = ReplacementLeaveRequest::create([
                'user_id' => $userId, 'replacement_date' => $date->toDateString(), 'days' => $days,
                'reason' => $data['reason'] ?? null, 'allocations' => $allocations,
            ]);
            $this->approvals->submit($r);
            $this->attachments->attach($r, $staged, $userId);

            return $r;
        });
        $request->refresh();

        $first = collect($request->approval_chain ?? [])->firstWhere('level', 1);
        if ($request->status !== 'approved' && $first && ($approver = User::find($first['approver_id']))) {
            $this->notify($approver, new TimeCorrectionRequestedNotification($request->id, User::find($userId)?->name), "Replacement request notification failed #{$request->id}");
        }
        $this->signal->touch('attendance', 'all', $userId, 'replacement_apply');

        return $request;
    }

    public function approve(ReplacementLeaveRequest $r, User $approver, ?string $comments = null): array
    {
        $res = $this->approvals->approve($r, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $r->refresh();
            DB::transaction(function () use ($r, $approver) {
                $this->compOff->consume($r);
                $this->makeWeeklyOff($r, $approver);
            });
            if ($employee = User::find($r->user_id)) {
                $this->notify($employee, new TimeCorrectionDecidedNotification($r->id, 'approved'), "Replacement decision notification failed #{$r->id}");
                $this->notify($employee, new RosterChangedNotification($r->replacement_date->toDateString()), "Roster change notification failed #{$r->id}");
            }
        }
        if ($res['success'] ?? false) {
            $this->signal->touch('attendance', 'all', $approver->id, 'replacement_approve');
        }

        return $res;
    }

    public function reject(ReplacementLeaveRequest $r, User $approver, string $reason): array
    {
        $res = $this->approvals->reject($r, $approver, $reason);
        if ($res['success'] ?? false) {
            if ($employee = User::find($r->user_id)) {
                $this->notify($employee, new TimeCorrectionDecidedNotification($r->id, 'rejected'), "Replacement decision notification failed #{$r->id}");
            }
            $this->signal->touch('attendance', 'all', $approver->id, 'replacement_reject');
        }

        return $res;
    }

    /**
     * The replacement date becomes a weekly off (a locked roster day without a shift), audited like any roster change.
     * A half day is not a roster change: the register notes it in the Remark and the employee keeps the day's shift.
     */
    private function makeWeeklyOff(ReplacementLeaveRequest $r, User $actor): void
    {
        if ((float) $r->days < 1.0) {
            return;
        }
        $date = $r->replacement_date->toDateString();
        $existing = RosterDay::where('user_id', $r->user_id)->whereDate('date', $date)->first();
        // What the roster had for that date (a materialised row, else the assignment / pattern): the audit trail records the change from it.
        $oldShift = $existing ? $existing->shift_id : app(RosterService::class)->resolveShift($r->user_id, $r->replacement_date)?->id;
        $oldSource = $existing?->source;

        $note = 'Replacement off for rest day worked '.collect($r->allocations)->pluck('work_date')->implode(', ');
        $day = RosterDay::updateOrCreate(
            ['user_id' => $r->user_id, 'date' => $date],
            ['shift_id' => null, 'source' => 'manual', 'locked' => true, 'note' => $note],
        );

        foreach ([['shift_id', $oldShift, null], ['source', $oldSource, 'manual']] as [$field, $old, $new]) {
            if ((string) $old !== (string) $new) {
                RosterDayChange::create([
                    'roster_day_id' => $day->id, 'actor_id' => (string) $actor->employee_id, 'field' => $field,
                    'old_value' => $old === null ? null : (string) $old, 'new_value' => $new === null ? null : (string) $new,
                    'reason' => "Replacement leave #{$r->id}: {$note}",
                ]);
            }
        }
        $r->update(['roster_applied' => true]);
    }

    private function notify(User $user, $notification, string $context): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::error($context, ['error' => $e->getMessage()]);
        }
    }
}
