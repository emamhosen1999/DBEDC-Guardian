<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\Leave;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Leave\LeaveApprovalService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Requests waiting for the viewer's decision: leave, regularization, overtime, shift swaps.
 *
 * Scope: DepartmentScope::applyToEmployeeOwned (shift swaps: the attendance-administrator
 * rule from DepartmentScope::isAttendanceAdmin). A request is counted when the viewer
 * could actually decide it — they are the named approver at the current chain level, or
 * (department-scoped viewers) they strictly outrank the requester; never their own
 * request. Each figure appears only for viewers holding the permission the decision
 * endpoint requires, and is linked only where they can open the page.
 */
final class PendingApprovals extends DashboardWidget
{
    public function __construct(
        DepartmentScope $scope,
        private readonly LeaveApprovalService $leaveApprovals,
    ) {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'team.approvals';
    }

    public function title(): string
    {
        return 'Pending approvals';
    }

    public function permissions(): array
    {
        return ['leaves.approve', 'attendance.correct', 'attendance.create', 'attendance.update', 'attendance.roster.manage', 'attendance.settings'];
    }

    public function personas(): array
    {
        return ['line_manager', 'department_admin', 'hr_manager', 'administrator'];
    }

    public function section(): string
    {
        return self::SECTION_TEAM;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_MAIN;
    }

    public function priority(): int
    {
        return 10;
    }

    public function span(): int
    {
        return 4;
    }

    public function ttl(): int
    {
        return 60;
    }

    public function route(User $viewer): ?string
    {
        return $viewer->can('leaves.view') && $viewer->can('leaves.approve') ? 'leaves.index' : 'attendance.unified';
    }

    public function mobileRoute(): ?string
    {
        return '/leave-approvals';
    }

    public function type(): string
    {
        return 'analytics';
    }

    public function data(User $viewer): array
    {
        $stats = [];
        /** @var array<string, Collection<int, array{kind: string, id: int, user_id: string, created_at: ?string}>> $pending */
        $pending = [];

        if ($viewer->can('leaves.approve')) {
            $pending['leaves'] = $this->pendingLeaves($viewer);
            $stats[] = $this->stat('leaves', 'Leave requests', $pending['leaves']->count(), $this->toneFor($pending['leaves']->count()), 'leaves.index', '/leave-approvals');
        }

        if ($viewer->canAny(['attendance.correct', 'attendance.create', 'attendance.update'])) {
            $pending['regularizations'] = $this->pendingAttendanceRequests($viewer, AttendanceRegularization::query(), 'regularization');
            $pending['overtime'] = $this->pendingAttendanceRequests($viewer, OvertimeRequest::query(), 'overtime');
            $stats[] = $this->stat('regularizations', 'Regularizations', $pending['regularizations']->count(), $this->toneFor($pending['regularizations']->count()), 'attendance.unified', '/regularization-approvals');
            $stats[] = $this->stat('overtime', 'Overtime', $pending['overtime']->count(), $this->toneFor($pending['overtime']->count()), 'attendance.unified', '/overtime-approvals');
        }

        if ($viewer->canAny(['attendance.roster.manage', 'attendance.settings'])) {
            $pending['swaps'] = $this->pendingSwaps($viewer);
            $stats[] = $this->stat('swaps', 'Shift swaps', $pending['swaps']->count(), $this->toneFor($pending['swaps']->count()), 'attendance.unified', '/swap-approvals');
        }

        $all = collect($pending)->flatten(1)->values();
        $total = $all->count();
        $ages = $all->map(fn (array $row) => $row['created_at'] === null ? 0 : (int) max(0, Carbon::parse($row['created_at'])->startOfDay()->diffInDays(now()->startOfDay())));
        $oldest = $ages->max();

        return [
            'scope' => $this->scopeMeta($viewer),
            'total' => $total,
            'stats' => $stats,
            'kpis' => [
                $this->kpi('approvals', 'Pending approvals', $total, $this->toneFor($total), $viewer->can('leaves.view') && $viewer->can('leaves.approve') ? 'leaves.index' : 'attendance.unified', null, null, null,
                    $total > 0 ? 'oldest '.$oldest.' '.($oldest === 1 ? 'day' : 'days') : 'nothing waiting'),
            ],
            'charts' => [
                $this->chart('approval_ageing', 'bar', 'Approvals by age',
                    'Requests waiting for your decision, by days waiting: '.collect($this->ageBuckets($ages))->map(fn ($n, $b) => "{$b} days {$n}")->implode(', '),
                    [
                        'categories' => array_keys($this->ageBuckets($ages)),
                        'series' => [['name' => 'Requests', 'tone' => 'warn', 'data' => array_values($this->ageBuckets($ages))]],
                        'items' => $this->oldestItems($all),
                    ], ['from' => null, 'to' => now()->toDateString()], 'Nothing is waiting for your decision.'),
            ],
        ];
    }

    /**
     * Requests per age bucket (days waiting): 0-1, 2-3, 4-7, more than 7.
     *
     * @param  Collection<int, int>  $ages
     * @return array<string, int>
     */
    private function ageBuckets(Collection $ages): array
    {
        return [
            '0-1' => $ages->filter(fn (int $d) => $d <= 1)->count(),
            '2-3' => $ages->filter(fn (int $d) => $d >= 2 && $d <= 3)->count(),
            '4-7' => $ages->filter(fn (int $d) => $d >= 4 && $d <= 7)->count(),
            '>7' => $ages->filter(fn (int $d) => $d > 7)->count(),
        ];
    }

