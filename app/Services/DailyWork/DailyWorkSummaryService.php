<?php

namespace App\Services\DailyWork;

use App\Models\Jurisdiction;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The summary tab: every number is a database aggregate over the viewer's own scope and the same filters as the register
 * (never a collection loaded into memory). The default period is the last 12 months; when that period holds nothing the
 * answer says when the latest record is dated and how many works exist, so a quiet period is never shown as a wall of zeros.
 */
class DailyWorkSummaryService
{
    public const PERIODS = ['30d' => 30, '90d' => 90, '365d' => 365, 'all' => null];

    public function __construct(private readonly DailyWorkRegisterService $register) {}

    /** @return array<string, mixed> */
    public function summary(User $viewer, DailyWorkFilters $filters, string $period = '365d'): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : '365d';
        $explicit = $filters->dateFrom !== null || $filters->dateTo !== null;
        $days = self::PERIODS[$period];
        if (! $explicit && $days !== null) {
            $filters = new DailyWorkFilters(
                q: $filters->q, status: $filters->status, type: $filters->type, side: $filters->side, incharge: $filters->incharge, assigned: $filters->assigned,
                jurisdiction: $filters->jurisdiction, dateFrom: now()->subDays($days)->toDateString(), dateTo: now()->toDateString(), chainFrom: $filters->chainFrom,
                chainTo: $filters->chainTo, objections: $filters->objections, quality: $filters->quality, trashed: false, importBatch: $filters->importBatch,
            );
        }
        $base = fn (): Builder => $filters->apply($this->register->scopedQuery($viewer));

        $t = $base()->toBase()->selectRaw(
            "count(*) as works,
            sum(case when status in ('completed','complete') then 1 else 0 end) as completed,
            sum(case when status in ('completed','complete') and inspection_result = 'pass' then 1 else 0 end) as passed,
            sum(case when status in ('completed','complete') and inspection_result = 'fail' then 1 else 0 end) as failed,
            sum(case when status in ('completed','complete') and inspection_result = 'pass' and coalesce(resubmission_count,0) = 0 then 1 else 0 end) as first_time_pass,
            sum(case when status in ('new','in-progress','resubmission','pending','emergency','rejected') then 1 else 0 end) as open_works,
            sum(case when coalesce(resubmission_count,0) > 0 then 1 else 0 end) as resubmitted,
            sum(case when start_m is null then 1 else 0 end) as unplaced,
            sum(case when jurisdiction_id is null then 1 else 0 end) as unattributed,
            min(date) as first_date, max(date) as last_date"
        )->first();

        $works = (int) ($t->works ?? 0);
        $completed = (int) ($t->completed ?? 0);
        $out = [
            'period' => ['key' => $explicit ? 'custom' : $period, 'from' => $filters->dateFrom, 'to' => $filters->dateTo, 'first' => $t->first_date ? substr((string) $t->first_date, 0, 10) : null, 'last' => $t->last_date ? substr((string) $t->last_date, 0, 10) : null],
            'totals' => [
                'works' => $works, 'completed' => $completed, 'passed' => (int) ($t->passed ?? 0), 'failed' => (int) ($t->failed ?? 0), 'open' => (int) ($t->open_works ?? 0),
                'resubmitted' => (int) ($t->resubmitted ?? 0), 'unplaced' => (int) ($t->unplaced ?? 0), 'unattributed' => (int) ($t->unattributed ?? 0),
                'completion_rate' => $works > 0 ? round($completed / $works * 100, 1) : null,
                'first_time_pass_rate' => $completed > 0 ? round((int) $t->first_time_pass / $completed * 100, 1) : null,
                'pass_rate' => $completed > 0 ? round((int) $t->passed / $completed * 100, 1) : null,
                'with_objections' => $works > 0 ? (clone $base())->whereHas('objections', fn ($o) => $o->whereIn('rfi_objections.status', RfiObjection::$activeStatuses))->count() : 0,
            ],
            'empty' => null,
        ];
        if ($works === 0) {
            $scoped = $this->register->scopedQuery($viewer);
            $latest = (clone $scoped)->max('date');
            $out['empty'] = ['scoped_total' => (clone $scoped)->count(), 'latest_work_date' => $latest ? substr((string) $latest, 0, 10) : null];

            return $out + ['by_status' => [], 'by_type' => [], 'trend' => ['grain' => 'day', 'points' => []], 'by_incharge' => [], 'by_jurisdiction' => [], 'turnaround' => ['avg_days' => null, 'samples' => 0, 'fallback_samples' => 0]];
        }

