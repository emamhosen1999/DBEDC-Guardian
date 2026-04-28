<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\RfiObjection;
use App\Models\User;
use App\Services\Leave\LeaveApprovalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManagerDashboardController extends Controller
{
    public function summary(Request $request, LeaveApprovalService $approvalService): JsonResponse
    {
        $user = $request->user();

        if (! $this->isManagerUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access manager dashboard summary.',
            ], 403);
        }

        $today = now()->toDateString();
        $teamMemberIds = $this->resolveTeamMemberIds($user);
        $workloadUserIds = collect($teamMemberIds)
            ->push((int) $user->id)
            ->unique()
            ->values()
            ->all();

        $presentToday = 0;
        if ($teamMemberIds !== []) {
            $presentToday = Attendance::query()
                ->whereIn('user_id', $teamMemberIds)
                ->whereDate('date', $today)
                ->distinct()
                ->count('user_id');
        }

        $onLeaveToday = $this->countTeamOnLeaveToday($teamMemberIds, $today);
        $pendingLeaveApprovals = $this->countPendingLeaveApprovals($user, $approvalService);

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
        $dailyWorkIds = (clone $dailyWorkBaseQuery)->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $objectionStats = $this->buildObjectionStats($dailyWorkIds);
        $leaveWindows = $this->buildTeamLeaveWindows($teamMemberIds, $today);
        $upcomingHolidays = $this->buildUpcomingHolidays($today);

        return response()->json([
            'success' => true,
            'data' => [
                'team' => [
                    'total_members' => count($teamMemberIds),
                    'present_today' => $presentToday,
                    'on_leave_today' => $onLeaveToday,
                ],
                'approvals' => [
                    'pending_leave_approvals' => $pendingLeaveApprovals,
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
            ],
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
        $hasMemberCodeColumn = Schema::hasColumn('users', 'employee_id');
        $hasProfileImageColumn = Schema::hasColumn('users', 'profile_image');

        $leaveQuery = DB::table('leaves')
            ->leftJoin('users', "leaves.{$userColumn}", '=', 'users.id')
            ->whereIn("leaves.{$userColumn}", $teamMemberIds)
            ->whereDate('leaves.from_date', '<=', $queryRangeEnd)
            ->whereDate('leaves.to_date', '>=', $queryRangeStart)
            ->whereRaw("LOWER(COALESCE(leaves.status, '')) NOT IN ('declined', 'rejected', 'cancelled', 'canceled')");

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

        if ($hasMemberCodeColumn) {
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

    private function resolveTeamMemberIds(User $user): array
    {
        if ($this->isAdminLikeUser($user)) {
            return User::query()
                ->where('active', true)
                ->where('id', '!=', $user->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        return User::query()
            ->where('active', true)
            ->where('report_to', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
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

        return Leave::query()
            ->whereNotNull('approval_chain')
            ->whereRaw('LOWER(status) = ?', ['pending'])
            ->get()
            ->filter(function (Leave $leave) use ($approvalService, $user): bool {
                $this->normalizePendingStatus($leave);

                return $approvalService->canApprove($leave, $user);
            })
            ->count();
    }

    private function buildObjectionStats(array $dailyWorkIds): array
    {
        if ($dailyWorkIds === [] || ! Schema::hasTable('rfi_objections')) {
            return [
                'submitted' => 0,
                'under_review' => 0,
                'total_active' => 0,
            ];
        }

        $hasPivotTable = Schema::hasTable('daily_work_objection');
        if (! $hasPivotTable) {
            return [
                'submitted' => 0,
                'under_review' => 0,
                'total_active' => 0,
            ];
        }

        $objections = RfiObjection::query()
            ->select('rfi_objections.id', 'rfi_objections.status')
            ->whereHas('dailyWorks', function ($dailyWorkQuery) use ($dailyWorkIds) {
                $dailyWorkQuery->whereIn('daily_works.id', $dailyWorkIds);
            })
            ->distinct()
            ->get();

        $submitted = $objections->filter(function ($objection): bool {
            return strtolower((string) $objection->status) === RfiObjection::STATUS_SUBMITTED;
        })->count();

        $underReview = $objections->filter(function ($objection): bool {
            return strtolower((string) $objection->status) === RfiObjection::STATUS_UNDER_REVIEW;
        })->count();

        $totalActive = $objections->filter(function ($objection): bool {
            return in_array(strtolower((string) $objection->status), RfiObjection::$activeStatuses, true);
        })->count();

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

    private function isManagerUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Project Manager',
            'Consultant',
            'Super Administrator',
            'Administrator',
        ]);
    }

    private function isAdminLikeUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Super Administrator',
            'Administrator',
        ]);
    }
}
