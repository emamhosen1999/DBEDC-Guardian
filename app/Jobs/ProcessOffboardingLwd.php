<?php

namespace App\Jobs;

use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\HRM\Offboarding;
use App\Models\HRM\RosterDay;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Notifications\Attendance\OffboardingInitiatedNotification;
use App\Services\DeviceAuthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProcessOffboardingLwd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly Offboarding $offboarding,
    ) {}

    /**
     * Queue the LWD effects: delayed to the end of the last working day when it
     * is still in the future, immediately when it is already past (e.g. abscond).
     * Safe to call repeatedly — handle() re-checks everything.
     */
    public static function dispatchFor(Offboarding $offboarding): void
    {
        // Non-destructive: the process is under way as soon as it is initiated.
        if ($offboarding->status === Offboarding::STATUS_PENDING) {
            $offboarding->update(['status' => Offboarding::STATUS_IN_PROGRESS]);
        }

        if ($offboarding->lwdHasPassed()) {
            static::dispatch($offboarding)->afterCommit();

            return;
        }

        static::dispatch($offboarding)->delay($offboarding->lwdEndsAt())->afterCommit();
    }

    public function handle(DeviceAuthService $deviceAuth): void
    {
        $offboarding = $this->offboarding->fresh();
        if (! $offboarding || $offboarding->status === Offboarding::STATUS_CANCELLED) {
            Log::info('ProcessOffboardingLwd: offboarding cancelled or deleted, skipping', [
                'offboarding_id' => $this->offboarding->id,
            ]);

            return;
        }

        $lwd = $offboarding->last_working_date;
        if (! $lwd) {
            Log::warning('ProcessOffboardingLwd: no last_working_date set', [
                'offboarding_id' => $offboarding->id,
            ]);

            return;
        }

        // LWD still ahead (never reached, or extended after this job was queued):
        // nothing destructive may happen. A later dispatch / the daily sweep handles it.
        if (! $offboarding->lwdHasPassed()) {
            Log::info('ProcessOffboardingLwd: last working date not over yet, skipping', [
                'offboarding_id' => $offboarding->id,
                'lwd' => $lwd->toDateString(),
            ]);

            return;
        }

        if ($offboarding->lwd_processed_at) {
            return;
        }

        $employee = User::withTrashed()->find($offboarding->employee_id);
        if (! $employee) {
            Log::warning('ProcessOffboardingLwd: employee not found', [
                'offboarding_id' => $offboarding->id,
                'employee_id' => $offboarding->employee_id,
            ]);

            return;
        }

        $employeeId = $employee->employee_id;
        $lwdStr = $lwd->toDateString();

        Log::info('ProcessOffboardingLwd: processing', [
            'offboarding_id' => $offboarding->id,
            'employee' => $employee->name,
            'employee_id' => $employeeId,
            'lwd' => $lwdStr,
            'reason' => $offboarding->reason,
        ]);

        DB::transaction(function () use ($employeeId, $lwd, $lwdStr) {
            // 1. End shift assignments — set effective_to to LWD
            ShiftAssignment::where('scope_type', 'user')
                ->where('scope_id', $employeeId)
                ->where(function ($q) use ($lwd) {
                    $q->whereNull('effective_to')
                        ->orWhere('effective_to', '>', $lwd);
                })
                ->update(['effective_to' => $lwdStr]);

            // 2. Delete all future roster days after LWD
            $deleted = RosterDay::where('user_id', $employeeId)
                ->where('date', '>', $lwdStr)
                ->delete();

            Log::info("ProcessOffboardingLwd: cleared {$deleted} future roster days for {$employeeId}");
        });

        // 3. Revoke all sessions and API tokens. A failure propagates so the job
        // retries and the row is NOT marked processed (the daily sweep re-queues it).
        $deviceAuth->terminateUserAccess($employee);
        Log::info("ProcessOffboardingLwd: revoked sessions/tokens for {$employeeId}");

        // 4. Queue biometric DELETE_USER on all active devices
        $this->queueBiometricDeletion($employee);

        // 5. Notify relevant people
        $this->sendNotifications($offboarding, $employee);

        // 6. Mark processed (idempotency) and move pending -> in_progress
        $offboarding->lwd_processed_at = now();
        if ($offboarding->status === Offboarding::STATUS_PENDING) {
            $offboarding->status = Offboarding::STATUS_IN_PROGRESS;
        }
        $offboarding->save();

        Log::info('ProcessOffboardingLwd: completed', [
            'offboarding_id' => $offboarding->id,
            'employee_id' => $employeeId,
        ]);
    }

    private function queueBiometricDeletion(User $employee): void
    {
        try {
            $devices = BiometricDevice::where('is_active', true)->get();

            foreach ($devices as $device) {
                BiometricDeviceCommand::create([
                    'biometric_device_id' => $device->id,
                    'command_type' => 'DELETE_USER',
                    'payload' => ['pin' => (string) $employee->employee_id],
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                ]);
            }

            Log::info("ProcessOffboardingLwd: queued DELETE_USER on {$devices->count()} devices for {$employee->employee_id}");
        } catch (\Throwable $e) {
            Log::error('ProcessOffboardingLwd: failed to queue biometric deletion', [
                'employee_id' => $employee->employee_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            Offboarding::REASON_ABSCONDED => 'Job Abandonment (Absconded)',
            Offboarding::REASON_RESIGNATION_WITHOUT_NOTICE => 'Resignation Without Notice',
            Offboarding::REASON_RESIGNATION => 'Resignation',
            Offboarding::REASON_TERMINATION => 'Termination',
            Offboarding::REASON_RETIREMENT => 'Retirement',
            Offboarding::REASON_END_CONTRACT => 'End of Contract',
            default => ucfirst($reason),
        };
    }

    private function sendNotifications(Offboarding $offboarding, User $employee): void
    {
        try {
            $reasonLabel = self::reasonLabel($offboarding->reason);

            // Collect recipients: HR managers, employee's manager, IT admins
            $recipients = User::where(function ($q) use ($employee) {
                $q->whereHas('roles', fn ($rq) => $rq->whereIn('name', ['HR Manager', 'Super Administrator']));
                if ($employee->report_to) {
                    $q->orWhere('employee_id', $employee->report_to);
                }
            })
                ->whereNull('deleted_at')
                ->where('employee_id', '!=', $employee->employee_id)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            // Use database notification channel
            foreach ($recipients as $recipient) {
                $recipient->notify(new OffboardingInitiatedNotification(
                    $employee->name,
                    $reasonLabel,
                    $offboarding->last_working_date->toDateString(),
                    $offboarding->id,
                ));
            }
        } catch (\Throwable $e) {
            Log::error('ProcessOffboardingLwd: notification delivery failed', [
                'offboarding_id' => $offboarding->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
