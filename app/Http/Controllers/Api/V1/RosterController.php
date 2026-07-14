<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;
use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Services\Attendance\RosterOverlayService;
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
            ->get();

        return response()->json([
            'success' => true,
            'data' => $this->withOverlay(
                $this->formatRoster($rows),
                $rows->pluck('user_id')->unique()->values()->all(),
                $data['from'],
                $data['to'],
            ),
        ]);
    }

    public function shifts(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'shifts' => Shift::query()
                    ->orderBy('start_time')
                    ->orderBy('name')
                    ->get(['id', 'code', 'name', 'color', 'type', 'start_time', 'end_time', 'crosses_midnight']),
            ],
        ]);
    }

    private function formatRoster(Collection $rows): Collection
    {
        return $rows->groupBy('user_id')->map(function (Collection $userRows) {
            $first = $userRows->first();

            return [
                'name' => $first->user?->name,
                'profile_image_url' => $first->user?->profile_image_url,
                'days' => $userRows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
                    ->map(fn ($row) => [
                        'code' => $row->shift?->code,
                        'color' => $row->shift?->color,
                        'off' => $row->shift_id === null,
                    ]),
            ];
        });
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
                    ];
                }

                $roster[$userId]['days'][$date]['leave'] = $info;
            }
        }

        return ['roster' => $roster, 'holidays' => $overlay['holidays']];
    }
}
