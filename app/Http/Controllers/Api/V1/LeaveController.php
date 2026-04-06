<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApproveLeaveRequest;
use App\Http\Requests\Api\V1\BulkApproveLeavesRequest;
use App\Http\Requests\Api\V1\BulkRejectLeavesRequest;
use App\Http\Requests\Api\V1\LeaveAnalyticsRequest;
use App\Http\Requests\Api\V1\LeaveCalendarRequest;
use App\Http\Requests\Api\V1\LeaveSummaryRequest;
use App\Http\Requests\Api\V1\ListLeavesRequest;
use App\Http\Requests\Api\V1\RejectLeaveRequest;
use App\Http\Requests\Api\V1\StoreLeaveRequest;
use App\Http\Requests\Api\V1\UpdateLeaveRequest;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Services\Leave\LeaveApprovalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeaveController extends Controller
{
    public function types(): JsonResponse
    {
        if (! Schema::hasTable('leave_settings')) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $columns = ['id', 'type', 'days', 'eligibility', 'carry_forward', 'earned_leave', 'requires_approval', 'auto_approve', 'special_conditions'];

        if (Schema::hasColumn('leave_settings', 'symbol')) {
            $columns[] = 'symbol';
        }

        if (Schema::hasColumn('leave_settings', 'is_earned')) {
            $columns[] = 'is_earned';
        }

        $leaveTypes = LeaveSetting::query()
            ->select($columns)
            ->orderBy('type')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $leaveTypes,
        ]);
    }

    public function index(ListLeavesRequest $request): JsonResponse
    {
        if (! Schema::hasTable('leaves')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'leaves' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 10,
                        'total' => 0,
                    ],
                ],
            ]);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return response()->json([
                'success' => false,
                'message' => 'Leave schema is misconfigured.',
            ], 500);
        }

        $perPage = (int) $request->input('perPage', 10);
        $page = (int) $request->input('page', 1);

        $query = DB::table('leaves')
            ->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->where("leaves.{$userColumn}", $request->user()->id);

        if ($request->filled('status')) {
            $query->where('leaves.status', $request->input('status'));
        }

        if ($request->filled('year')) {
            $query->whereYear('leaves.from_date', (int) $request->input('year'));
        }

        if ($request->filled('month')) {
            $query->whereMonth('leaves.from_date', (int) $request->input('month'));
        }

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

        $total = (clone $query)->count('leaves.id');

        $leaves = $query
            ->select($selectColumns)
            ->orderByDesc('leaves.from_date')
            ->orderByDesc('leaves.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(function ($leave) {
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
            })
            ->values();

        $lastPage = max(1, (int) ceil($total / max($perPage, 1)));

        return response()->json([
            'success' => true,
            'data' => [
                'leaves' => $leaves,
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
        ]);
    }

    public function show(Request $request, int $leaveId): JsonResponse
    {
        if (! Schema::hasTable('leaves')) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return response()->json([
                'success' => false,
                'message' => 'Leave schema is misconfigured.',
            ], 500);
        }

        $leave = DB::table('leaves')
            ->where('id', $leaveId)
            ->first();

        if (! $leave) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        if ((int) ($leave->{$userColumn} ?? 0) !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this leave request.',
            ], 403);
        }

        $query = DB::table('leaves')
            ->where('leaves.id', $leaveId);

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

        if (Schema::hasColumn('leaves', 'submitted_at')) {
            $selectColumns[] = 'leaves.submitted_at';
        }

        if (Schema::hasColumn('leaves', 'approved_at')) {
            $selectColumns[] = 'leaves.approved_at';
        }

        $leaveDetails = $query->select($selectColumns)->first();

        if (! $leaveDetails) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $leaveDetails->id,
                'user_id' => (int) $leaveDetails->user_id,
                'leave_type' => (int) $leaveDetails->leave_type,
                'leave_type_name' => $leaveDetails->leave_type_name,
                'leave_type_symbol' => $leaveDetails->leave_type_symbol,
                'from_date' => $leaveDetails->from_date,
                'to_date' => $leaveDetails->to_date,
                'no_of_days' => (int) $leaveDetails->no_of_days,
                'reason' => $leaveDetails->reason,
                'status' => $leaveDetails->status,
                'submitted_at' => $leaveDetails->submitted_at ?? null,
                'approved_at' => $leaveDetails->approved_at ?? null,
                'created_at' => $leaveDetails->created_at,
                'updated_at' => $leaveDetails->updated_at,
            ],
        ]);
    }

    public function calendar(LeaveCalendarRequest $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        $leaveTypeId = $request->filled('leave_type_id') ? (int) $request->input('leave_type_id') : null;
        $includeHolidays = $request->boolean('include_holidays', true);

        $startOfMonth = Carbon::create($year, $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        if ($leaveTypeId !== null && Schema::hasTable('leave_settings') && ! LeaveSetting::query()->whereKey($leaveTypeId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid leave type selected.',
            ], 422);
        }

        $leaves = [];
        $approvedLeaveDays = 0;
        $pendingLeaveDays = 0;
        $rejectedLeaveDays = 0;
        $totalLeaveDays = 0;

        if (Schema::hasTable('leaves')) {
            $userColumn = $this->resolveLeavesUserColumn();

            if (! $userColumn) {
                return response()->json([
                    'success' => false,
                    'message' => 'Leave schema is misconfigured.',
                ], 500);
            }

            $query = DB::table('leaves')
                ->where("leaves.{$userColumn}", (int) $request->user()->id)
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

        return response()->json([
            'success' => true,
            'data' => [
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
            ],
        ]);
    }

    public function summary(LeaveSummaryRequest $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $leaveTypeId = $request->filled('leave_type_id') ? $request->integer('leave_type_id') : null;

        if (! Schema::hasTable('leave_settings')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'totals' => [
                        'allocated' => 0,
                        'used' => 0,
                        'pending' => 0,
                        'rejected' => 0,
                        'balance' => 0,
                        'usage_percentage' => 0,
                    ],
                    'by_type' => [],
                ],
            ]);
        }

        $leaveTypesQuery = LeaveSetting::query()
            ->select(['id', 'type', 'symbol', 'days', 'requires_approval', 'auto_approve'])
            ->orderBy('type');

        if ($leaveTypeId) {
            $leaveTypesQuery->where('id', $leaveTypeId);
        }

        $leaveTypes = $leaveTypesQuery->get();

        if ($leaveTypes->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'totals' => [
                        'allocated' => 0,
                        'used' => 0,
                        'pending' => 0,
                        'rejected' => 0,
                        'balance' => 0,
                        'usage_percentage' => 0,
                    ],
                    'by_type' => [],
                ],
            ]);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return response()->json([
                'success' => false,
                'message' => 'Leave schema is misconfigured.',
            ], 500);
        }

        if (! Schema::hasTable('leaves')) {
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

            return response()->json([
                'success' => true,
                'data' => [
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
                ],
            ]);
        }

        $rawLeaveUsage = DB::table('leaves')
            ->where("leaves.{$userColumn}", (int) $request->user()->id)
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

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'totals' => [
                    ...$totals,
                    'usage_percentage' => $usagePercentage,
                ],
                'by_type' => $byType,
            ],
        ]);
    }

    public function analytics(LeaveAnalyticsRequest $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $leaveTypeId = $request->filled('leave_type_id') ? (int) $request->input('leave_type_id') : null;

        $statusBreakdown = [
            'approved' => ['requests' => 0, 'days' => 0],
            'pending' => ['requests' => 0, 'days' => 0],
            'rejected' => ['requests' => 0, 'days' => 0],
            'other' => ['requests' => 0, 'days' => 0],
        ];

        $monthly = $this->buildMonthlyAnalyticsTemplate();

        if (! Schema::hasTable('leaves')) {
            return response()->json([
                'success' => true,
                'data' => [
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
                ],
            ]);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return response()->json([
                'success' => false,
                'message' => 'Leave schema is misconfigured.',
            ], 500);
        }

        if ($leaveTypeId !== null && Schema::hasTable('leave_settings') && ! LeaveSetting::query()->whereKey($leaveTypeId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid leave type selected.',
            ], 422);
        }

        $hasLeaveSettings = Schema::hasTable('leave_settings');

        $query = DB::table('leaves')
            ->where("leaves.{$userColumn}", (int) $request->user()->id)
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

        return response()->json([
            'success' => true,
            'data' => [
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
            ],
        ]);
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        if (! Schema::hasTable('leaves') || ! Schema::hasTable('leave_settings')) {
            return response()->json([
                'success' => false,
                'message' => 'Leave configuration is unavailable.',
            ], 500);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return response()->json([
                'success' => false,
                'message' => 'Leave schema is misconfigured.',
            ], 500);
        }

        $user = $request->user();
        $leaveType = LeaveSetting::query()->find($request->integer('leave_type_id'));

        if (! $leaveType) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid leave type selected.',
            ], 422);
        }

        $fromDate = Carbon::parse($request->input('from_date'))->startOfDay();
        $toDate = Carbon::parse($request->input('to_date'))->startOfDay();

        if ($fromDate->lt(now()->startOfDay())) {
            return response()->json([
                'success' => false,
                'message' => 'Leave cannot be applied for past dates.',
            ], 422);
        }

        if ($this->hasOverlappingLeave($userColumn, (int) $user->id, $fromDate, $toDate)) {
            return response()->json([
                'success' => false,
                'message' => 'Leave dates overlap with an existing leave request.',
            ], 422);
        }

        if ($this->hasOverlappingHoliday($fromDate, $toDate)) {
            return response()->json([
                'success' => false,
                'message' => 'Selected dates overlap with a holiday.',
            ], 422);
        }

        $daysCount = $fromDate->diffInDays($toDate) + 1;
        $status = (! $leaveType->requires_approval || $leaveType->auto_approve) ? 'Approved' : 'New';

        $payload = [
            'leave_type' => $leaveType->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'no_of_days' => $daysCount,
            'reason' => $request->input('reason'),
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

        if ($status === 'Approved' && Schema::hasColumn('leaves', 'approved_at')) {
            $payload['approved_at'] = now();
        }

        $leaveId = DB::table('leaves')->insertGetId($payload);

        $createdLeave = DB::table('leaves')
            ->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->where('leaves.id', $leaveId)
            ->select([
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
            ])
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully.',
            'data' => $createdLeave,
        ], 201);
    }

    public function update(UpdateLeaveRequest $request, int $leaveId): JsonResponse
    {
        if (! Schema::hasTable('leaves') || ! Schema::hasTable('leave_settings')) {
            return response()->json([
                'success' => false,
                'message' => 'Leave configuration is unavailable.',
            ], 500);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return response()->json([
                'success' => false,
                'message' => 'Leave schema is misconfigured.',
            ], 500);
        }

        $leave = DB::table('leaves')->where('id', $leaveId)->first();

        if (! $leave) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        $ownerId = (int) ($leave->{$userColumn} ?? 0);
        if ($ownerId !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update this leave request.',
            ], 403);
        }

        $currentStatus = strtolower((string) ($leave->status ?? ''));
        if (in_array($currentStatus, ['approved', 'declined', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This leave request can no longer be updated.',
            ], 422);
        }

        $leaveType = LeaveSetting::query()->find($request->integer('leave_type_id'));

        if (! $leaveType) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid leave type selected.',
            ], 422);
        }

        $fromDate = Carbon::parse($request->input('from_date'))->startOfDay();
        $toDate = Carbon::parse($request->input('to_date'))->startOfDay();

        if ($fromDate->lt(now()->startOfDay())) {
            return response()->json([
                'success' => false,
                'message' => 'Leave cannot be applied for past dates.',
            ], 422);
        }

        if ($this->hasOverlappingLeave($userColumn, (int) $request->user()->id, $fromDate, $toDate, $leaveId)) {
            return response()->json([
                'success' => false,
                'message' => 'Leave dates overlap with an existing leave request.',
            ], 422);
        }

        if ($this->hasOverlappingHoliday($fromDate, $toDate)) {
            return response()->json([
                'success' => false,
                'message' => 'Selected dates overlap with a holiday.',
            ], 422);
        }

        $status = (! $leaveType->requires_approval || $leaveType->auto_approve) ? 'Approved' : 'New';

        $updatePayload = [
            'leave_type' => $leaveType->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'no_of_days' => $fromDate->diffInDays($toDate) + 1,
            'reason' => $request->input('reason'),
            'status' => $status,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('leaves', 'submitted_at')) {
            $updatePayload['submitted_at'] = now();
        }

        if (Schema::hasColumn('leaves', 'approved_at')) {
            $updatePayload['approved_at'] = $status === 'Approved' ? now() : null;
        }

        DB::table('leaves')->where('id', $leaveId)->update($updatePayload);

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

        $updatedLeave = DB::table('leaves')
            ->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->where('leaves.id', $leaveId)
            ->select($selectColumns)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Leave request updated successfully.',
            'data' => $updatedLeave,
        ]);
    }

    public function destroy(Request $request, int $leaveId): JsonResponse
    {
        if (! Schema::hasTable('leaves')) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return response()->json([
                'success' => false,
                'message' => 'Leave schema is misconfigured.',
            ], 500);
        }

        $leave = DB::table('leaves')->where('id', $leaveId)->first();

        if (! $leave) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        $ownerId = (int) ($leave->{$userColumn} ?? 0);
        if ($ownerId !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to cancel this leave request.',
            ], 403);
        }

        $status = strtolower((string) ($leave->status ?? ''));
        if (in_array($status, ['approved', 'declined', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This leave request can no longer be cancelled.',
            ], 422);
        }

        DB::table('leaves')->where('id', $leaveId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave request cancelled successfully.',
        ]);
    }

    public function pendingApprovals(Request $request, LeaveApprovalService $approvalService): JsonResponse
    {
        if (! Schema::hasTable('leaves')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'pending_leaves' => [],
                    'stats' => [
                        'pending' => 0,
                        'approved' => 0,
                        'rejected' => 0,
                        'total' => 0,
                    ],
                ],
            ]);
        }

        $approver = $request->user();

        $pendingLeaves = Leave::query()
            ->with([
                'employee:id,name,employee_id,profile_image',
                'leaveSetting:id,type,symbol',
            ])
            ->whereNotNull('approval_chain')
            ->whereRaw('LOWER(status) = ?', ['pending'])
            ->orderByDesc('from_date')
            ->get()
            ->filter(function (Leave $leave) use ($approvalService, $approver): bool {
                $this->normalizePendingStatus($leave);

                return $approvalService->canApprove($leave, $approver);
            })
            ->values();

        $stats = $this->buildApprovalStats((int) $approver->id, $pendingLeaves);

        return response()->json([
            'success' => true,
            'data' => [
                'pending_leaves' => $pendingLeaves->map(function (Leave $leave) {
                    return $this->transformApprovalLeave($leave);
                })->values(),
                'stats' => $stats,
            ],
        ]);
    }

    public function approve(ApproveLeaveRequest $request, int $leaveId, LeaveApprovalService $approvalService): JsonResponse
    {
        $leave = Leave::query()->with(['employee', 'leaveSetting'])->find($leaveId);

        if (! $leave) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        $this->normalizePendingStatus($leave);

        $result = $approvalService->approve($leave, $request->user(), $request->input('comments'));

        if (! ($result['success'] ?? false)) {
            return response()->json($result, 403);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Leave approved successfully.',
            'data' => [
                'leave' => $this->transformApprovalLeave($leave->fresh(['employee', 'leaveSetting'])),
                'status' => $result['status'] ?? null,
            ],
        ]);
    }

    public function reject(RejectLeaveRequest $request, int $leaveId, LeaveApprovalService $approvalService): JsonResponse
    {
        $leave = Leave::query()->with(['employee', 'leaveSetting'])->find($leaveId);

        if (! $leave) {
            return response()->json([
                'success' => false,
                'message' => 'Leave request not found.',
            ], 404);
        }

        $this->normalizePendingStatus($leave);

        $result = $approvalService->reject($leave, $request->user(), $request->input('reason'));

        if (! ($result['success'] ?? false)) {
            return response()->json($result, 403);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Leave rejected successfully.',
            'data' => [
                'leave' => $this->transformApprovalLeave($leave->fresh(['employee', 'leaveSetting'])),
                'status' => $result['status'] ?? null,
            ],
        ]);
    }

    public function bulkApprove(BulkApproveLeavesRequest $request, LeaveApprovalService $approvalService): JsonResponse
    {
        $approver = $request->user();
        $leaveIds = collect($request->input('leave_ids', []))
            ->map(fn ($leaveId) => (int) $leaveId)
            ->unique()
            ->values();

        $leaves = Leave::query()
            ->with(['employee', 'leaveSetting'])
            ->whereIn('id', $leaveIds->all())
            ->get()
            ->keyBy('id');

        $approvedLeaveIds = [];
        $failed = [];

        foreach ($leaveIds as $leaveId) {
            /** @var Leave|null $leave */
            $leave = $leaves->get($leaveId);

            if (! $leave) {
                $failed[] = [
                    'leave_id' => $leaveId,
                    'message' => 'Leave request not found.',
                ];

                continue;
            }

            $this->normalizePendingStatus($leave);

            $result = $approvalService->approve($leave, $approver, $request->input('comments'));

            if ($result['success'] ?? false) {
                $approvedLeaveIds[] = $leaveId;

                continue;
            }

            $failed[] = [
                'leave_id' => $leaveId,
                'message' => $result['message'] ?? 'Failed to approve leave request.',
            ];
        }

        if ($approvedLeaveIds === []) {
            return response()->json([
                'success' => false,
                'message' => 'No leave requests were approved.',
                'data' => [
                    'approved_count' => 0,
                    'failed_count' => count($failed),
                    'total_requested' => $leaveIds->count(),
                    'approved_leave_ids' => [],
                    'failed' => $failed,
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => count($approvedLeaveIds).' leave request(s) approved successfully.',
            'data' => [
                'approved_count' => count($approvedLeaveIds),
                'failed_count' => count($failed),
                'total_requested' => $leaveIds->count(),
                'approved_leave_ids' => $approvedLeaveIds,
                'failed' => $failed,
            ],
        ]);
    }

    public function bulkReject(BulkRejectLeavesRequest $request, LeaveApprovalService $approvalService): JsonResponse
    {
        $approver = $request->user();
        $leaveIds = collect($request->input('leave_ids', []))
            ->map(fn ($leaveId) => (int) $leaveId)
            ->unique()
            ->values();

        $leaves = Leave::query()
            ->with(['employee', 'leaveSetting'])
            ->whereIn('id', $leaveIds->all())
            ->get()
            ->keyBy('id');

        $rejectedLeaveIds = [];
        $failed = [];

        foreach ($leaveIds as $leaveId) {
            /** @var Leave|null $leave */
            $leave = $leaves->get($leaveId);

            if (! $leave) {
                $failed[] = [
                    'leave_id' => $leaveId,
                    'message' => 'Leave request not found.',
                ];

                continue;
            }

            $this->normalizePendingStatus($leave);

            $result = $approvalService->reject($leave, $approver, $request->input('reason'));

            if ($result['success'] ?? false) {
                $rejectedLeaveIds[] = $leaveId;

                continue;
            }

            $failed[] = [
                'leave_id' => $leaveId,
                'message' => $result['message'] ?? 'Failed to reject leave request.',
            ];
        }

        if ($rejectedLeaveIds === []) {
            return response()->json([
                'success' => false,
                'message' => 'No leave requests were rejected.',
                'data' => [
                    'rejected_count' => 0,
                    'failed_count' => count($failed),
                    'total_requested' => $leaveIds->count(),
                    'rejected_leave_ids' => [],
                    'failed' => $failed,
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => count($rejectedLeaveIds).' leave request(s) rejected successfully.',
            'data' => [
                'rejected_count' => count($rejectedLeaveIds),
                'failed_count' => count($failed),
                'total_requested' => $leaveIds->count(),
                'rejected_leave_ids' => $rejectedLeaveIds,
                'failed' => $failed,
            ],
        ]);
    }

    private function resolveLeavesUserColumn(): ?string
    {
        if (Schema::hasColumn('leaves', 'user_id')) {
            return 'user_id';
        }

        if (Schema::hasColumn('leaves', 'user')) {
            return 'user';
        }

        return null;
    }

    private function normalizePendingStatus(Leave $leave): void
    {
        if (strtolower((string) $leave->status) === 'pending') {
            $leave->status = 'pending';
        }
    }

    private function transformApprovalLeave(Leave $leave): array
    {
        return [
            'id' => $leave->id,
            'user_id' => $leave->user_id,
            'employee' => [
                'id' => $leave->employee?->id,
                'name' => $leave->employee?->name,
                'employee_id' => $leave->employee?->employee_id,
                'profile_image' => $leave->employee?->profile_image,
            ],
            'leave_type' => $leave->leave_type,
            'leave_type_name' => $leave->leaveSetting?->type,
            'leave_type_symbol' => $leave->leaveSetting?->symbol,
            'from_date' => $leave->from_date,
            'to_date' => $leave->to_date,
            'no_of_days' => $leave->no_of_days,
            'reason' => $leave->reason,
            'status' => $leave->status,
            'current_approval_level' => $leave->current_approval_level,
            'approval_chain' => $leave->approval_chain,
            'submitted_at' => $leave->submitted_at,
            'approved_at' => $leave->approved_at,
            'rejection_reason' => $leave->rejection_reason,
            'created_at' => $leave->created_at,
            'updated_at' => $leave->updated_at,
        ];
    }

    private function buildApprovalStats(int $approverId, Collection $pendingLeaves): array
    {
        $reviewedLeaves = Leave::query()
            ->whereNotNull('approval_chain')
            ->whereRaw("LOWER(status) IN ('approved', 'rejected')")
            ->get();

        $approved = 0;
        $rejected = 0;

        foreach ($reviewedLeaves as $leave) {
            foreach (($leave->approval_chain ?? []) as $level) {
                if ((int) ($level['approver_id'] ?? 0) !== $approverId) {
                    continue;
                }

                $levelStatus = strtolower((string) ($level['status'] ?? ''));

                if ($levelStatus === 'approved') {
                    $approved++;
                    break;
                }

                if ($levelStatus === 'rejected') {
                    $rejected++;
                    break;
                }
            }
        }

        $pending = $pendingLeaves->count();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
        ];
    }

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

    private function hasOverlappingLeave(string $userColumn, int $userId, Carbon $fromDate, Carbon $toDate, ?int $exceptLeaveId = null): bool
    {
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
