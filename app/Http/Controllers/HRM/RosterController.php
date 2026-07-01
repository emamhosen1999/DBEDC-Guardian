<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Models\User;
use App\Notifications\Attendance\RosterChangedNotification;
use App\Services\Attendance\RosterOverlayService;
use App\Services\Attendance\RosterService;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RosterController extends Controller
{
    public function __construct(
        private readonly RosterService $roster,
        private readonly RealtimeSignal $signals,
        private readonly RosterOverlayService $overlay,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'nullable|integer',
        ]);

        $rows = RosterDay::with(['shift:id,code,color,name', 'user:id,name'])
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

        $rows = RosterDay::with(['shift:id,code,color,name', 'user:id,name'])
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
                'days' => $userRows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
                    ->map(fn ($row) => [
                        'code' => $row->shift?->code,
                        'color' => $row->shift?->color,
                        'off' => $row->shift_id === null,
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
            'days' => $user['days'] instanceof \Illuminate\Support\Collection
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

        $count = $this->roster->generateRoster($data['user_ids'], $data['from'], $data['to']);

        $cursor = \Carbon\Carbon::parse($data['from'])->startOfMonth();
        $end = \Carbon\Carbon::parse($data['to'])->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $this->signals->touch('roster', $cursor->format('Y-m'), $request->user()?->id);
            $cursor->addMonth();
        }

        return response()->json(['message' => 'Roster generated.', 'count' => $count]);
    }

    public function updateCell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'note' => 'nullable|string|max:255',
            'expected_updated_at' => 'nullable|date',
        ]);

        $existing = RosterDay::where('user_id', $data['user_id'])
            ->whereDate('date', $data['date'])
            ->first();

        if (
            $existing
            && ! empty($data['expected_updated_at'])
            && $existing->updated_at->toIso8601String() !== \Carbon\Carbon::parse($data['expected_updated_at'])->toIso8601String()
        ) {
            return response()->json([
                'message' => 'This cell was changed by someone else. Showing the latest version.',
                'cell' => $existing->load('shift'),
            ], 409);
        }

        $cell = RosterDay::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            ['shift_id' => $data['shift_id'] ?? null, 'source' => 'manual', 'locked' => true, 'note' => $data['note'] ?? null],
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

        return response()->json(['message' => 'Roster updated.', 'cell' => $cell->load('shift')]);
    }
}
