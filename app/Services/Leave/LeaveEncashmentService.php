<?php

namespace App\Services\Leave;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use Carbon\Carbon;

/**
 * Records leave encashment as a ledger transaction (no payout — payroll is external).
 * Gated by LeaveSetting.is_encashable and sufficient balance.
 */
class LeaveEncashmentService
{
    public function __construct(private LeaveLedgerService $ledger) {}

    public function encash(int $userId, int $leaveTypeId, float $days, int $actorId, ?string $reason = null): LeaveLedger
    {
        $type = LeaveSetting::find($leaveTypeId);
        if (! $type || ! $type->is_encashable) {
            throw new \RuntimeException('This leave type is not encashable.', 422);
        }
        if ($days <= 0) {
            throw new \RuntimeException('Encashment days must be positive.', 422);
        }
        $year = Carbon::now()->year;
        if ($this->ledger->balance($userId, $leaveTypeId, $year) < $days) {
            throw new \RuntimeException('Insufficient balance to encash.', 422);
        }

        // Yearly encashment cap (LeaveSetting.max_encash_days).
        if ($type->max_encash_days !== null) {
            $alreadyEncashed = abs((float) LeaveLedger::where('user_id', $userId)
                ->where('leave_type', $leaveTypeId)
                ->where('period_year', $year)
                ->where('txn_type', 'encashment')
                ->sum('amount'));
            if ($alreadyEncashed + $days > (float) $type->max_encash_days) {
                $remaining = max(0, (float) $type->max_encash_days - $alreadyEncashed);
                throw new \RuntimeException(
                    "Encashment cap exceeded: {$remaining} of {$type->max_encash_days} days remain this year.", 422
                );
            }
        }

        return $this->ledger->post($userId, $leaveTypeId, $year, 'encashment', -$days, 'manual', null, $actorId, $reason ?? 'Leave encashment');
    }
}
