<?php

namespace App\Console\Commands;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\BiometricAttLog;
use App\Models\User;
use App\Services\Attendance\RegularizationService;
use App\Services\Biometric\BiometricProcessingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turn an employee's rejected terminal scans into regularization requests for their approver.
 *
 * A scan rejected because the employee's attendance type is not biometric (or they have none) is
 * evidence of presence that the punch pipeline deliberately refuses to convert: doing so would
 * override an attendance policy an admin set (see ReplayOrphanedBiometricPunches). Once the
 * configuration is corrected, the lost days still need a decision, and the standard owner of a
 * retroactive attendance change is the employee's approver. This raises one pending request per
 * day (first scan in, last scan out) through the normal regularization workflow; nothing is
 * written to attendance until an approver approves it.
 *
 * Dry run by default. Idempotent: days that already have attendance, approved leave, or a pending
 * or approved request are skipped.
 */
class RaiseCaptureExceptionRegularizations extends Command
{
    protected $signature = 'attendance:raise-capture-exceptions
                            {employee : The employee_id whose rejected scans to raise}
                            {--from= : First date (Y-m-d), default 60 days ago}
                            {--to= : Last date (Y-m-d), default yesterday}
                            {--apply : Create the requests (otherwise only report them)}';

    protected $description = 'Raise regularization requests, for approval, from terminal scans rejected by attendance configuration.';

    public function handle(RegularizationService $regularizations): int
    {
        $employee = User::where('employee_id', (string) $this->argument('employee'))->first();
        if (! $employee) {
            $this->error('No employee '.$this->argument('employee').'.');

            return self::FAILURE;
        }

        $from = Carbon::parse($this->option('from') ?: now()->subDays(60)->toDateString())->startOfDay();
        $to = Carbon::parse($this->option('to') ?: now()->subDay()->toDateString())->endOfDay();
        $apply = (bool) $this->option('apply');

        $scans = BiometricAttLog::where('user_id', $employee->employee_id)
            ->where('punch_status', 'failed')
            ->where(fn ($q) => $q->where('punch_status_reason', BiometricProcessingService::REASON_NO_ATTENDANCE_TYPE)
                ->orWhere('punch_status_reason', 'like', BiometricProcessingService::REASON_NOT_BIOMETRIC_PREFIX.'%'))
            ->whereBetween('punch_time', [$from, $to])
            ->get()
            ->map(fn (BiometricAttLog $log) => ['at' => Carbon::parse($log->corrected_punch_time ?? $log->punch_time), 'log' => $log])
            ->sortBy('at')
            ->groupBy(fn ($scan) => $scan['at']->toDateString());

        $rows = [];
        $raised = 0;
        foreach ($scans as $date => $day) {
            $skip = $this->skipReason((string) $employee->employee_id, $date);
            $in = $day->first()['at'];
            $last = $day->last()['at'];
            $out = $day->count() > 1 && $last->diffInMinutes($in, true) >= 5 ? $last : null;
            $status = $skip ?? ($apply ? 'raised' : 'would raise');

            if ($skip === null && $apply) {
                try {
                    $request = $regularizations->request((string) $employee->employee_id, [
                        'date' => $date,
                        'type' => 'other',
                        'requested_punchin' => $in->toDateTimeString(),
                        'requested_punchout' => $out?->toDateTimeString(),
                        'reason' => $this->reason($day, $employee->name),
                    ]);
                    $status = "raised #{$request->id} ({$request->status})";
                    $raised++;
                } catch (ValidationException $e) {
                    $status = 'not raised: '.collect($e->errors())->flatten()->first();
                }
            }

            $rows[] = [$date, $day->count(), $in->format('H:i'), $out?->format('H:i') ?? '-', $status];
        }

        $this->table(['Date', 'Scans', 'In', 'Out', 'Result'], $rows);
        $this->info(sprintf('%s: %d day(s) with rejected scans; %d request(s) %s.', $employee->name, count($rows), $apply ? $raised : collect($rows)->where(4, 'would raise')->count(), $apply ? 'raised' : 'would be raised (dry run, use --apply)'));

        return self::SUCCESS;
    }

    private function skipReason(string $employeeId, string $date): ?string
    {
        if (Attendance::where('user_id', $employeeId)->whereDate('date', $date)->whereNotNull('punchin')->exists()) {
            return 'skipped: attendance exists';
        }
        if (AttendanceRegularization::where('user_id', $employeeId)->whereDate('date', $date)->whereIn('status', ['pending', 'approved'])->exists()) {
            return 'skipped: request exists';
        }
        $onLeave = DB::table('leaves')->where('user_id', $employeeId)->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->exists();

        return $onLeave ? 'skipped: approved leave' : null;
    }

    private function reason($day, string $name): string
    {
        $first = $day->first()['log'];
        $times = $day->map(fn ($scan) => $scan['at']->format('H:i'))->implode(', ');

        return Str::limit(sprintf(
            'System-raised: %d scan(s) at %s on terminal %s were rejected (%s). Approve if %s worked this day.',
            $day->count(), $times, $first->serial_number, $first->punch_status_reason, $name
        ), 255, '');
    }
}
