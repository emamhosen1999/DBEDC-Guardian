<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\RfiObjection;
use App\Models\User;
use App\Services\Leave\LeaveApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ],
        ]);
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
        $hasLegacyColumn = Schema::hasColumn('rfi_objections', 'daily_work_id');

        if (! $hasPivotTable && ! $hasLegacyColumn) {
            return [
                'submitted' => 0,
                'under_review' => 0,
                'total_active' => 0,
            ];
        }

        $objections = RfiObjection::query()
            ->select('rfi_objections.id', 'rfi_objections.status')
            ->where(function ($query) use ($dailyWorkIds, $hasLegacyColumn, $hasPivotTable) {
                if ($hasPivotTable) {
                    $query->whereHas('dailyWorks', function ($dailyWorkQuery) use ($dailyWorkIds) {
                        $dailyWorkQuery->whereIn('daily_works.id', $dailyWorkIds);
                    });
                }

                if ($hasLegacyColumn) {
                    if ($hasPivotTable) {
                        $query->orWhereIn('daily_work_id', $dailyWorkIds);
                    } else {
                        $query->whereIn('daily_work_id', $dailyWorkIds);
                    }
                }
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