        $out['by_status'] = $base()->toBase()->selectRaw("case when status = 'complete' then 'completed' else status end as k, count(*) as n")->groupBy('k')->orderByDesc('n')->get()
            ->map(fn ($r): array => ['key' => $r->k, 'count' => (int) $r->n])->all();
        $out['by_type'] = $base()->toBase()->selectRaw('type as k, count(*) as n')->groupBy('type')->orderByDesc('n')->get()->map(fn ($r): array => ['key' => $r->k, 'count' => (int) $r->n])->all();

        $span = $filters->dateFrom && $filters->dateTo ? (strtotime($filters->dateTo) - strtotime($filters->dateFrom)) / 86400 : (($t->first_date && $t->last_date) ? (strtotime((string) $t->last_date) - strtotime((string) $t->first_date)) / 86400 : 0);
        $grain = $span > 92 ? 'month' : 'day';
        $bucket = $this->bucketSql($grain);
        $out['trend'] = ['grain' => $grain, 'points' => $base()->toBase()->selectRaw("$bucket as d, count(*) as works, sum(case when status in ('completed','complete') then 1 else 0 end) as completed")
            ->groupBy('d')->orderBy('d')->get()->map(fn ($r): array => ['date' => $r->d, 'works' => (int) $r->works, 'completed' => (int) $r->completed])->all()];

        $inch = $base()->toBase()->selectRaw("incharge as k, count(*) as works, sum(case when status in ('completed','complete') then 1 else 0 end) as completed, sum(coalesce(resubmission_count,0)) as resubmissions")
            ->groupBy('incharge')->orderByDesc('works')->limit(15)->get();
        $names = User::withTrashed()->whereIn('employee_id', $inch->pluck('k')->filter())->pluck('name', 'employee_id');
        $out['by_incharge'] = $inch->map(fn ($r): array => ['id' => $r->k, 'name' => $names[$r->k] ?? $r->k, 'works' => (int) $r->works, 'completed' => (int) $r->completed, 'resubmissions' => (int) $r->resubmissions])->all();

        $jur = $base()->toBase()->selectRaw("jurisdiction_id as k, count(*) as works, sum(case when status in ('completed','complete') then 1 else 0 end) as completed")
            ->groupBy('jurisdiction_id')->orderByDesc('works')->limit(20)->get();
        $codes = Jurisdiction::withTrashed()->whereIn('id', $jur->pluck('k')->filter())->get(['id', 'code', 'location'])->keyBy('id');
        $out['by_jurisdiction'] = $jur->map(fn ($r): array => ['id' => $r->k, 'code' => $r->k ? ($codes[$r->k]->code ?? '#'.$r->k) : null, 'name' => $r->k ? ($codes[$r->k]->location ?? null) : 'Not attributed', 'works' => (int) $r->works, 'completed' => (int) $r->completed])->all();

        $out['turnaround'] = $this->turnaround($base);

        return $out;
    }

    private function bucketSql(string $grain): string
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($grain === 'month') {
            return $mysql ? "date_format(date, '%Y-%m')" : "strftime('%Y-%m', date)";
        }

        return $mysql ? 'date(date)' : 'date(date)';
    }

    /** Days from the RFI submission (or, for old records without one, the work date) to completion. @return array<string, mixed> */
    private function turnaround(callable $base): array
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $start = 'coalesce(rfi_submission_date, date)';
        $days = $mysql ? "(timestampdiff(minute, $start, completion_time) / 1440.0)" : "(julianday(completion_time) - julianday($start))";
        $r = $base()->toBase()->whereIn('status', ['completed', 'complete'])->whereNotNull('completion_time')
            ->whereRaw("$days >= 0")
            ->selectRaw("count(*) as n, avg($days) as avg_days, sum(case when rfi_submission_date is null then 1 else 0 end) as fallback")->first();

        return ['avg_days' => $r && $r->n > 0 ? round((float) $r->avg_days, 1) : null, 'samples' => (int) ($r->n ?? 0), 'fallback_samples' => (int) ($r->fallback ?? 0)];
    }
}
