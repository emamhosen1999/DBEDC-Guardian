<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

class DailyOvertimeEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $o = $policy->overtime();

        return is_array($o) && (int) ($o['daily_threshold_minutes'] ?? 0) > 0;
    }

    public function evaluate(DayContext $ctx): void
    {
        $o = $ctx->policy->overtime();
        $dailyThreshold = (int) ($o['daily_threshold_minutes'] ?? 0);
        $dtThreshold = (int) ($o['double_time_threshold_minutes'] ?? 0); // 0 = no double-time

        if ($dailyThreshold <= 0) {
            return;
        }

        $worked = max(0, $ctx->workedMinutes);
        $regular = min($worked, $dailyThreshold);
        $overage = max(0, $worked - $dailyThreshold);

        if ($dtThreshold > $dailyThreshold) {
            $otBand = max(0, $dtThreshold - $dailyThreshold);
            $ot = min($overage, $otBand);
            $doubleTime = max(0, $worked - $dtThreshold);
        } else {
            $ot = $overage;
            $doubleTime = 0;
        }

        $ctx->regularMinutes = $regular;
        $ctx->otMinutes = $ot;
        $ctx->doubleTimeMinutes = $doubleTime;

        if (! empty($o['require_preauthorization']) && ($ot + $doubleTime) > 0) {
            $ctx->flags[] = 'ot_needs_preauth';
        }
    }
}
