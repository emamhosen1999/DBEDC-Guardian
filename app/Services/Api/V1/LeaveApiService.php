<?php

namespace App\Services\Api\V1;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LeaveApiService
{
    /**
     * Fetch active leave type definitions for mobile clients.
     */
    public function getLeaveTypes(): Collection
    {
        if (! Schema::hasTable('leave_settings')) {
            return collect();
        }

        $columns = [
            'id',
            'type',
            'days',
            'eligibility',
            'carry_forward',
            'earned_leave',
            'requires_approval',
            'auto_approve',
            'special_conditions',
        ];

        if (Schema::hasColumn('leave_settings', 'symbol')) {
            $columns[] = 'symbol';
        }

        if (Schema::hasColumn('leave_settings', 'is_earned')) {
            $columns[] = 'is_earned';
        }

        return LeaveSetting::query()
            ->select($columns)
            ->orderBy('type')
            ->get();
    }

    public function leavesTableExists(): bool
    {
        return Schema::hasTable('leaves');
    }

    public function resolveLeavesUserColumn(): ?string
    {
        if (Schema::hasColumn('leaves', 'user_id')) {
            return 'user_id';
        }

        if (Schema::hasColumn('leaves', 'user')) {
            return 'user';
        }

        return null;
    }

    /**
     * @return array{leaves: Collection, pagination: array<string, int>}
     */
    public function listLeavesForUser(User $user, array $filters, int $page = 1, int $perPage = 10): array
    {
        if (! $this->leavesTableExists()) {
            return $this->emptyLeaveList($perPage);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            throw new RuntimeException('Leave schema is misconfigured.');
        }

        $perPage = max(1, $perPage);
        $page = max(1, $page);

        $query = DB::table('leaves')
            ->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->where("leaves.{$userColumn}", $user->id);

        if (! empty($filters['status'])) {
            $status = strtolower((string) $filters['status']);
            $query->whereRaw('LOWER(leaves.status) = ?', [$status]);
        }

        if (! empty($filters['year'])) {
            $query->whereYear('leaves.from_date', (int) $filters['year']);
        }

        if (! empty($filters['month'])) {
            $query->whereMonth('leaves.from_date', (int) $filters['month']);
        }

        $selectColumns = $this->leaveSelectColumns($userColumn);

        $total = (clone $query)->count('leaves.id');

        $leaves = $query
            ->select($selectColumns)
            ->orderByDesc('leaves.from_date')
            ->orderByDesc('leaves.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($leave) => $this->transformLeaveRow($leave))
            ->values();

        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'leaves' => $leaves,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLeaveForUser(User $user, int $leaveId): ?array
    {
        if (! $this->leavesTableExists()) {
            return null;
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            throw new RuntimeException('Leave schema is misconfigured.');
        }

        $leave = DB::table('leaves')->where('id', $leaveId)->first();

        if (! $leave) {
            return null;
        }

        if ((int) ($leave->{$userColumn} ?? 0) !== (int) $user->id) {
            throw new RuntimeException('You are not authorized to access this leave request.', 403);
        }

        $query = DB::table('leaves')->where('leaves.id', $leaveId);

        if (Schema::hasTable('leave_settings')) {
            $query->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id');
        }

        $selectColumns = $this->leaveDetailSelectColumns($userColumn);

        $leaveDetails = $query->select($selectColumns)->first();

        return $leaveDetails ? $this->transformLeaveRow($leaveDetails) : null;
    }

    /**
     * Monthly leave calendar payload for mobile clients.
     *
     * @return array<string, mixed>
     */
    public function getCalendarForUser(
        User $user,
        int $year,
        int $month,
        ?int $leaveTypeId = null,
        bool $includeHolidays = true
    ): array {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $leaves = [];
        $approvedLeaveDays = 0;
        $pendingLeaveDays = 0;
        $rejectedLeaveDays = 0;
        $totalLeaveDays = 0;

        if ($this->leavesTableExists()) {
            $userColumn = $this->resolveLeavesUserColumn();

            if (! $userColumn) {
                throw new RuntimeException('Leave schema is misconfigured.');
            }

            $query = DB::table('leaves')
                ->where("leaves.{$userColumn}", (int) $user->id)
                ->whereDate('leaves.from_date', '<=', $endOfMonth->toDateString())
                ->whereDate('leaves.to_date', '>=', $startOfMonth->toDateString());

            if ($leaveTypeId !== null) {
                $query->where('leaves.leave_type', $leaveTypeId);
            }

            $selectColumns = [
                'leaves.id',
                'leaves.leave_type',
                'leaves.from_date',
                'leaves.to_date',
                'leaves.no_of_days',
                'leaves.reason',
                'leaves.status',
            ];

            if (Schema::hasTable('leave_settings')) {
                $query->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id');
                $selectColumns[] = 'leave_settings.type as leave_type_name';

                if (Schema::hasColumn('leave_settings', 'symbol')) {
                    $selectColumns[] = 'leave_settings.symbol as leave_type_symbol';
                } else {
                    $selectColumns[] = DB::raw('NULL as leave_type_symbol');
                }
            } else {
                $selectColumns[] = DB::raw('NULL as leave_type_name');
                $selectColumns[] = DB::raw('NULL as leave_type_symbol');
            }

            $leaveRecords = $query
                ->select($selectColumns)
                ->orderBy('leaves.from_date')
                ->orderBy('leaves.id')
                ->get();

            foreach ($leaveRecords as $leave) {
                $overlapDays = $this->calculateDateRangeOverlapDays(
                    (string) $leave->from_date,
                    (string) $leave->to_date,
                    $startOfMonth,
                    $endOfMonth
                );

                $statusBucket = $this->normalizeLeaveStatusBucket((string) $leave->status);

                if ($statusBucket === 'approved') {
                    $approvedLeaveDays += $overlapDays;
                }

                if ($statusBucket === 'pending') {
                    $pendingLeaveDays += $overlapDays;
                }

                if ($statusBucket === 'rejected') {
                    $rejectedLeaveDays += $overlapDays;
                }

                $totalLeaveDays += $overlapDays;

                $leaves[] = [
                    'id' => (int) $leave->id,
                    'leave_type' => (int) $leave->leave_type,
                    'leave_type_name' => $leave->leave_type_name,
                    'leave_type_symbol' => $leave->leave_type_symbol,
                    'from_date' => $leave->from_date,
                    'to_date' => $leave->to_date,
                    'no_of_days' => (int) $leave->no_of_days,
                    'overlap_days' => $overlapDays,
                    'reason' => $leave->reason,
                    'status' => $leave->status,
                    'status_bucket' => $statusBucket,
                ];
            }
        }

        $holidays = [];
        $holidayDays = 0;

        if ($includeHolidays && Schema::hasTable('holidays')) {
            $holidayQuery = DB::table('holidays')
                ->whereDate('from_date', '<=', $endOfMonth->toDateString())
                ->whereDate('to_date', '>=', $startOfMonth->toDateString());

            if (Schema::hasColumn('holidays', 'is_active')) {
                $holidayQuery->where('is_active', true);
            }

            $holidaySelectColumns = [
                'id',
                'title',
                'from_date',
                'to_date',
            ];

            if (Schema::hasColumn('holidays', 'description')) {
                $holidaySelectColumns[] = 'description';
            } else {
                $holidaySelectColumns[] = DB::raw('NULL as description');
            }

            if (Schema::hasColumn('holidays', 'type')) {
                $holidaySelectColumns[] = 'type';
            } else {
                $holidaySelectColumns[] = DB::raw('NULL as type');
            }

            $holidayRecords = $holidayQuery
                ->select($holidaySelectColumns)
                ->orderBy('from_date')
                ->orderBy('id')
                ->get();

            foreach ($holidayRecords as $holiday) {
                $overlapDays = $this->calculateDateRangeOverlapDays(
                    (string) $holiday->from_date,
                    (string) $holiday->to_date,
                    $startOfMonth,
                    $endOfMonth
                );

                $holidayDays += $overlapDays;

                $holidays[] = [
                    'id' => (int) $holiday->id,
                    'title' => $holiday->title,
                    'description' => $holiday->description,
                    'type' => $holiday->type,
                    'from_date' => $holiday->from_date,
                    'to_date' => $holiday->to_date,
                    'overlap_days' => $overlapDays,
                ];
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'range' => [
                'from' => $startOfMonth->toDateString(),
                'to' => $endOfMonth->toDateString(),
            ],
            'summary' => [
                'total_leave_requests' => count($leaves),
                'total_leave_days' => $totalLeaveDays,
                'approved_leave_days' => $approvedLeaveDays,
                'pending_leave_days' => $pendingLeaveDays,
                'rejected_leave_days' => $rejectedLeaveDays,
                'holiday_days' => $holidayDays,
            ],
            'leaves' => $leaves,
            'holidays' => $holidays,
        ];
    }

    /**
     * Annual leave balance summary for mobile clients.
     *
     * @return array<string, mixed>
     */
    public function getSummaryForUser(User $user, int $year, ?int $leaveTypeId = null): array
    {
        $emptyTotals = [
            'allocated' => 0,
            'used' => 0,
            'pending' => 0,
            'rejected' => 0,
            'balance' => 0,
            'usage_percentage' => 0,
        ];

        if (! Schema::hasTable('leave_settings')) {
            return [
                'year' => $year,
                'totals' => $emptyTotals,
                'by_type' => [],
            ];
        }

        $leaveTypesQuery = LeaveSetting::query()
            ->select(['id', 'type', 'symbol', 'days', 'requires_approval', 'auto_approve'])
            ->orderBy('type');

        if ($leaveTypeId) {
            $leaveTypesQuery->where('id', $leaveTypeId);
        }

        $leaveTypes = $leaveTypesQuery->get();

        if ($leaveTypes->isEmpty()) {
            return [
                'year' => $year,
                'totals' => $emptyTotals,
                'by_type' => [],
            ];
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            throw new RuntimeException('Leave schema is misconfigured.');
        }

        if (! $this->leavesTableExists()) {
            $byType = $leaveTypes->map(function (LeaveSetting $leaveType): array {
                $allocated = (int) ($leaveType->days ?? 0);

                return [
                    'id' => (int) $leaveType->id,
                    'type' => $leaveType->type,
                    'symbol' => $leaveType->symbol,
                    'requires_approval' => (bool) ($leaveType->requires_approval ?? true),
                    'auto_approve' => (bool) ($leaveType->auto_approve ?? false),
                    'allocated' => $allocated,
                    'used' => 0,
                    'pending' => 0,
                    'rejected' => 0,
                    'balance' => $allocated,
                    'usage_percentage' => 0,
                ];
            })->values();

            return [
                'year' => $year,
                'totals' => [
                    'allocated' => (int) $byType->sum('allocated'),
                    'used' => 0,
                    'pending' => 0,
                    'rejected' => 0,
                    'balance' => (int) $byType->sum('balance'),
                    'usage_percentage' => 0,
                ],
                'by_type' => $byType,
            ];
        }

        $rawLeaveUsage = DB::table('leaves')
            ->where("leaves.{$userColumn}", (int) $user->id)
            ->whereYear('from_date', $year)
            ->when($leaveTypeId, function ($query) use ($leaveTypeId) {
                $query->where('leave_type', $leaveTypeId);
            })
            ->select([
                'leave_type',
                DB::raw('LOWER(COALESCE(status, "")) as normalized_status'),
                DB::raw('SUM(no_of_days) as total_days'),
            ])
            ->groupBy('leave_type', 'normalized_status')
            ->get();

        $usageByTypeAndStatus = [];

        foreach ($rawLeaveUsage as $usageRow) {
            $typeKey = (int) ($usageRow->leave_type ?? 0);
            $statusKey = (string) ($usageRow->normalized_status ?? '');
            $days = (int) ($usageRow->total_days ?? 0);

            if (! isset($usageByTypeAndStatus[$typeKey])) {
                $usageByTypeAndStatus[$typeKey] = [];
            }

            $usageByTypeAndStatus[$typeKey][$statusKey] = $days;
        }

        $totals = [
            'allocated' => 0,
            'used' => 0,
            'pending' => 0,
            'rejected' => 0,
            'balance' => 0,
        ];

        $byType = $leaveTypes->map(function (LeaveSetting $leaveType) use (&$totals, $usageByTypeAndStatus): array {
            $allocated = (int) ($leaveType->days ?? 0);
            $usage = $usageByTypeAndStatus[(int) $leaveType->id] ?? [];

            $used = (int) ($usage['approved'] ?? 0);
            $pending = (int) (($usage['new'] ?? 0) + ($usage['pending'] ?? 0));
            $rejected = (int) (($usage['declined'] ?? 0) + ($usage['rejected'] ?? 0));
            $balance = max(0, $allocated - $used);

            $totals['allocated'] += $allocated;
            $totals['used'] += $used;
            $totals['pending'] += $pending;
            $totals['rejected'] += $rejected;
            $totals['balance'] += $balance;

            return [
                'id' => (int) $leaveType->id,
                'type' => $leaveType->type,
                'symbol' => $leaveType->symbol,
                'requires_approval' => (bool) ($leaveType->requires_approval ?? true),
                'auto_approve' => (bool) ($leaveType->auto_approve ?? false),
                'allocated' => $allocated,
                'used' => $used,
                'pending' => $pending,
                'rejected' => $rejected,
                'balance' => $balance,
                'usage_percentage' => $allocated > 0 ? round(($used / $allocated) * 100, 1) : 0,
            ];
        })->values();

        $usagePercentage = $totals['allocated'] > 0
            ? round(($totals['used'] / $totals['allocated']) * 100, 1)
            : 0;

        return [
            'year' => $year,
            'totals' => [
                ...$totals,
                'usage_percentage' => $usagePercentage,
            ],
            'by_type' => $byType,
        ];
    }

    /**
     * Yearly leave analytics for mobile clients.
     *
     * @throws RuntimeException when leave schema is misconfigured
     */
    public function getAnalyticsForUser(User $user, int $year, ?int $leaveTypeId = null): array
    {
        $statusBreakdown = [
            'approved' => ['requests' => 0, 'days' => 0],
            'pending' => ['requests' => 0, 'days' => 0],
            'rejected' => ['requests' => 0, 'days' => 0],
            'other' => ['requests' => 0, 'days' => 0],
        ];

        $monthly = $this->buildMonthlyAnalyticsTemplate();

        if (! $this->leavesTableExists()) {
            return [
                'year' => $year,
                'totals' => [
                    'requests' => 0,
                    'approved_days' => 0,
                    'pending_days' => 0,
                    'rejected_days' => 0,
                    'total_days' => 0,
                ],
                'status_breakdown' => $statusBreakdown,
                'monthly' => array_values($monthly),
                'leave_type_breakdown' => [],
            ];
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            throw new RuntimeException('Leave schema is misconfigured.');
        }

        if ($leaveTypeId !== null && Schema::hasTable('leave_settings') && ! LeaveSetting::query()->whereKey($leaveTypeId)->exists()) {
            throw new RuntimeException('Invalid leave type selected.', 422);
        }

        $hasLeaveSettings = Schema::hasTable('leave_settings');

        $query = DB::table('leaves')
            ->where("leaves.{$userColumn}", (int) $user->id)
            ->whereYear('leaves.from_date', $year);

        if ($hasLeaveSettings) {
            $query->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id');
        }

        if ($leaveTypeId !== null) {
            $query->where('leaves.leave_type', $leaveTypeId);
        }

        $selectColumns = [
            'leaves.leave_type',
            'leaves.from_date',
            'leaves.status',
            'leaves.no_of_days',
        ];

        if ($hasLeaveSettings) {
            $selectColumns[] = 'leave_settings.type as leave_type_name';
        } else {
            $selectColumns[] = DB::raw('NULL as leave_type_name');
        }

        $leaves = $query->select($selectColumns)->get();

        $leaveTypeBreakdown = [];

        foreach ($leaves as $leave) {
            $bucket = $this->normalizeLeaveStatusBucket((string) ($leave->status ?? ''));
            $days = max(0, (int) ($leave->no_of_days ?? 0));

            $statusBreakdown[$bucket]['requests']++;
            $statusBreakdown[$bucket]['days'] += $days;

            if (! empty($leave->from_date)) {
                $month = Carbon::parse((string) $leave->from_date)->month;

                if (isset($monthly[$month])) {
                    $monthly[$month]['requests']++;
                    $monthly[$month]['total_days'] += $days;

                    if ($bucket === 'approved') {
                        $monthly[$month]['approved_days'] += $days;
                    }

                    if ($bucket === 'pending') {
                        $monthly[$month]['pending_days'] += $days;
                    }

                    if ($bucket === 'rejected') {
                        $monthly[$month]['rejected_days'] += $days;
                    }
                }
            }

            $leaveTypeKey = (int) ($leave->leave_type ?? 0);

            if (! isset($leaveTypeBreakdown[$leaveTypeKey])) {
                $leaveTypeBreakdown[$leaveTypeKey] = [
                    'leave_type_id' => $leaveTypeKey,
                    'leave_type_name' => $leave->leave_type_name,
                    'requests' => 0,
                    'approved_days' => 0,
                    'pending_days' => 0,
                    'rejected_days' => 0,
                    'total_days' => 0,
                ];
            }

            $leaveTypeBreakdown[$leaveTypeKey]['requests']++;
            $leaveTypeBreakdown[$leaveTypeKey]['total_days'] += $days;

            if ($bucket === 'approved') {
                $leaveTypeBreakdown[$leaveTypeKey]['approved_days'] += $days;
            }

            if ($bucket === 'pending') {
                $leaveTypeBreakdown[$leaveTypeKey]['pending_days'] += $days;
            }

            if ($bucket === 'rejected') {
                $leaveTypeBreakdown[$leaveTypeKey]['rejected_days'] += $days;
            }
        }

        ksort($leaveTypeBreakdown);

        $totalDays = array_sum(array_map(fn (array $status): int => (int) $status['days'], $statusBreakdown));

        return [
            'year' => $year,
            'totals' => [
                'requests' => $leaves->count(),
                'approved_days' => $statusBreakdown['approved']['days'],
                'pending_days' => $statusBreakdown['pending']['days'],
                'rejected_days' => $statusBreakdown['rejected']['days'],
                'total_days' => $totalDays,
            ],
            'status_breakdown' => $statusBreakdown,
            'monthly' => array_values($monthly),
            'leave_type_breakdown' => array_values($leaveTypeBreakdown),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMonthlyAnalyticsTemplate(): array
    {
        return collect(range(1, 12))->mapWithKeys(function (int $month): array {
            return [$month => [
                'month' => $month,
                'label' => Carbon::create(null, $month, 1)->format('M'),
                'requests' => 0,
                'approved_days' => 0,
                'pending_days' => 0,
                'rejected_days' => 0,
                'total_days' => 0,
            ]];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function createLeaveForUser(
        User $user,
        int $leaveTypeId,
        string $fromDate,
        string $toDate,
        string $reason
    ): array {
        $this->assertLeaveConfigurationAvailable();

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            throw new RuntimeException('Leave schema is misconfigured.', 500);
        }

        $leaveType = LeaveSetting::query()->find($leaveTypeId);

        if (! $leaveType) {
            throw new RuntimeException('Invalid leave type selected.', 422);
        }

        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();

        if ($from->lt(now()->startOfDay())) {
            throw new RuntimeException('Leave cannot be applied for past dates.', 422);
        }

        if ($this->hasOverlappingLeave($userColumn, (int) $user->id, $from, $to)) {
            throw new RuntimeException('Leave dates overlap with an existing leave request.', 422);
        }

        if ($this->hasOverlappingHoliday($from, $to)) {
            throw new RuntimeException('Selected dates overlap with a holiday.', 422);
        }

        $status = (! $leaveType->requires_approval || $leaveType->auto_approve) ? 'approved' : 'new';

        $payload = [
            'leave_type' => $leaveType->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'no_of_days' => $from->diffInDays($to) + 1,
            'reason' => $reason,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $payload[$userColumn] = $user->id;

        if ($userColumn === 'user_id' && Schema::hasColumn('leaves', 'user')) {
            $payload['user'] = $user->id;
        }

        if ($userColumn === 'user' && Schema::hasColumn('leaves', 'user_id')) {
            $payload['user_id'] = $user->id;
        }

        if (Schema::hasColumn('leaves', 'submitted_at')) {
            $payload['submitted_at'] = now();
        }

        if ($status === 'approved' && Schema::hasColumn('leaves', 'approved_at')) {
            $payload['approved_at'] = now();
        }

        $leaveId = DB::table('leaves')->insertGetId($payload);

        return $this->fetchLeaveRowById($leaveId, $userColumn);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateLeaveForUser(
        User $user,
        int $leaveId,
        int $leaveTypeId,
        string $fromDate,
        string $toDate,
        string $reason
    ): array {
        $this->assertLeaveConfigurationAvailable();

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            throw new RuntimeException('Leave schema is misconfigured.', 500);
        }

        $leave = DB::table('leaves')->where('id', $leaveId)->first();

        if (! $leave) {
            throw new RuntimeException('Leave request not found.', 404);
        }

        if ((int) ($leave->{$userColumn} ?? 0) !== (int) $user->id) {
            throw new RuntimeException('You are not authorized to update this leave request.', 403);
        }

        $currentStatus = strtolower((string) ($leave->status ?? ''));
        if (in_array($currentStatus, ['approved', 'declined', 'rejected'], true)) {
            throw new RuntimeException('This leave request can no longer be updated.', 422);
        }

        $leaveType = LeaveSetting::query()->find($leaveTypeId);

        if (! $leaveType) {
            throw new RuntimeException('Invalid leave type selected.', 422);
        }

        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();

        if ($from->lt(now()->startOfDay())) {
            throw new RuntimeException('Leave cannot be applied for past dates.', 422);
        }

        if ($this->hasOverlappingLeave($userColumn, (int) $user->id, $from, $to, $leaveId)) {
            throw new RuntimeException('Leave dates overlap with an existing leave request.', 422);
        }

        if ($this->hasOverlappingHoliday($from, $to)) {
            throw new RuntimeException('Selected dates overlap with a holiday.', 422);
        }

        $status = (! $leaveType->requires_approval || $leaveType->auto_approve) ? 'approved' : 'new';

        $updatePayload = [
            'leave_type' => $leaveType->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'no_of_days' => $from->diffInDays($to) + 1,
            'reason' => $reason,
            'status' => $status,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('leaves', 'submitted_at')) {
            $updatePayload['submitted_at'] = now();
        }

        if (Schema::hasColumn('leaves', 'approved_at')) {
            $updatePayload['approved_at'] = $status === 'approved' ? now() : null;
        }

        DB::table('leaves')->where('id', $leaveId)->update($updatePayload);

        return $this->fetchLeaveRowById($leaveId, $userColumn);
    }

    public function cancelLeaveForUser(User $user, int $leaveId): void
    {
        if (! $this->leavesTableExists()) {
            throw new RuntimeException('Leave request not found.', 404);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            throw new RuntimeException('Leave schema is misconfigured.', 500);
        }

        $leave = DB::table('leaves')->where('id', $leaveId)->first();

        if (! $leave) {
            throw new RuntimeException('Leave request not found.', 404);
        }

        if ((int) ($leave->{$userColumn} ?? 0) !== (int) $user->id) {
            throw new RuntimeException('You are not authorized to cancel this leave request.', 403);
        }

        $status = strtolower((string) ($leave->status ?? ''));
        if (in_array($status, ['approved', 'declined', 'rejected'], true)) {
            throw new RuntimeException('This leave request can no longer be cancelled.', 422);
        }

        DB::table('leaves')->where('id', $leaveId)->delete();
    }

    public function assertLeaveConfigurationAvailable(): void
    {
        if (! $this->leavesTableExists() || ! Schema::hasTable('leave_settings')) {
            throw new RuntimeException('Leave configuration is unavailable.', 500);
        }
    }

    /**
     * @return array{leaves: Collection, pagination: array<string, int>}
     */
    public function emptyLeaveList(int $perPage = 10): array
    {
        $perPage = max(1, $perPage);

        return [
            'leaves' => collect(),
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ],
        ];
    }

    /**
     * @return array<int, string|Expression>
     */
    private function leaveSelectColumns(string $userColumn): array
    {
        $selectColumns = [
            'leaves.id',
            "leaves.{$userColumn} as user_id",
            'leaves.leave_type',
            'leaves.from_date',
            'leaves.to_date',
            'leaves.no_of_days',
            'leaves.reason',
            'leaves.status',
            'leaves.created_at',
            'leaves.updated_at',
            'leave_settings.type as leave_type_name',
        ];

        if (Schema::hasColumn('leaves', 'submitted_at')) {
            $selectColumns[] = 'leaves.submitted_at';
        }

        if (Schema::hasColumn('leaves', 'approved_at')) {
            $selectColumns[] = 'leaves.approved_at';
        }

        if (Schema::hasColumn('leave_settings', 'symbol')) {
            $selectColumns[] = 'leave_settings.symbol as leave_type_symbol';
        }

        return $selectColumns;
    }

    /**
     * @return array<int, string|Expression>
     */
    private function leaveDetailSelectColumns(string $userColumn): array
    {
        $selectColumns = $this->leaveSelectColumns($userColumn);

        if (! Schema::hasTable('leave_settings')) {
            $selectColumns[] = DB::raw('NULL as leave_type_name');
            $selectColumns[] = DB::raw('NULL as leave_type_symbol');
        }

        return $selectColumns;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformLeaveRow(object $leave): array
    {
        return [
            'id' => $leave->id,
            'user_id' => (int) $leave->user_id,
            'leave_type' => (int) $leave->leave_type,
            'leave_type_name' => $leave->leave_type_name,
            'leave_type_symbol' => property_exists($leave, 'leave_type_symbol') ? $leave->leave_type_symbol : null,
            'from_date' => $leave->from_date,
            'to_date' => $leave->to_date,
            'no_of_days' => (int) $leave->no_of_days,
            'reason' => $leave->reason,
            'status' => $leave->status,
            'submitted_at' => property_exists($leave, 'submitted_at') ? $leave->submitted_at : null,
            'approved_at' => property_exists($leave, 'approved_at') ? $leave->approved_at : null,
            'created_at' => $leave->created_at,
            'updated_at' => $leave->updated_at,
        ];
    }

    private function normalizeLeaveStatusBucket(string $status): string
    {
        $normalizedStatus = strtolower(trim($status));

        if ($normalizedStatus === 'approved') {
            return 'approved';
        }

        if (in_array($normalizedStatus, ['new', 'pending'], true)) {
            return 'pending';
        }

        if (in_array($normalizedStatus, ['declined', 'rejected'], true)) {
            return 'rejected';
        }

        return 'other';
    }

    private function calculateDateRangeOverlapDays(string $fromDate, string $toDate, Carbon $rangeStart, Carbon $rangeEnd): int
    {
        $startDate = Carbon::parse($fromDate)->startOfDay();
        $endDate = Carbon::parse($toDate)->startOfDay();

        if ($endDate->lt($startDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $overlapStart = $startDate->copy();
        $overlapEnd = $endDate->copy();

        if ($overlapStart->lt($rangeStart)) {
            $overlapStart = $rangeStart->copy();
        }

        if ($overlapEnd->gt($rangeEnd)) {
            $overlapEnd = $rangeEnd->copy();
        }

        if ($overlapEnd->lt($overlapStart)) {
            return 0;
        }

        return $overlapStart->diffInDays($overlapEnd) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLeaveRowById(int $leaveId, string $userColumn): array
    {
        $query = DB::table('leaves')->where('leaves.id', $leaveId);

        if (Schema::hasTable('leave_settings')) {
            $query->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id');
        }

        $row = $query->select($this->leaveDetailSelectColumns($userColumn))->first();

        if (! $row) {
            throw new RuntimeException('Leave request not found.', 404);
        }

        return $this->transformLeaveRow($row);
    }

    private function hasOverlappingLeave(
        string $userColumn,
        int $userId,
        Carbon $fromDate,
        Carbon $toDate,
        ?int $exceptLeaveId = null
    ): bool {
        $query = DB::table('leaves')
            ->where($userColumn, $userId)
            ->where(function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('from_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhereBetween('to_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhere(function ($nestedQuery) use ($fromDate, $toDate) {
                        $nestedQuery->whereDate('from_date', '<=', $fromDate->toDateString())
                            ->whereDate('to_date', '>=', $toDate->toDateString());
                    });
            });

        if ($exceptLeaveId !== null) {
            $query->where('id', '!=', $exceptLeaveId);
        }

        return $query->exists();
    }

    private function hasOverlappingHoliday(Carbon $fromDate, Carbon $toDate): bool
    {
        if (! Schema::hasTable('holidays')) {
            return false;
        }

        return DB::table('holidays')
            ->where(function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('from_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhereBetween('to_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhere(function ($nestedQuery) use ($fromDate, $toDate) {
                        $nestedQuery->whereDate('from_date', '<=', $fromDate->toDateString())
                            ->whereDate('to_date', '>=', $toDate->toDateString());
                    });
            })
            ->exists();
    }
}
