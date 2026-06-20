<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendancePolicy;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Read-only service that previews the impact of a draft policy.
 *
 * Iterates each user-day in the given range, resolves attendance status
 * under the current (neutral) profile and under the draft profile, and
 * returns a diff summary. Writes nothing to the database.
 */
class PolicySimulationService
{
    private const MAX_SAMPLES = 50;

    public function __construct(
        private readonly ScheduleResolver $scheduleResolver,
        private readonly AttendanceStatusService $statusService,
    ) {}

    /**
     * @param  AttendancePolicy  $draft  An unsaved or saved policy model (id not required).
     * @param  array<int>  $userIds
     * @param  string  $from  'Y-m-d'
     * @param  string  $to    'Y-m-d'
     * @return array{days: int, changed: int, samples: list<array{user_id: int, date: string, before_status: string, after_status: string}>}
     */
    public function simulate(AttendancePolicy $draft, array $userIds, string $from, string $to): array
    {
        $draftProfile = new PolicyProfile(
            $draft->punch_strictness ?? 'warn',
            (int) ($draft->outside_window_minutes ?? 120),
            $draft->grace_tiers,
            $draft->rounding,
        );

        $period = CarbonPeriod::create($from, $to);

        $days = 0;
        $changed = 0;
        $samples = [];

        foreach ($userIds as $userId) {
            foreach ($period as $date) {
                $shift = $this->scheduleResolver->resolve($userId, $date);

                $punches = Attendance::where('user_id', $userId)
                    ->whereDate('date', $date->toDateString())
                    ->where('policy_status', '!=', 'rejected')
                    ->orderBy('punchin')
                    ->get();

                $before = $this->statusService->resolve($punches, $shift);
                $after = $this->statusService->resolve($punches, $shift, policy: $draftProfile);

                $days++;

                if ($before->status !== $after->status) {
                    $changed++;
                    if (count($samples) < self::MAX_SAMPLES) {
                        $samples[] = [
                            'user_id' => $userId,
                            'date' => $date->toDateString(),
                            'before_status' => $before->status,
                            'after_status' => $after->status,
                        ];
                    }
                }
            }
        }

        return [
            'days' => $days,
            'changed' => $changed,
            'samples' => $samples,
        ];
    }
}
