<?php

namespace App\Http\Controllers;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveBalanceController extends Controller
{
    /**
     * Per-type leave balances for a user/year, aggregated from the immutable ledger.
     */
    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $requestedUserId = (int) ($request->input('user_id') ?: Auth::id());

        // Only self, or an approver/manager, may view another user's balances.
        if ($requestedUserId !== Auth::id()
            && ! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
            $requestedUserId = (int) Auth::id();
        }

        $rows = LeaveLedger::query()
            ->where('user_id', $requestedUserId)
            ->where('period_year', $year)
            ->get();

        $byType = $rows->groupBy('leave_type');
        $types = LeaveSetting::whereIn('id', $byType->keys())->get()->keyBy('id');

        $balances = $byType->map(function ($txns, $typeId) use ($types) {
            $sum = fn (array $kinds) => (float) $txns->whereIn('txn_type', $kinds)->sum('amount');

            $entitled = $sum(['opening']);
            $accrued = $sum(['accrual']);
            $carried = $sum(['carry_forward']);
            $taken = -$sum(['consumption', 'consumption_reversal']); // positive = days used
            $remaining = (float) (optional($txns->sortByDesc('id')->first())->balance_after ?? 0);

            return [
                'leave_type_id' => (int) $typeId,
                'type' => $types[$typeId]->type ?? 'Unknown',
                'entitled' => round($entitled, 1),
                'accrued' => round($accrued, 1),
                'carried' => round($carried, 1),
                'taken' => round($taken, 1),
                'remaining' => round($remaining, 1),
            ];
        })->values();

        return response()->json([
            'year' => $year,
            'user_id' => $requestedUserId,
            'balances' => $balances,
        ]);
    }
}
