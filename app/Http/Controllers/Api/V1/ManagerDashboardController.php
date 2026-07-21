<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\RfiObjection;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\DailyWorkRepository;
use App\Repositories\LeaveRepository;
use App\Services\Attendance\UpcomingShiftService;
use App\Services\Leave\LeaveApprovalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;

class ManagerDashboardController extends Controller
{
    use ResolvesTeamMembers;
    use ApiResponse;

    protected AttendanceRepository $attendanceRepository;

    protected DailyWorkRepository $dailyWorkRepository;

    protected LeaveRepository $leaveRepository;

    public function __construct(
        AttendanceRepository $attendanceRepository,
        DailyWorkRepository $dailyWorkRepository,
        LeaveRepository $leaveRepository
    ) {
        $this->attendanceRepository = $attendanceRepository;
        $this->dailyWorkRepository = $dailyWorkRepository;
        $this->leaveRepository = $leaveRepository;
    }

    public function summary(
        Request $request,
        LeaveApprovalService $approvalService,
        UpcomingShiftService $upcomingShiftService
    ): JsonResponse {
        $user = $request->user();

        if (! $this->isManagerUser($user)) {
            return $this->errorResponse('You are not authorized to access manager dashboard summary.', null, 403);
        }

        $today = now()->toDateString();
        $teamMemberIds = $this->resolveTeamMemberIds($user);
        $workloadUserIds = collect($teamMemberIds)
            ->push((int) $user->id)
            ->unique()
            ->values()
            ->all();

        // Present = team members with a punch for today. Plucking the ids (not just a
        // count) lets the today-based split reuse the exact non-present set, so
        // present + absent + off + upcoming == the whole team, consistent with the
        // team-attendance screen.
        $presentUserIds = [];
        if ($teamMemberIds !== []) {
            $presentUserIds = Attendance::query()
                ->whereIn('user_id', $teamMemberIds)
                ->whereDate('date', $today)
                ->distinct()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $presentToday = count($presentUserIds);

        // Split the non-present remainder into absent / off / upcoming using the same
        // today partition the team-attendance endpoint uses (read-only call). A member
        // rostered but not yet started is "upcoming", a non-working day is "off", and a
        // started-but-unpunched shift is "absent" — this replaces the client's stale
        // total - present - onLeave derivation that lumped off + upcoming into absent.
        $absentToday = 0;
        $offToday = 0;
        $upcomingToday = 0;

        if ($teamMemberIds !== []) {
            $presentIdLookup = array_flip($presentUserIds);
            $nonPresentUsers = User::query()
                ->whereIn('id', $teamMemberIds)
                ->get()
                ->reject(fn (User $member) => isset($presentIdLookup[(int) $member->id]))
                ->values();

            $todayPartition = $upcomingShiftService->todayPartition(Carbon::parse($today), $nonPresentUsers);
            $absentToday = $todayPartition['absent']->count();
            $offToday = $todayPartition['off']->count();
            $upcomingToday = $todayPartition['upcoming']->count();
        }

        $onLeaveToday = $this->countTeamOnLeaveToday($teamMemberIds, $today);
        $pendingLeaveApprovals = $this->countPendingLeaveApprovals($user, $approvalService);

        $pendingSwapApprovals = 0;
        if ($teamMemberIds !== []) {
            $pendingSwapApprovals = \App\Models\HRM\ShiftSwapRequest::whereIn('requester_id', $teamMemberIds)
                ->where('status', 'pending')
                ->where('counterparty_status', 'approved')
                ->count();
        }

        $dailyWorkBaseQuery = DailyWork::query();
        if ($workloadUserIds === []) {
            $dailyWorkBaseQuery->whereRaw('1 = 0');
        } else {
            $dailyWorkBaseQuery->where(function ($query) use ($workloadUserIds) {
                $query->whereIn('incharge', $workloadUserIds)
                    ->orWhereIn('assigned', $workloadUserIds);
            });
        }

        $dailyWorkTotal = (clone $dailyWorkBaseQuery)->count();
        $dailyWorkCompleted = (clone $dailyWorkBaseQuery)->where('status', DailyWork::STATUS_COMPLETED)->count();
        $dailyWorkPending = max(0, $dailyWorkTotal - $dailyWorkCompleted);

        // The in-scope daily-work id set is passed to the objection stats as a SUBQUERY,
        // never as a materialised PHP array. On the live data the widest manager (285
        // reports) scopes 19,021 daily works: plucking those ids cost ~32ms of SQL plus
        // ~400ms of PHP building a 19k-element `whereIn` binding list.
        $objectionStats = $this->buildObjectionStats(clone $dailyWorkBaseQuery);
        $leaveWindows = $this->buildTeamLeaveWindows($teamMemberIds, $today);
        $upcomingHolidays = $this->buildUpcomingHolidays($today);

        return $this->successResponse([
            'team' => [
                'total_members' => count($teamMemberIds),
                'present_today' => $presentToday,
                'on_leave_today' => $onLeaveToday,
                'absent_today' => $absentToday,
                'off_today' => $offToday,
                'upcoming_today' => $upcomingToday,
            ],
            'approvals' => [
                'pending_leave_approvals' => $pendingLeaveApprovals,
                'pending_swap_approvals' => $pendingSwapApprovals,
            ],
            'daily_works' => [
                'total' => $dailyWorkTotal,
                'completed' => $dailyWorkCompleted,
                'pending' => $dailyWorkPending,
            ],
            'objections' => [
                'submitted' => $objectionStats['submitted'],
                'under_review' => $objectionStats['under_review'],
                'total_active' => $objectionStats['total_active'],
            ],
            'leave_windows' => $leaveWindows,
            'upcoming_holidays' => $upcomingHolidays,
        ]);
    }

    private function buildTeamLeaveWindows(array $teamMemberIds, string $today): array
    {
        $todayDate = Carbon::parse($today)->startOfDay();

        $windowDefinitions = [
            [
                'key' => 'today',
                'label' => 'Today',
                'from' => $todayDate->copy(),
                'to' => $todayDate->copy(),
            ],
            [
                'key' => 'tomorrow',
                'label' => 'Tomorrow',
                'from' => $todayDate->copy()->addDay(),
                'to' => $todayDate->copy()->addDay(),
            ],
            [
                'key' => 'next-seven-days',
                'label' => 'Next Seven Days',
                'from' => $todayDate->copy()->addDays(2),
                'to' => $todayDate->copy()->addDays(8),
            ],
        ];

        if ($teamMemberIds === [] || ! Schema::hasTable('leaves')) {
            return array_map(function (array $window): array {
                return $this->buildEmptyLeaveWindow($window);
            }, $windowDefinitions);
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return array_map(function (array $window): array {
                return $this->buildEmptyLeaveWindow($window);
            }, $windowDefinitions);
        }

        $queryRangeStart = $windowDefinitions[0]['from']->toDateString();
        $queryRangeEnd = $windowDefinitions[2]['to']->toDateString();
        $hasLeaveSettingsTable = Schema::hasTable('leave_settings');
        $hasLeaveSymbolColumn = $hasLeaveSettingsTable && Schema::hasColumn('leave_settings', 'symbol');
        $hasEmployeeCodeColumn = Schema::hasColumn('users', 'employee_id');
        $hasProfileImageColumn = Schema::hasColumn('users', 'profile_image');

        $leaveQuery = DB::table('leaves')
            ->leftJoin('users', "leaves.{$userColumn}", '=', 'users.id')
            ->whereIn("leaves.{$userColumn}", $teamMemberIds)
            ->whereDate('leaves.from_date', '<=', $queryRangeEnd)
            ->whereDate('leaves.to_date', '>=', $queryRangeStart)
            ->whereRaw("LOWER(COALESCE(leaves.status, '')) NOT IN (?, ?, ?, ?)", ['declined', 'rejected', 'cancelled', 'canceled']);

        if ($hasLeaveSettingsTable) {
            $leaveQuery->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id');
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
            'users.id as employee_id',
            'users.name as employee_name',
        ];

        if ($hasEmployeeCodeColumn) {
            $selectColumns[] = 'users.employee_id as employee_code';
        } else {
            $selectColumns[] = DB::raw('NULL as employee_code');
        }

        if ($hasProfileImageColumn) {
            $selectColumns[] = 'users.profile_image as profile_image';
        } else {
            $selectColumns[] = DB::raw('NULL as profile_image');
        }

        if ($hasLeaveSettingsTable) {
            $selectColumns[] = 'leave_settings.type as leave_type_name';

            if ($hasLeaveSymbolColumn) {
                $selectColumns[] = 'leave_settings.symbol as leave_type_symbol';
            } else {
                $selectColumns[] = DB::raw('NULL as leave_type_symbol');
            }
        } else {
            $selectColumns[] = DB::raw('NULL as leave_type_name');
            $selectColumns[] = DB::raw('NULL as leave_type_symbol');
        }

        $leaveRows = $leaveQuery
            ->select($selectColumns)
            ->orderBy('leaves.from_date')
            ->orderBy('leaves.id')
            ->get();

        return array_map(function (array $window) use ($leaveRows): array {
            $fromDate = $window['from']->toDateString();
            $toDate = $window['to']->toDateString();

            $matchingLeaves = $leaveRows->filter(function ($leaveRow) use ($fromDate, $toDate): bool {
                $leaveFromDate = (string) ($leaveRow->from_date ?? '');
                $leaveToDate = (string) ($leaveRow->to_date ?? '');

                if ($leaveFromDate === '' || $leaveToDate === '') {
                    return false;
                }

                return $this->hasDateOverlap($leaveFromDate, $leaveToDate, $fromDate, $toDate);
            })->values();

            $membersById = [];

            foreach ($matchingLeaves as $leaveRow) {
                $memberId = (int) ($leaveRow->user_id ?? 0);

                if ($memberId <= 0) {
                    continue;
                }

                $statusBucket = $this->normalizeLeaveStatusBucket((string) ($leaveRow->status ?? ''));

                if (! isset($membersById[$memberId])) {
                    $membersById[$memberId] = [
                        'user_id' => $memberId,
                        'employee' => [
                            'id' => (int) ($leaveRow->employee_id ?? $memberId),
                            'name' => (string) ($leaveRow->employee_name ?? 'Team Member'),
                            'employee_id' => $leaveRow->employee_code,
                            'profile_image' => $leaveRow->profile_image,
                        ],
                        'status_bucket' => $statusBucket,
                        'leaves' => [],
                    ];
                }

                $membersById[$memberId]['leaves'][] = [
                    'id' => (int) ($leaveRow->id ?? 0),
                    'leave_type' => (int) ($leaveRow->leave_type ?? 0),
                    'leave_type_name' => $leaveRow->leave_type_name,
                    'leave_type_symbol' => $leaveRow->leave_type_symbol,
                    'from_date' => $leaveRow->from_date,
                    'to_date' => $leaveRow->to_date,
                    'no_of_days' => (int) ($leaveRow->no_of_days ?? 0),
                    'reason' => $leaveRow->reason,
                    'status' => $leaveRow->status,
                    'status_bucket' => $statusBucket,
                ];
            }

            $members = array_values(array_map(function (array $member): array {
                $memberLeaves = collect($member['leaves']);

                if ($memberLeaves->contains(function (array $leave): bool {
                    return ($leave['status_bucket'] ?? '') === 'approved';
                })) {
                    $member['status_bucket'] = 'approved';
                } elseif ($memberLeaves->contains(function (array $leave): bool {
                    return ($leave['status_bucket'] ?? '') === 'pending';
                })) {
                    $member['status_bucket'] = 'pending';
                }

                return $member;
            }, $membersById));

            usort($members, function (array $first, array $second): int {
                return strcmp((string) ($first['employee']['name'] ?? ''), (string) ($second['employee']['name'] ?? ''));
            });

            $approvedCount = collect($members)->filter(function (array $member): bool {
                return ($member['status_bucket'] ?? '') === 'approved';
            })->count();

            $pendingCount = collect($members)->filter(function (array $member): bool {
                return ($member['status_bucket'] ?? '') === 'pending';
            })->count();

            return [
                'key' => $window['key'],
                'label' => $window['label'],
                'from' => $fromDate,
                'to' => $toDate,
                'window_label' => $this->formatWindowLabel($window['from'], $window['to']),
                'total_count' => count($members),
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
                'members' => $members,
            ];
        }, $windowDefinitions);
    }

    private function buildUpcomingHolidays(string $today): array
    {
        if (! Schema::hasTable('holidays')) {
            return [];
        }

        $holidayQuery = DB::table('holidays')
            ->whereDate('to_date', '>=', $today);

        if (Schema::hasColumn('holidays', 'is_active')) {
            $holidayQuery->where('is_active', true);
        }

        $holidayColumns = [
            'id',
            'title',
            'from_date',
            'to_date',
        ];

        if (Schema::hasColumn('holidays', 'description')) {
            $holidayColumns[] = 'description';
        } else {
            $holidayColumns[] = DB::raw('NULL as description');
        }

        if (Schema::hasColumn('holidays', 'type')) {
            $holidayColumns[] = 'type';
        } else {
            $holidayColumns[] = DB::raw('NULL as type');
        }

        return $holidayQuery
            ->select($holidayColumns)
            ->orderBy('from_date')
            ->orderBy('id')
            ->limit(8)
            ->get()
            ->map(function ($holiday): array {
                return [
                    'id' => (int) ($holiday->id ?? 0),
                    'title' => $holiday->title,
                    'description' => $holiday->description,
                    'type' => $holiday->type,
                    'from_date' => $holiday->from_date,
                    'to_date' => $holiday->to_date,
                ];
            })
            ->values()
            ->all();
    }

    private function buildEmptyLeaveWindow(array $window): array
    {
        /** @var Carbon $fromDate */
        $fromDate = $window['from'];
        /** @var Carbon $toDate */
        $toDate = $window['to'];

        return [
            'key' => $window['key'],
            'label' => $window['label'],
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'window_label' => $this->formatWindowLabel($fromDate, $toDate),
            'total_count' => 0,
            'approved_count' => 0,
            'pending_count' => 0,
            'members' => [],
        ];
    }

    private function formatWindowLabel(Carbon $fromDate, Carbon $toDate): string
    {
        if ($fromDate->isSameDay($toDate)) {
            return $fromDate->format('M j');
        }

        return $fromDate->format('M j').' - '.$toDate->format('M j');
    }

    private function hasDateOverlap(string $rangeStart, string $rangeEnd, string $windowStart, string $windowEnd): bool
    {
        return $rangeStart <= $windowEnd && $rangeEnd >= $windowStart;
    }

    private function normalizeLeaveStatusBucket(string $status): string
    {
        $normalized = strtolower(trim($status));

        if ($normalized === 'approved') {
            return 'approved';
        }

        if (in_array($normalized, ['new', 'pending'], true)) {
            return 'pending';
        }

        if (in_array($normalized, ['rejected', 'declined', 'cancelled', 'canceled'], true)) {
            return 'rejected';
        }

        return 'other';
    }

    private function countTeamOnLeaveToday(array $teamMemberIds, string $today): int
    {
        if ($teamMemberIds === [] || ! Schema::hasTable('leaves')) {
            return 0;
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return 0;
        }

        return Leave::query()
            ->whereIn($userColumn, $teamMemberIds)
            ->whereDate('from_date', '<=', $today)
            ->whereDate('to_date', '>=', $today)
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->distinct()
            ->count($userColumn);
    }

    private function countPendingLeaveApprovals(User $user, LeaveApprovalService $approvalService): int
    {
        if (! Schema::hasTable('leaves')) {
            return 0;
        }

        // canApprove() reads only status / approval_chain / current_approval_level, so
        // hydrating every column of every pending leave is pure waste. The chain is JSON
        // and must still be evaluated in PHP — only the projection is narrowed here.
        return Leave::query()
            ->whereNotNull('approval_chain')
            ->whereRaw('LOWER(status) = ?', ['pending'])
            ->get(['id', 'status', 'approval_chain', 'current_approval_level'])
            ->filter(function (Leave $leave) use ($approvalService, $user): bool {
                $this->normalizePendingStatus($leave);

                return $approvalService->canApprove($leave, $user);
            })
            ->count();
    }

    /**
     * Objection buckets for every daily work in the manager's scope.
     *
     * $dailyWorkQuery is the in-scope daily-works builder and is consumed as a
     * SUBQUERY (`select id from daily_works where …`), so the id set never leaves
     * the database. Counting is likewise pushed into a single GROUP BY instead of
     * hydrating every matching objection and filtering three times in PHP.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<DailyWork>  $dailyWorkQuery
     */
    private function buildObjectionStats($dailyWorkQuery): array
    {
        $empty = [
            'submitted' => 0,
            'under_review' => 0,
            'total_active' => 0,
        ];

        if (! Schema::hasTable('rfi_objections')) {
            return $empty;
        }

        $hasPivotTable = Schema::hasTable('daily_work_objection');
        $hasLegacyColumn = Schema::hasColumn('rfi_objections', 'daily_work_id');

        if (! $hasPivotTable && ! $hasLegacyColumn) {
            return $empty;
        }

        $idSubQuery = (clone $dailyWorkQuery)->select('daily_works.id');

        $statusCounts = RfiObjection::query()
            ->where(function ($query) use ($idSubQuery, $hasLegacyColumn, $hasPivotTable) {
                if ($hasPivotTable) {
                    $query->whereHas('dailyWorks', function ($dailyWorkQuery) use ($idSubQuery) {
                        $dailyWorkQuery->whereIn('daily_works.id', $idSubQuery);
                    });
                }

                if ($hasLegacyColumn) {
                    if ($hasPivotTable) {
                        $query->orWhereIn('daily_work_id', $idSubQuery);
                    } else {
                        $query->whereIn('daily_work_id', $idSubQuery);
                    }
                }
            })
            ->select('rfi_objections.status')
            ->selectRaw('COUNT(DISTINCT rfi_objections.id) as aggregate')
            ->groupBy('rfi_objections.status')
            ->get();

        $submitted = 0;
        $underReview = 0;
        $totalActive = 0;

        foreach ($statusCounts as $row) {
            $normalized = strtolower((string) $row->status);
            $count = (int) $row->aggregate;

            if ($normalized === RfiObjection::STATUS_SUBMITTED) {
                $submitted += $count;
            }

            if ($normalized === RfiObjection::STATUS_UNDER_REVIEW) {
                $underReview += $count;
            }

            if (in_array($normalized, RfiObjection::$activeStatuses, true)) {
                $totalActive += $count;
            }
        }

        return [
            'submitted' => $submitted,
            'under_review' => $underReview,
            'total_active' => $totalActive,
        ];
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

    public function teamMembers(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->isManagerUser($user)) {
            return $this->errorResponse('Unauthorized.', null, 403);
        }

        $teamMemberIds = $this->resolveTeamMemberIds($user);

        // The avatar comes from the media library (profile_image_url accessor), NOT from a
        // users.profile_image column — that column does not exist in the live schema.
        $members = User::query()
            ->whereIn('id', $teamMemberIds)
            ->whereNull('deleted_at')
            ->with('media')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_id'])
            ->map(fn (User $member) => [
                'id' => (int) $member->id,
                'name' => $member->name,
                'employee_id' => $member->employee_id,
                'profile_image_url' => $member->profile_image_url,
            ])
            ->values();

        return $this->successResponse($members);
    }
}
