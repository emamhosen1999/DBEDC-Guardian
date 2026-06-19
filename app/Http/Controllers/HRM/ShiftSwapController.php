<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\ShiftSwapRequest;
use App\Services\Attendance\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftSwapController extends Controller
{
    public function __construct(private readonly RosterService $roster) {}

    public function index(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['requester', 'counterparty'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['swaps' => $swaps]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requester_date' => 'required|date',
            'counterparty_id' => 'nullable|integer|exists:users,id',
            'counterparty_date' => 'nullable|date',
            'requested_shift_id' => 'nullable|integer|exists:shifts,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $data['requester_id'] = $request->user()->id;
        $data['status'] = 'pending';

        $swap = DB::transaction(fn () => ShiftSwapRequest::create($data));

        return response()->json(['message' => 'Swap request submitted.', 'swap' => $swap], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);

        DB::transaction(function () use ($swap, $request) {
            $swap->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
            ]);

            $this->roster->applySwap($swap->fresh());
        });

        return response()->json(['message' => 'Swap approved and applied.', 'swap' => $swap->fresh()]);
    }
}
