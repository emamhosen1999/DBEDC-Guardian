<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Models\User;
use App\Notifications\Attendance\RosterChangedNotification;
use App\Services\Attendance\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RosterController extends Controller
{
    public function __construct(private readonly RosterService $roster) {}

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

        return response()->json(['roster' => $this->formatRoster($rows)]);
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

        return response()->json(['roster' => $this->formatRoster($rows)]);
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

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'integer|exists:users,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $count = $this->roster->generateRoster($data['user_ids'], $data['from'], $data['to']);

        return response()->json(['message' => 'Roster generated.', 'count' => $count]);
    }

    public function updateCell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'note' => 'nullable|string|max:255',
        ]);

        $cell = RosterDay::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            ['shift_id' => $data['shift_id'] ?? null, 'source' => 'manual', 'locked' => true, 'note' => $data['note'] ?? null],
        );

        // Notify the affected employee of their updated roster slot
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
