<?php

namespace App\Services\Attendance\Contracts;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

interface RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool;

    public function evaluate(DayContext $ctx): void;
}
