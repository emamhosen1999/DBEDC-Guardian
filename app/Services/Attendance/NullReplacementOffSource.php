<?php

namespace App\Services\Attendance;

use App\Services\Attendance\Contracts\ReplacementOffSource;
use Carbon\CarbonInterface;

/** No replacement ledger exists yet (next batch), so no replacement offs are reported. */
class NullReplacementOffSource implements ReplacementOffSource
{
    public function between(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }
}
