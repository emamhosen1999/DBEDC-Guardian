<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Services\Attendance\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $rows = RosterDay::with('shift:id,code,color,name')
            ->whereBetween('date', [$data['from'], $data['to']])
            ->get()
            ->groupBy('user_id');

        return response()->json(['roster' => $rows]);
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

        return response()->json(['message' => 'Roster updated.', 'cell' => $cell->load('shift')]);
    }
}
