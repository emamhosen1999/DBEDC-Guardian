<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\Carbon;

class RoundingEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $r = $policy->rounding();

        return is_array($r) && ($r['strategy'] ?? 'none') !== 'none';
    }

    public function evaluate(DayContext $ctx): void
    {
        $r = $ctx->policy->rounding();
        $unit = max(1, (int) ($r['unit_minutes'] ?? ($r['strategy'] === 'seven_minute' ? 15 : 15)));
        $dir = $r['direction'] ?? 'nearest';

        if ($ctx->firstIn) {
            $ctx->firstIn = $this->round($ctx->firstIn, $unit, $dir);
        }
        if ($ctx->lastOut) {
            $ctx->lastOut = $this->round($ctx->lastOut, $unit, $dir);
        }
    }

    private function round(Carbon $t, int $unit, string $dir): Carbon
    {
        $minutes = $t->hour * 60 + $t->minute;
        $rounded = match ($dir) {
            'up' => (int) (ceil($minutes / $unit) * $unit),
            'down' => (int) (floor($minutes / $unit) * $unit),
            default => (int) (round($minutes / $unit) * $unit),
        };

        return $t->copy()->startOfDay()->addMinutes($rounded);
    }
}
