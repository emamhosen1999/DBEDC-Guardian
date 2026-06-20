<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

class GraceTiersEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $t = $policy->graceTiers();

        return is_array($t) && ! empty($t['late']);
    }

    public function evaluate(DayContext $ctx): void
    {
        if (! $ctx->firstIn || ! $ctx->shift->isWorkingDay) {
            return;
        }
        $lateMin = max(0, (int) round($ctx->shift->start->diffInMinutes($ctx->firstIn, false)));
        if ($ctx->firstIn->lessThanOrEqualTo($ctx->shift->start)) {
            $lateMin = 0;
        }
        foreach ($ctx->policy->graceTiers()['late'] as $band) {
            if ($lateMin <= (int) $band['upto_minutes']) {
                if ($band['outcome'] === 'late') {
                    $ctx->flags[] = 'tier_late';
                } elseif ($band['outcome'] === 'half_day') {
                    $ctx->flags[] = 'tier_half_day';
                } elseif ($band['outcome'] === 'present') {
                    $ctx->flags[] = 'tier_present';
                }

                return;
            }
        }
    }
}
