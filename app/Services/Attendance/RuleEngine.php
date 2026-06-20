<?php

namespace App\Services\Attendance;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;

class RuleEngine
{
    /** @var RuleEvaluator[] */
    private array $evaluators;

    public function __construct(RuleEvaluator ...$evaluators)
    {
        $this->evaluators = $evaluators;
    }

    public function apply(DayContext $ctx): void
    {
        foreach ($this->evaluators as $evaluator) {
            if ($evaluator->supports($ctx->policy)) {
                $evaluator->evaluate($ctx);
            }
        }
    }
}
