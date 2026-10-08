<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Offboarding;
use App\Models\HRM\Onboarding;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Dashboard\TodaySummary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * HR snapshot: attendance today, on leave today, open offboardings, probation ending soon.
 *
 * Permission: employees.view. Scope: DepartmentScope - HR/global roles get the whole
 * organization, anyone else (for example a department head who holds employees.view) only
 * their own departments and reporting line, so the figures always match the people the
 * viewer can open. On/offboardings are filtered through applyToEmployeeOwned and shown only
 * to viewers who also hold hr.onboarding.view / hr.offboarding.view.
 */
final class HrSnapshot extends DashboardWidget
{
    /** Probation-end look-ahead, in days. */
    private const PROBATION_WINDOW_DAYS = 30;

    /** Weeks shown on the probation timeline. */
    private const PROBATION_WEEKS = 8;

    public function __construct(DepartmentScope $scope, private readonly TodaySummary $today)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'hr.snapshot';
    }

    public function title(): string
    {
        return 'HR snapshot';
    }

    public function permissions(): array
    {
        return ['employees.view'];
    }

    public function personas(): array
    {
        return ['hr_manager', 'administrator', 'department_admin'];
    }

    public function section(): string
    {
        return self::SECTION_HR;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_MAIN;
    }

    public function priority(): int
    {
        return 50;
    }

    public function span(): int
    {
        return 6;
    }

    public function ttl(): int
    {
        return 180;
    }

    public function route(User $viewer): ?string
    {
        return 'employees';
    }

    public function type(): string
    {
        return 'analytics';
    }

    public function data(User $viewer): array
    {
        $t = $this->today->summarize($this->people($viewer));
        $today = now()->startOfDay();

        $probationRows = $this->between(
            $this->people($viewer)->whereNotNull('users.probation_end_date'),
            'users.probation_end_date',
            $today,
            $today->copy()->addWeeks(self::PROBATION_WEEKS)->subDay(),
        )->orderBy('users.probation_end_date')->get(['users.employee_id', 'users.name', 'users.probation_end_date']);
        $probation = $this->between(
            $this->people($viewer)->whereNotNull('users.probation_end_date'),
            'users.probation_end_date',
            $today,
            $today->copy()->addDays(self::PROBATION_WINDOW_DAYS),
        )->count();

        $stats = [
            $this->stat('present', 'Present today', $t['present'], 'good', 'attendance.unified'),
            $this->stat('on_leave', 'On leave today', $t['on_leave'], $t['on_leave'] > 0 ? 'info' : 'neutral', 'leaves.index'),
        ];
        $charts = [];

        if ($viewer->can('hr.onboarding.view')) {
            $byStatus = $this->pipeline(Onboarding::query(), $viewer);
            $open = ($byStatus[Onboarding::STATUS_PENDING] ?? 0) + ($byStatus[Onboarding::STATUS_IN_PROGRESS] ?? 0);
            $stats[] = $this->stat('onboardings', 'Open onboardings', $open, $this->toneFor($open, 'info'), 'hr.onboarding.index');
            $charts[] = $this->pipelineChart('onboarding_pipeline', 'Onboarding pipeline', $byStatus);
        }

        if ($viewer->can('hr.offboarding.view')) {
            $byStatus = $this->pipeline(Offboarding::query(), $viewer);
            $open = ($byStatus[Offboarding::STATUS_PENDING] ?? 0) + ($byStatus[Offboarding::STATUS_IN_PROGRESS] ?? 0);
            $stats[] = $this->stat('offboardings', 'Open offboardings', $open, $this->toneFor($open), 'hr.offboarding.index');
            $charts[] = $this->pipelineChart('offboarding_pipeline', 'Offboarding pipeline', $byStatus);
        }

        $stats[] = $this->stat('probation_due', 'Probation ending (30d)', $probation, $this->toneFor($probation), 'employees');
        $charts[] = $this->probationTimeline($probationRows, $today);

        return [
            'scope' => $this->scopeMeta($viewer),
            'total' => $t['total'],
            'stats' => $stats,
            'kpis' => [
                $this->kpi('probation_due', 'Probation ending (30 days)', $probation, $this->toneFor($probation), 'employees', null, null, null, null, false),
            ],
            'charts' => $charts,
        ];
    }

    /**
     * Records per status (onboardings / offboardings) of employees the viewer may see. One grouped query.
     *
     * @param  Builder<Model>  $query
     * @return array<string, int>
     */
    private function pipeline(Builder $query, User $viewer): array
    {
        return $this->owned($query, $viewer, 'employee_id')->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    /** @param  array<string, int>  $byStatus */
    private function pipelineChart(string $key, string $title, array $byStatus): array
    {
        $order = ['pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        $data = array_map(fn ($status) => $byStatus[$status] ?? 0, array_keys($order));

        return $this->chart($key, 'bar', $title,
            $title.' by status: '.implode(', ', array_map(fn ($l, $n) => "{$l} {$n}", array_values($order), $data)),
            [
                'horizontal' => true,
                'categories' => array_values($order),
                'series' => [['name' => 'Records', 'tone' => 'theme', 'data' => $data]],
            ], null, 'No records yet.');
    }

    /**
     * Probation end dates in the next weeks: a weekly histogram plus the next five names.
     *
     * @param  Collection<int, User>  $rows
     */
    private function probationTimeline($rows, $today): array
    {
        $weeks = [];
        for ($w = 0; $w < self::PROBATION_WEEKS; $w++) {
            $weeks[$w] = 0;
        }
        foreach ($rows as $row) {
            $index = (int) floor($today->diffInDays(Carbon::parse($row->probation_end_date)->startOfDay()) / 7);
            if (isset($weeks[$index])) {
                $weeks[$index]++;
            }
        }
        $labels = array_map(fn ($w) => $today->copy()->addWeeks($w)->format('d M'), array_keys($weeks));

        return $this->chart('probation_timeline', 'bar', 'Probation ending, next '.self::PROBATION_WEEKS.' weeks',
            'Employees whose probation ends in each coming week: '.implode(', ', array_map(fn ($l, $n) => "week of {$l}: {$n}", $labels, $weeks)),
            [
                'categories' => $labels,
                'series' => [['name' => 'Probation ends', 'tone' => 'warn', 'data' => array_values($weeks)]],
                'items' => $rows->take(5)->map(fn ($r) => $this->item($r->employee_id, $r->name, null, Carbon::parse($r->probation_end_date)->format('d M')))->values()->all(),
            ], ['from' => $today->toDateString(), 'to' => $today->copy()->addWeeks(self::PROBATION_WEEKS)->subDay()->toDateString()], 'No probation ends in this period.');
    }
}
