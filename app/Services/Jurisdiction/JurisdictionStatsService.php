<?php

namespace App\Services\Jurisdiction;

use App\Models\Jurisdiction;
use App\Models\JurisdictionMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Real numbers per jurisdiction, aggregated in SQL (a handful of grouped queries for ALL jurisdictions - no N+1).
 *
 * A work belongs to the jurisdiction it was attributed to on its own date (daily_works.jurisdiction_id: the one holding its
 * start chainage under the boundaries valid that day). "Completed" is the two spellings the register carries: completed and complete.
 * Works with no jurisdiction on their date (unreadable chainage, or a gap) are counted in `unplaced`.
 */
class JurisdictionStatsService
{
    public const COMPLETED = ['completed', 'complete'];

    /** Not finished and not dead: the in-charge still has to act on them. */
    public const CLOSED = ['completed', 'complete', 'rejected'];

    /**
     * @param  Collection<int, Jurisdiction>  $jurisdictions
     * @return array{period: array, unplaced: int, by_jurisdiction: array<int, array<string, mixed>>}
     */
    public function forPeriod(Collection $jurisdictions, JurisdictionPeriod $period): array
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $ids = $jurisdictions->pluck('id')->all();

        // Grouped by the jurisdiction each work was ATTRIBUTED to on its own date (daily_works.jurisdiction_id), so a
        // boundary moved later never rewrites a past period. Indexed on (jurisdiction_id, date).
        $base = fn () => DB::table('daily_works as dw')
            ->whereIn('dw.jurisdiction_id', $ids)->whereNull('dw.deleted_at')
            ->whereBetween('dw.date', [$from, $to]);

        $status = $base()->selectRaw('dw.jurisdiction_id as jid, dw.status as status, COUNT(*) as n')->groupBy('dw.jurisdiction_id', 'dw.status')->get();
        $type = $base()->selectRaw('dw.jurisdiction_id as jid, dw.type as type, COUNT(*) as n')->groupBy('dw.jurisdiction_id', 'dw.type')->get();
        $rfis = $base()->selectRaw('dw.jurisdiction_id as jid, COUNT(DISTINCT dw.number) as n')->groupBy('dw.jurisdiction_id')->pluck('n', 'jid');
        $objections = $base()->join('daily_work_objection as dwo', 'dwo.daily_work_id', '=', 'dw.id')
            ->selectRaw('dw.jurisdiction_id as jid, COUNT(DISTINCT dwo.rfi_objection_id) as n')->groupBy('dw.jurisdiction_id')->pluck('n', 'jid');
        $last = DB::table('daily_works as dw')->whereIn('dw.jurisdiction_id', $ids)->whereNull('dw.deleted_at')->where('dw.date', '<=', $to)
            ->selectRaw('dw.jurisdiction_id as jid, MAX(dw.date) as last_date')->groupBy('dw.jurisdiction_id')->pluck('last_date', 'jid');

        // Per person in the period: works they are in charge of, and works assigned to them that someone else is in charge of.
        $asIncharge = $base()->selectRaw('dw.jurisdiction_id as jid, dw.incharge as eid, COUNT(*) as n')->groupBy('dw.jurisdiction_id', 'dw.incharge')->get();
        $asAssigned = $base()->whereNotNull('dw.assigned')->whereColumn('dw.assigned', '<>', 'dw.incharge')
            ->selectRaw('dw.jurisdiction_id as jid, dw.assigned as eid, COUNT(*) as n')->groupBy('dw.jurisdiction_id', 'dw.assigned')->get();

        $out = [];
        foreach ($jurisdictions as $j) {
            $byStatus = $status->where('jid', $j->id)->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
            $total = array_sum($byStatus);
            $completed = array_sum(array_intersect_key($byStatus, array_flip(self::COMPLETED)));
            $people = [];
            foreach ([$asIncharge, $asAssigned] as $rows) {
                foreach ($rows->where('jid', $j->id) as $r) {
                    $people[(string) $r->eid] = ($people[(string) $r->eid] ?? 0) + (int) $r->n;
                }
            }
            $out[$j->id] = [
                'works' => $total,
                'completed' => $completed,
                'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : null,
                'by_status' => $byStatus,
                'by_type' => $type->where('jid', $j->id)->pluck('n', 'type')->map(fn ($n): int => (int) $n)->all(),
                'rfis' => (int) ($rfis[$j->id] ?? 0),
                'objections' => (int) ($objections[$j->id] ?? 0),
                'last_activity' => isset($last[$j->id]) ? substr((string) $last[$j->id], 0, 10) : null,
                'works_by_employee' => $people,
            ];
        }

        $unplaced = (int) DB::table('daily_works')->whereNull('deleted_at')->whereNull('jurisdiction_id')->whereBetween('date', [$from, $to])->count();

