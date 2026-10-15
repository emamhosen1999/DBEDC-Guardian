<?php

namespace App\Services\Attendance\Contracts;

use App\Services\Attendance\NullReplacementOffSource;
use Carbon\CarbonInterface;

/**
 * Where the register learns which days off were taken IN LIEU of a rest day worked.
 *
 * Policy: only a worked weekly-off (rostered rest day) can be replaced; the replacement day is simply the
 * weekly-off symbol '/', and the register's Remark column carries "Replacement off <date> for rest day worked <date>".
 * The claim and replacement workflow (and its ledger) is the next batch: until it binds a real source, the
 * default {@see NullReplacementOffSource} reports none.
 */
interface ReplacementOffSource
{
    /**
     * @param  list<string>  $employeeIds  employee ids (users.employee_id)
     * @return array<string, list<array{off: string, worked: string}>> employee id => replacement offs (Y-m-d dates) falling in the range
     */
    public function between(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array;
}
