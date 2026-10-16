<?php

namespace App\Services\Attendance;

use App\Models\HRM\ReplacementLeaveRequest;
use App\Services\Attendance\Contracts\ReplacementOffSource;
use Carbon\CarbonInterface;

/** The register's Remark source: approved replacement days off in the range, with the rest-day work they replace. */
class LedgerReplacementOffSource implements ReplacementOffSource
{
    public function between(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $out = [];
        ReplacementLeaveRequest::query()->whereIn('user_id', $employeeIds)->where('status', 'approved')
            ->whereBetween('replacement_date', [$from->toDateString(), $to->toDateString()])->orderBy('replacement_date')->get()
            ->each(function (ReplacementLeaveRequest $r) use (&$out) {
                foreach ($r->allocations ?? [] as $a) {
                    $out[(string) $r->user_id][] = ['off' => $r->replacement_date->toDateString(), 'worked' => (string) $a['work_date']];
                }
            });

        return $out;
    }
}
