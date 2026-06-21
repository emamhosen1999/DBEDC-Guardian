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
        // Two-stage flow: a named counterparty must consent before manager/admin review.
        // An open/give-away swap (no counterparty) skips the peer step → straight to admin.
        $data['counterparty_status'] = ! empty($data['counterparty_id']) ? 'pending' : null;

        $swap = DB::transaction(fn () => ShiftSwapRequest::create($data));

        $message = $data['counterparty_status'] === 'pending'
            ? 'Swap request sent to the counterparty for confirmation.'
            : 'Swap request submitted for approval.';

        return response()->json(['message' => $message, 'swap' => $swap], 201);
    }

    /**
     * Counterparty (the affected coworker) accepts or declines — the peer-consent stage.
     * Accept → moves to manager/admin review; Decline → terminal (rejected).
     */
    public function respond(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:accept,decline']);
        $swap = ShiftSwapRequest::findOrFail($id);

        abort_unless($swap->counterparty_id === $request->user()->id, 403, 'Only the counterparty can respond to this swap.');
        abort_if($swap->counterparty_status !== 'pending', 409, 'This swap is not awaiting your response.');

        if ($data['decision'] === 'accept') {
            $swap->update(['counterparty_status' => 'accepted']);

            return response()->json(['message' => 'Swap accepted; sent to your manager for final approval.', 'swap' => $swap->fresh()]);
        }

        $swap->update(['counterparty_status' => 'declined', 'status' => 'rejected']);

        return response()->json(['message' => 'Swap declined.', 'swap' => $swap->fresh()]);
    }

    /**
     * Swaps awaiting THIS user's counterparty response (employee-side inbox).
     */
    public function awaitingMe(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['requester', 'counterparty'])
            ->where('counterparty_id', $request->user()->id)
            ->where('counterparty_status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['swaps' => $swaps]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);
        abort_if($swap->status !== 'pending', 409, 'This swap request has already been decided.');
        abort_if($swap->counterparty_status === 'pending', 409, 'Awaiting the counterparty\'s confirmation before approval.');

        DB::transaction(function () use ($swap, $request) {
            $swap->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
            ]);

            $this->roster->applySwap($swap->fresh());
        });

        return response()->json(['message' => 'Swap approved and applied.', 'swap' => $swap->fresh()]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);
        abort_if($swap->status !== 'pending', 409, 'This swap request has already been decided.');

        $swap->update([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Swap rejected.', 'swap' => $swap->fresh()]);
    }
}