        return ['period' => $period->toArray(), 'unplaced' => $unplaced, 'by_jurisdiction' => $out];
    }

    /**
     * Open works (not completed/rejected) each employee is in charge of or assigned to, over ALL jurisdictions: the
     * in-charge's whole workload, not only the part inside one phase.
     *
     * @param  array<int, string>  $employeeIds
     * @return array<string, int>
     */
    public function openWorkload(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $load = [];
        $incharge = DB::table('daily_works')->whereNull('deleted_at')->whereIn('incharge', $employeeIds)->whereNotIn('status', self::CLOSED)
            ->selectRaw('incharge as eid, COUNT(*) as n')->groupBy('incharge')->get();
        $assigned = DB::table('daily_works')->whereNull('deleted_at')->whereIn('assigned', $employeeIds)->whereColumn('assigned', '<>', 'incharge')->whereNotIn('status', self::CLOSED)
            ->selectRaw('assigned as eid, COUNT(*) as n')->groupBy('assigned')->get();
        foreach ([$incharge, $assigned] as $rows) {
            foreach ($rows as $r) {
                $load[(string) $r->eid] = ($load[(string) $r->eid] ?? 0) + (int) $r->n;
            }
        }

        return $load;
    }

    /**
     * Who has been working inside this jurisdiction: employees who were `assigned` or `incharge` on works in its range
     * in the last 90 days, ranked by count, minus whoever is already on the team. A SUGGESTION only - nothing here writes.
     *
     * @return array<int, array{employee_id: string, works: int, as_incharge: int, as_assigned: int}>
     */
    public function suggestions(Jurisdiction $jurisdiction, int $days = 90, int $limit = 12): array
    {
        $since = now()->subDays($days)->toDateString();
        $base = fn () => DB::table('daily_works')->whereNull('deleted_at')->where('jurisdiction_id', $jurisdiction->id)->where('date', '>=', $since);

        $score = [];
        foreach ($base()->selectRaw('incharge as eid, COUNT(*) as n')->groupBy('incharge')->get() as $r) {
            $score[(string) $r->eid]['as_incharge'] = (int) $r->n;
        }
        foreach ($base()->whereNotNull('assigned')->selectRaw('assigned as eid, COUNT(*) as n')->groupBy('assigned')->get() as $r) {
            $score[(string) $r->eid]['as_assigned'] = (int) $r->n;
        }
        $onTeam = JurisdictionMember::query()->where('jurisdiction_id', $jurisdiction->id)->whereNull('effective_to')->pluck('employee_id')->map(fn ($id): string => (string) $id)->all();
        // Only people who can still be added: active employees.
        $active = DB::table('users')->whereIn('employee_id', array_keys($score))->where('is_active', true)->whereNull('deleted_at')->pluck('employee_id')->map(fn ($id): string => (string) $id)->all();

        $rows = [];
        foreach ($score as $eid => $s) {
            if (in_array($eid, $onTeam, true) || ! in_array($eid, $active, true)) {
                continue;
            }
            $rows[] = ['employee_id' => $eid, 'as_incharge' => $s['as_incharge'] ?? 0, 'as_assigned' => $s['as_assigned'] ?? 0, 'works' => ($s['as_incharge'] ?? 0) + ($s['as_assigned'] ?? 0)];
        }
        usort($rows, fn (array $a, array $b): int => [$b['works'], $a['employee_id']] <=> [$a['works'], $b['employee_id']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * The in-charge split by tenure: for each in-charge row overlapping the period, the works dated inside that tenure
     * (and the period) that were attributed to the jurisdiction, and how many of them were recorded under that person.
     *
     * @return array<int, array{employee_id: string, from: ?string, to: ?string, works: int, recorded_under: int, completed: int}>
     */
    public function tenures(Jurisdiction $jurisdiction, JurisdictionPeriod $period): array
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $rows = JurisdictionMember::query()->where('jurisdiction_id', $jurisdiction->id)->where('role', JurisdictionMember::IN_CHARGE)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to))
            ->orderByRaw('effective_from IS NULL DESC')->orderBy('effective_from')->get();

        $out = [];
        foreach ($rows as $r) {
            $lo = max($from, $r->effective_from?->toDateString() ?? $from);
            $hi = min($to, $r->effective_to?->toDateString() ?? $to);
            $q = fn () => DB::table('daily_works')->whereNull('deleted_at')->where('jurisdiction_id', $jurisdiction->id)->whereBetween('date', [$lo, $hi]);
            $works = (int) $q()->count();
            $out[] = [
                'employee_id' => (string) $r->employee_id, 'from' => $r->effective_from?->toDateString(), 'to' => $r->effective_to?->toDateString(),
                'works' => $works,
                'recorded_under' => (int) $q()->where('incharge', $r->employee_id)->count(),
                'completed' => (int) $q()->whereIn('status', self::COMPLETED)->count(),
            ];
        }

        return $out;
    }
}