    /**
     * The five longest-waiting requests, with the requester's name (one query).
     *
     * @param  Collection<int, array{kind: string, id: int, user_id: string, created_at: ?string}>  $all
     * @return array<int, array<string, mixed>>
     */
    private function oldestItems(Collection $all): array
    {
        $oldest = $all->sortBy(fn (array $row) => $row['created_at'] ?? '9999')->take(5)->values();
        $names = User::withTrashed()->whereIn('employee_id', $oldest->pluck('user_id')->unique()->all())->pluck('name', 'employee_id');
        $labels = ['leave' => 'Leave', 'regularization' => 'Regularization', 'overtime' => 'Overtime', 'swap' => 'Shift swap'];

        return $oldest->map(function (array $row) use ($names, $labels): array {
            $days = $row['created_at'] === null ? 0 : (int) max(0, Carbon::parse($row['created_at'])->startOfDay()->diffInDays(now()->startOfDay()));

            return $this->item($row['kind'].':'.$row['id'], ($labels[$row['kind']] ?? $row['kind']).' - '.($names[$row['user_id']] ?? $row['user_id']), null, $days.' d', $days > 7 ? 'crit' : ($days > 3 ? 'warn' : 'neutral'));
        })->all();
    }

    /**
     * Pending leaves the viewer may decide - the chain is JSON, so it is evaluated in memory.
     *
     * @return Collection<int, array{kind: string, id: int, user_id: string, created_at: ?string}>
     */
    private function pendingLeaves(User $viewer): Collection
    {
        $pending = $this->owned(Leave::query(), $viewer, 'user_id')
            ->where('user_id', '!=', $this->id($viewer))
            ->whereRaw('LOWER(status) = ?', ['pending'])
            ->whereNotNull('approval_chain')
            ->get(['id', 'user_id', 'status', 'approval_chain', 'current_approval_level', 'created_at']);

        // Privileged (override) viewers may decide every in-scope pending leave; the scope
        // filter above already confined them, so no per-leave lookup is needed.
        $decidable = $this->leaveApprovals->canOverride($viewer)
            ? $pending
            : $pending->filter(fn (Leave $leave): bool => $this->leaveApprovals->canApprove($leave, $viewer));

        return $this->rows($decidable, 'leave', 'user_id');
    }

    /**
     * @param  Builder<Model>  $query  regularization / overtime
     * @return Collection<int, array{kind: string, id: int, user_id: string, created_at: ?string}>
     */
    private function pendingAttendanceRequests(User $viewer, Builder $query, string $kind): Collection
    {
        $rows = $this->owned($query, $viewer, 'user_id')
            ->where('user_id', '!=', $this->id($viewer))
            ->where('status', 'pending')
            ->get(['id', 'user_id', 'approval_chain', 'current_approval_level', 'created_at']);

        return $this->rows($this->actionable($rows, $viewer, 'user_id'), $kind, 'user_id');
    }

    /** @return Collection<int, array{kind: string, id: int, user_id: string, created_at: ?string}> */
    private function pendingSwaps(User $viewer): Collection
    {
        $query = ShiftSwapRequest::query()
            ->where('requester_id', '!=', $this->id($viewer))
            ->where('status', 'pending')
            // The manager's stage only opens after the counterparty accepted (never 'approved').
            ->where('counterparty_status', 'accepted');

        // Roster/attendance administrators decide company-wide; everyone else within their scope.
        if (! $this->scope->isAttendanceAdmin($viewer)) {
            $this->owned($query, $viewer, 'requester_id');
        }

        return $this->rows($query->get(['id', 'requester_id', 'created_at']), 'swap', 'requester_id');
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return Collection<int, array{kind: string, id: int, user_id: string, created_at: ?string}>
     */
    private function rows(Collection $models, string $kind, string $requesterColumn): Collection
    {
        return $models->map(fn ($m): array => [
            'kind' => $kind,
            'id' => (int) $m->id,
            'user_id' => (string) $m->{$requesterColumn},
            'created_at' => $m->created_at?->toDateTimeString(),
        ])->values();
    }

    /**
     * Which of these pending requests could the viewer decide?
     *
     * @param  Collection<int, Model>  $rows
     * @return Collection<int, Model>
     */
    private function actionable(Collection $rows, User $viewer, string $requesterColumn): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $isGlobal = $this->scope->isGlobal($viewer);
        $me = $this->id($viewer);
        $outranked = $isGlobal
            ? []
            : array_flip($this->scope->outrankedIds($viewer, $rows->pluck($requesterColumn)->all()));

        return $rows->filter(function ($row) use ($isGlobal, $me, $outranked, $requesterColumn): bool {
            $chain = is_array($row->approval_chain) ? $row->approval_chain : [];

            foreach ($chain as $level) {
                if ((int) ($level['level'] ?? 0) === (int) $row->current_approval_level
                    && (string) ($level['approver_id'] ?? '') === $me
                    && strtolower((string) ($level['status'] ?? '')) === 'pending') {
                    return true;
                }
            }

            // Global actors act through the chain, or on requests that have none; scoped
            // actors additionally decide requests of employees they outrank.
            return $isGlobal ? $chain === [] : isset($outranked[(string) $row->{$requesterColumn}]);
        })->values();
    }
}
