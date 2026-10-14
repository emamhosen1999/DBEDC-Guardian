<?php

namespace App\Services\Org;

use App\Models\EmployeeReportingLine;
use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of `users.report_to`. record() is called from the User observer on every change; reconcile() catches any write
 * that bypassed model events (a query-builder update, an import) by comparing the current lines with the open history rows.
 * A change closes the open row the day before and opens a new one; two changes on the same day correct the row in place.
 */
class ReportingLineService
{
    public function record(string $employeeId, ?string $reportsTo, ?string $changedBy = null, ?string $date = null): void
    {
        if (! Schema::hasTable('employee_reporting_lines')) {
            return;
        }
        $date ??= ChainageResolver::today();
        $reportsTo = $reportsTo === '' ? null : $reportsTo;
        $open = EmployeeReportingLine::query()->where('employee_id', $employeeId)->whereNull('valid_to')->orderByDesc('id')->first();
        if ($open !== null && $open->reports_to === $reportsTo) {
            return;
        }
        DB::transaction(function () use ($open, $employeeId, $reportsTo, $changedBy, $date): void {
            if ($open !== null) {
                if ($open->valid_from !== null && $open->valid_from->toDateString() >= $date) {
                    // opened today (or later): a same-day correction, not a new period
                    if ($reportsTo === null) {
                        $open->valid_to = $open->valid_from->toDateString();
                        $open->save();
                    } else {
                        $open->reports_to = $reportsTo;
                        $open->changed_by = $changedBy;
                        $open->save();
                    }

                    return;
                }
                $open->valid_to = date('Y-m-d', strtotime($date.' -1 day'));
                $open->save();
            }
            if ($reportsTo !== null) {
                EmployeeReportingLine::query()->create(['employee_id' => $employeeId, 'reports_to' => $reportsTo, 'valid_from' => $open === null ? null : $date, 'valid_to' => null, 'changed_by' => $changedBy]);
            }
        });
    }

    /**
     * Bring the history in line with users.report_to for anyone whose line changed without an event. Returns how many.
     */
    public function reconcile(): int
    {
        if (! Schema::hasTable('employee_reporting_lines')) {
            return 0;
        }
        $open = EmployeeReportingLine::query()->whereNull('valid_to')->pluck('reports_to', 'employee_id');
        $changed = 0;
        foreach (DB::table('users')->whereNull('deleted_at')->get(['employee_id', 'report_to']) as $u) {
            $now = ($u->report_to === '' ? null : $u->report_to);
            $then = $open[$u->employee_id] ?? null;
            if ((string) $now !== (string) $then) {
                $this->record((string) $u->employee_id, $now, 'system:reconcile');
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * employee_id => reports_to on a date, from the history. An employee with no line on that date is absent.
     *
     * @return array<string, string>
     */
    public function mapAsOf(string $date): array
    {
        return EmployeeReportingLine::query()
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderBy('id')->pluck('reports_to', 'employee_id')->map(fn ($v) => (string) $v)->all();
    }

    /** The day recording began (the earliest row written: the seed's creation date), or null when there is no history yet. */
    public function recordedSince(): ?string
    {
        if (! Schema::hasTable('employee_reporting_lines')) {
            return null;
        }
        $first = EmployeeReportingLine::query()->min('created_at');

        return $first === null ? null : substr((string) $first, 0, 10);
    }

    /** @return Collection<int, EmployeeReportingLine> */
    public function historyOf(User|string $employee)
    {
        return EmployeeReportingLine::query()->where('employee_id', $employee instanceof User ? $employee->employee_id : $employee)->orderByRaw('valid_from IS NULL DESC')->orderBy('valid_from')->get();
    }
}
