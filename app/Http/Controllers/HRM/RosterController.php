<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Notifications\Attendance\RosterChangedNotification;
use App\Services\Attendance\RosterOverlayService;
use App\Services\Attendance\RosterService;
use App\Services\Attendance\WorkTimeComplianceService;
use App\Services\Realtime\RealtimeSignal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RosterController extends Controller
{
    public function __construct(
        private readonly RosterService $roster,
        private readonly RealtimeSignal $signals,
        private readonly RosterOverlayService $overlay,
        private readonly WorkTimeComplianceService $compliance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        if (! $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']) && $user->department_id !== null) {
            $data['department_id'] = $user->department_id;
        }

        $rows = RosterDay::with(['shift:id,code,color,name', 'user:id,name', 'user.media'])
            ->whereBetween('date', [$data['from'], $data['to']])
            ->when($data['department_id'] ?? null, fn ($q, $departmentId) => $q->whereHas(
                'user',
                fn ($uq) => $uq->where('department_id', $departmentId)
            ))
            ->get();

        return response()->json($this->withOverlay(
            $this->formatRoster($rows),
            $rows->pluck('user_id')->unique()->values()->all(),
            $data['from'],
            $data['to'],
        ));
    }

    /**
     * Scope the roster to the requesting user only (avoids leaking all employees' rosters).
     */
    public function myRoster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $rows = RosterDay::with(['shift:id,code,color,name', 'user:id,name', 'user.media'])
            ->where('user_id', $request->user()->id)
            ->whereBetween('date', [$data['from'], $data['to']])
            ->get();

        return response()->json($this->withOverlay(
            $this->formatRoster($rows),
            [$request->user()->id],
            $data['from'],
            $data['to'],
        ));
    }

    /**
     * Group roster rows by user and shape them into the standard roster payload.
     */
    private function formatRoster($rows)
    {
        return $rows->groupBy('user_id')->map(function ($userRows) {
            $first = $userRows->first();

            return [
                'name' => $first->user?->name,
                'profile_image_url' => $first->user?->profile_image_url,
                'days' => $userRows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
                    ->map(fn ($row) => [
                        'code' => $row->shift?->code,
                        'color' => $row->shift?->color,
                        'off' => $row->shift_id === null,
                        'work_location_id' => $row->work_location_id,
                        'updated_at' => $row->updated_at?->toIso8601String(),
                    ]),
            ];
        });
    }

    /**
     * Merge the leave/holiday overlay into a formatted roster payload.
     * Leave is attached per user/date cell; holidays are org-wide (top level).
     *
     * @return array{roster: array, holidays: array<string,string>}
     */
    private function withOverlay($rosterCollection, array $userIds, string $from, string $to): array
    {
        // `Collection::toArray()` only converts top-level Arrayable items; the nested
        // `days` Collection inside each user's array survives as an object, so cast
        // each user's entry (and its `days`) explicitly to plain arrays.
        $roster = $rosterCollection->map(fn ($user) => [
            'name' => $user['name'],
            'profile_image_url' => $user['profile_image_url'],
            'days' => $user['days'] instanceof Collection
                ? $user['days']->toArray()
                : $user['days'],
        ])->toArray();
        $overlay = $this->overlay->forRange($userIds, $from, $to);

        foreach ($overlay['leave'] as $userId => $days) {
            if (! isset($roster[$userId])) {
                continue; // only annotate users already present in the grid
            }
            foreach ($days as $date => $info) {
                if (! isset($roster[$userId]['days'][$date])) {
                    $roster[$userId]['days'][$date] = [
                        'code' => null, 'color' => null, 'off' => true, 'updated_at' => null,
                    ];
                }
                $roster[$userId]['days'][$date]['leave'] = $info;
            }
        }

        return ['roster' => $roster, 'holidays' => $overlay['holidays']];
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'integer|exists:users,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $user = $request->user();
        if ($user && ! $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']) && $user->department_id !== null) {
            $invalidCount = User::whereIn('id', $data['user_ids'])
                ->where('department_id', '!=', $user->department_id)
                ->count();
            if ($invalidCount > 0) {
                return response()->json(['error' => 'You can only generate rosters for your own department.'], 403);
            }
        }

        $count = $this->roster->generateRoster($data['user_ids'], $data['from'], $data['to']);

        $cursor = Carbon::parse($data['from'])->startOfMonth();
        $end = Carbon::parse($data['to'])->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $this->signals->touch('roster', $cursor->format('Y-m'), $request->user()?->id);
            $cursor->addMonth();
        }

        // Working-time compliance is informational only for bulk generation
        // (warn-first rollout): it never blocks, it only surfaces violations
        // for HR review. Keyed by user_id so the caller can attribute them.
        $complianceViolations = [];
        foreach ($data['user_ids'] as $userId) {
            $userViolations = $this->compliance->evaluate((int) $userId, $data['from'], $data['to']);
            if ($userViolations) {
                $complianceViolations[$userId] = $userViolations;
            }
        }

        return response()->json([
            'message' => 'Roster generated.',
            'count' => $count,
            'compliance_violations' => $complianceViolations,
        ]);
    }

    public function updateCell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'work_location_id' => 'nullable|integer|exists:work_locations,id',
            'note' => 'nullable|string|max:255',
            'expected_updated_at' => 'nullable|date',
        ]);

        $user = $request->user();
        if ($user && ! $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']) && $user->department_id !== null) {
            $targetUser = User::find($data['user_id']);
            if (! $targetUser || $targetUser->department_id !== $user->department_id) {
                return response()->json(['error' => 'You can only update roster cells for your own department.'], 403);
            }
        }

        $existing = RosterDay::where('user_id', $data['user_id'])
            ->whereDate('date', $data['date'])
            ->first();

        if (
            $existing
            && ! empty($data['expected_updated_at'])
            && $existing->updated_at->toIso8601String() !== Carbon::parse($data['expected_updated_at'])->toIso8601String()
        ) {
            return response()->json([
                'message' => 'This cell was changed by someone else. Showing the latest version.',
                'cell' => $existing->load('shift'),
            ], 409);
        }

        // Working-time compliance: simulate the ±7 day window AROUND the
        // affected date with the new shift substituted in, before writing
        // anything. Enforce mode blocks only a severity=error violation;
        // warnings are always returned but never block.
        $complianceViolations = $this->complianceForManualDay((int) $data['user_id'], $data['date'], $data['shift_id'] ?? null);
        $hasBlockingError = collect($complianceViolations)->contains(fn (array $v) => ($v['severity'] ?? null) === 'error');

        if (config('attendance.compliance.enforce') && $hasBlockingError) {
            return response()->json([
                'message' => 'This change violates working-time compliance rules and was not applied.',
                'compliance_violations' => $complianceViolations,
            ], 422);
        }

        $cell = RosterDay::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            ['shift_id' => $data['shift_id'] ?? null, 'work_location_id' => $data['work_location_id'] ?? null, 'source' => 'manual', 'locked' => true, 'note' => $data['note'] ?? null],
        );

        // Realtime cross-client signal (from realtime-foundation) + per-employee notification.
        $this->signals->touch('roster', substr($data['date'], 0, 7), $request->user()?->id);

        // Notify the affected employee of their updated roster slot.
        $employee = User::find($data['user_id']);
        if ($employee) {
            try {
                $employee->notify(new RosterChangedNotification($data['date']));
            } catch (\Throwable $exception) {
                Log::warning("RosterChangedNotification failed for user {$data['user_id']}", [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Roster updated.',
            'cell' => $cell->load('shift'),
            'compliance_violations' => $complianceViolations,
        ]);
    }

    /**
     * Working-time compliance for a single manual day-override, evaluated
     * over the affected user's surrounding +/-7 day window with the proposed
     * shift substituted in for the target date (existing rows for every
     * other day in the window are used as-is, so the check reflects what the
     * real roster would look like immediately after this change).
     *
     * @return array<int, array{date: string, rule: string, message: string, severity: string, details: array}>
     */
    private function complianceForManualDay(int $userId, string $date, ?int $shiftId): array
    {
        $target = Carbon::parse($date)->startOfDay();
        $from = $target->copy()->subDays(7);
        $to = $target->copy()->addDays(7);

        $existingByDate = RosterDay::with('shift')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (RosterDay $row) => $row->date->toDateString());

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dateStr = $d->toDateString();

            if ($dateStr === $target->toDateString()) {
                $days[] = ['date' => $dateStr, 'shift' => $shiftId ? Shift::find($shiftId) : null];

                continue;
            }

            $days[] = ['date' => $dateStr, 'shift' => $existingByDate->get($dateStr)?->shift];
        }

        return $this->compliance->evaluateSequence($days);
    }
}
