<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

class BreaksEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $b = $policy->breaks();

        return is_array($b) && (int) ($b['unpaid_meal_minutes'] ?? 0) > 0;
    }

    public function evaluate(DayContext $ctx): void
    {
        $b = $ctx->policy->breaks();
        $meal = (int) ($b['unpaid_meal_minutes'] ?? 0);
        $threshold = (int) ($b['meal_threshold_minutes'] ?? 360);

        if ($meal <= 0 || $ctx->workedMinutes < $threshold) {
            return;
        }

        // GAP I — never double-deduct: break_out/break_in punches already excluded the break
        // from workedMinutes, so the break ALREADY TAKEN = (span firstIn..lastOut) - workedMinutes.
        // Auto-deduct only the SHORTFALL between the required unpaid meal and what was taken.
        $breakTaken = 0;
        if ($ctx->firstIn && $ctx->lastOut) {
            $span = max(0, (int) round($ctx->firstIn->diffInMinutes($ctx->lastOut)));
            $breakTaken = max(0, $span - $ctx->workedMinutes);
        }

        $deduct = min(max(0, $meal - $breakTaken), $ctx->workedMinutes);
        if ($deduct <= 0) {
            return; // worker already took at least the required unpaid meal
        }

        $ctx->workedMinutes -= $deduct;
        $ctx->breakDeductedMinutes += $deduct;
        $ctx->flags[] = 'meal_deducted';
    }
}
