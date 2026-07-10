<?php

namespace App\Services\Leave;

use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Compensatory-off (TOIL) banking. Scans attendance for days an employee
 * actually worked (has a punch-in) that were NOT owed work:
 *   - a roster off-day (weekly off per ScheduleResolver),
 *   - a holiday,
 *   - a day covered by their own approved full-day leave.
 * Each such day banks +1.0 day into the comp-off leave type's ledger.
 * Idempotent per (user, date) via ledger idempotency keys — safe to re-scan.
 * Consumption is a normal leave request of the comp-off type.
 */
class CompOffService
{
    public function __construct(
        private LeaveLedgerService $ledger,
        private ScheduleResolver $scheduleResolver,
        private HolidayService $holidayService,
    ) {}

    /**
     * @return int number of grants posted
     */
    public function scan(CarbonInterface $from, CarbonInterface $to, ?int $userId = null, bool $dryRun = false): int
    {
        $type = LeaveSetting::where('is_comp_off', true)->first();
        if (! $type) {
            Log::info('Comp-off scan skipped: no leave type flagged is_comp_off.');

            return 0;
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        if ($end->lessThan($start)) {
            return 0;
        }

        $holidays = $this->holidayService->forRange($start, $end->copy()->endOfDay());

        // Worked days: any attendance row with a punch-in inside the window.
        $worked = Attendance::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('punchin')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get(['user_id', 'date'])
            ->unique(fn ($row) => $row->user_id.'|'.Carbon::parse($row->date)->toDateString());

        $posted = 0;

        foreach ($worked as $row) {
            $date = Carbon::parse($row->date)->startOfDay();
            $uid = (int) $row->user_id;

            $isHoliday = $holidays->contains(fn ($h) => $date->between(
                Carbon::parse($h->from_date)->startOfDay(),
                Carbon::parse($h->to_date)->endOfDay()
            ));

            $isOffDay = ! $this->scheduleResolver->resolve($uid, $date)->isWorkingDay;

            // whereDate: from/to are DATETIME columns — a raw string compare
            // ('Y-m-d H:i:s' vs 'Y-m-d') breaks on SQLite.
            $onFullDayLeave = ! $isHoliday && ! $isOffDay && Leave::query()
                ->where('user_id', $uid)
                ->where('status', 'approved')
                ->where('is_half_day', false)
                ->whereDate('from_date', '<=', $date->toDateString())
                ->whereDate('to_date', '>=', $date->toDateString())
                ->exists();

            if (! $isHoliday && ! $isOffDay && ! $onFullDayLeave) {
                continue; // a normal owed working day
            }

            $key = "co:{$uid}:{$date->format('Ymd')}";
            if ($this->alreadyGranted($key)) {
                continue;
            }

            if (! $dryRun) {
                $source = $isHoliday ? 'holiday' : ($isOffDay ? 'off-day' : 'leave-day');
                $this->ledger->post(
                    $uid, (int) $type->id, (int) $date->year, 'comp_off', 1.0,
                    'attendance', null, null, "Worked on {$source} {$date->toDateString()}", $key
                );
            }
            $posted++;
        }

        return $posted;
    }

    private function alreadyGranted(string $key): bool
    {
        return \App\Models\HRM\LeaveLedger::where('idempotency_key', $key)->exists();
    }
}
