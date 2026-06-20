<?php

namespace App\Services\Attendance;

use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\CarbonInterface;

class PunchPolicyGuard
{
    public function __construct(
        private readonly PolicyResolver $policies,
        private readonly ScheduleResolver $schedules,
    ) {}

    public function assess(int $userId, CarbonInterface $punchMoment): array
    {
        $policy = $this->policies->resolve($userId, $punchMoment);
        $accepted = ['policy_status' => 'accepted', 'needs_approval' => false, 'reason' => null, 'warning' => null];

        if ($policy->strictness() === 'warn' && $policy->isNeutral()) {
            return $accepted;
        }

        $shift = $this->schedules->resolve($userId, $punchMoment);
        $outOfWindow = false;
        if ($shift->isWorkingDay) {
            $earliest = $shift->start->copy()->subMinutes($policy->outsideWindowMinutes());
            $latest = $shift->end->copy()->addMinutes($policy->outsideWindowMinutes());
            $outOfWindow = $punchMoment->lessThan($earliest) || $punchMoment->greaterThan($latest);
        }

        return match ($policy->strictness()) {
            'flag' => ['policy_status' => 'provisional', 'needs_approval' => true, 'reason' => 'flagged by policy', 'warning' => null],
            'restrict' => $outOfWindow
                ? ['policy_status' => 'provisional', 'needs_approval' => true, 'reason' => 'outside permitted window', 'warning' => null]
                : $accepted,
            default => $outOfWindow
                ? array_merge($accepted, ['warning' => 'Punch is outside your shift window.'])
                : $accepted,
        };
    }
}
