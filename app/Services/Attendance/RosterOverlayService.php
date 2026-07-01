<?php

namespace App\Services\Attendance;

use App\Models\HRM\Leave;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Read-only overlay for the roster PLANNING surfaces.
 *
 * Merges two already-authoritative sources onto roster cells at read time:
 *  - approved/pending leave (leaves table), and
 *  - active holidays (HolidayService::forRange).
 *
 * It never writes roster_days and never touches the schedule resolver /
 * attendance engine. Approved leave out-ranks a same-date pending row.
 */
class RosterOverlayService
{
    public function __construct(private readonly HolidayService $holidays) {}

    /**
     * @param  int[]  $userIds
     * @return array{leave: array<int, array<string, array{type:string,fraction:float,session:?string,status:string}>>, holidays: array<string,string>}
     */
    public function forRange(array $userIds, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        return [
            'leave' => $this->buildLeaveOverlay($userIds, $start, $end),
            'holidays' => $this->buildHolidayOverlay($start, $end),
        ];
    }

    private function buildLeaveOverlay(array $userIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if (empty($userIds)) {
            return [];
        }

        $leaves = Leave::with('leaveSetting:id,type,symbol')
            ->whereIn('user_id', $userIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('from_date', '<=', $end->toDateString())
            ->whereDate('to_date', '>=', $start->toDateString())
            ->get();

        $overlay = [];
        foreach ($leaves as $leave) {
            $type = $leave->leaveSetting?->symbol ?? $leave->leaveSetting?->type ?? 'Leave';
            $fraction = $leave->is_half_day ? 0.5 : 1.0;
            $session = $leave->is_half_day ? $leave->half_day_session : null;

            foreach ($this->intersectDates($leave->from_date, $leave->to_date, $start, $end) as $key) {
                // Never let a pending row overwrite an already-recorded approved one.
                if (($overlay[$leave->user_id][$key]['status'] ?? null) === 'approved') {
                    continue;
                }
                $overlay[$leave->user_id][$key] = [
                    'type' => $type,
                    'fraction' => $fraction,
                    'session' => $session,
                    'status' => $leave->status,
                ];
            }
        }

        return $overlay;
    }

    private function buildHolidayOverlay(CarbonInterface $start, CarbonInterface $end): array
    {
        $overlay = [];
        foreach ($this->holidays->forRange($start, $end->copy()->endOfDay()) as $holiday) {
            foreach ($this->intersectDates($holiday->from_date, $holiday->to_date, $start, $end) as $key) {
                $overlay[$key] = $holiday->title;
            }
        }

        return $overlay;
    }

    /**
     * Dates (Y-m-d) shared by [rangeFrom,rangeTo] and the query window [start,end].
     *
     * @return string[]
     */
    private function intersectDates($rangeFrom, $rangeTo, CarbonInterface $start, CarbonInterface $end): array
    {
        $rf = Carbon::parse($rangeFrom)->startOfDay();
        $rt = Carbon::parse($rangeTo)->startOfDay();
        $cursor = $rf->greaterThan($start) ? $rf->copy() : $start->copy();
        $limit = $rt->lessThan($end) ? $rt->copy() : $end->copy();

        $dates = [];
        for ($d = $cursor; $d->lte($limit); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }
}
