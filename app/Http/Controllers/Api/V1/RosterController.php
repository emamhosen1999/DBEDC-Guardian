<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Services\Attendance\RosterOverlayService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only roster feed for the mobile app. The payload deliberately mirrors the
 * web `/attendance/roster` shape so the ported grid components need no reshaping.
 * Cell editing stays web-only.
 */
class RosterController extends Controller
{
    use ApiResponse;
    use ResolvesTeamMembers;

    public function __construct(private readonly RosterOverlayService $overlay) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'nullable|integer',
        ]);

        $currentUser = $request->user();

        // A manager sees their reporting tree; anyone else sees only themselves.
        $userIds = $this->isManagerUser($currentUser)
            ? $this->resolveTeamMemberIds($currentUser)
            : [$currentUser->id];

        if ($userIds === []) {
            $userIds = [$currentUser->id];
        }

        $rows = RosterDay::with(['shift:id,code,color,name', 'user:id,name', 'user.media'])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$data['from'], $data['to']])
            ->when($data['department_id'] ?? null, fn ($q, $departmentId) => $q->whereHas(
                'user',
                fn ($uq) => $uq->where('department_id', $departmentId)
            ))
            ->orderBy('id')
            ->get();

        $rosterUserIds = $rows->pluck('user_id')->unique()->values()->all();

        return $this->successResponse($this->withOverlay(
            $this->formatRoster($rows, $this->buildWorkedSet($rosterUserIds, $data['from'], $data['to'])),
            $rosterUserIds,
            $data['from'],
            $data['to'],
        ));
    }

    public function shifts(): JsonResponse
    {
        return $this->successResponse([
            'shifts' => Shift::query()
                ->orderBy('start_time')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'color', 'type', 'start_time', 'end_time', 'crosses_midnight']),
        ]);
    }

    private function formatRoster(Collection $rows, array $workedSet): Collection
    {
        $today = Carbon::today()->toDateString();

        return $rows->groupBy('user_id')->map(function (Collection $userRows) use ($workedSet, $today) {
            $first = $userRows->first();

            return [
                'name' => $first->user?->name,
                'profile_image_url' => $first->user?->profile_image_url,
                'days' => $userRows->groupBy(fn ($row) => $row->date->format('Y-m-d'))
                    ->map(fn (Collection $dayRows, $date) => $this->formatCell($dayRows, $date, $workedSet, $today)),
            ];
        });
    }

    /**
     * @param  Collection<int, RosterDay>  $dayRows
     * @param  array<string, bool>  $workedSet
     */
    private function formatCell(Collection $dayRows, string $date, array $workedSet, string $today): array
    {
        $primary = $dayRows->first();

        $shifts = $dayRows
            ->filter(fn (RosterDay $row) => $row->shift_id !== null)
            ->map(fn (RosterDay $row) => [
                'id' => $row->shift_id,
                'code' => $row->shift?->code,
                'color' => $row->shift?->color,
            ])
            ->values()
            ->all();

        $worked = null;
        if ($primary->shift_id !== null && $date <= $today) {
            $worked = isset($workedSet["{$primary->user_id}|{$date}"]);
        }

        return [
            'code' => $primary->shift?->code,
            'color' => $primary->shift?->color,
            'off' => $primary->shift_id === null,
            'shifts' => $shifts,
            'worked' => $worked,
        ];
    }

    /**
     * One query for the whole visible range: a set of "userId|Y-m-d" keys
     * for every (user, date) with a real punch-in whose policy status isn't
     * rejected. Avoids N+1 lookups per cell.
     *
     * @param  array<int, int>  $userIds
     * @return array<string, bool>
     */
    private function buildWorkedSet(array $userIds, string $from, string $to): array
    {
        if ($userIds === []) {
            return [];
        }

        return Attendance::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('punchin')
            ->where('policy_status', '!=', 'rejected')
            ->get(['user_id', 'date'])
            ->mapWithKeys(fn (Attendance $a) => ["{$a->user_id}|{$a->date->format('Y-m-d')}" => true])
            ->all();
    }

    /**
     * @return array{roster: array, holidays: array<string, string>}
     */
    private function withOverlay(Collection $rosterCollection, array $userIds, string $from, string $to): array
    {
        $roster = $rosterCollection->map(fn ($user) => [
            'name' => $user['name'],
            'profile_image_url' => $user['profile_image_url'],
            'days' => $user['days'] instanceof Collection ? $user['days']->toArray() : $user['days'],
        ])->toArray();

        $overlay = $this->overlay->forRange($userIds, $from, $to);

        foreach ($overlay['leave'] as $userId => $days) {
            if (! isset($roster[$userId])) {
                continue;
            }

            foreach ($days as $date => $info) {
                if (! isset($roster[$userId]['days'][$date])) {
                    $roster[$userId]['days'][$date] = [
                        'code' => null, 'color' => null, 'off' => true,
                        'shifts' => [], 'worked' => null,
                    ];
                }

                $roster[$userId]['days'][$date]['leave'] = $info;
            }
        }

        return ['roster' => $roster, 'holidays' => $overlay['holidays']];
    }
}
