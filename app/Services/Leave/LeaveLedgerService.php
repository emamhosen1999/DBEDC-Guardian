<?php

namespace App\Services\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveLedger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Append-only leave balance ledger. Never mutate a row — reversals are new rows.
 * Balance for (user, leave_type, period_year) is the running sum of signed amounts.
 */
class LeaveLedgerService
{
    public function post(
        int $userId,
        int $leaveTypeId,
        int $year,
        string $txnType,
        float $amount,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $actorId = null,
        ?string $reason = null
    ): LeaveLedger {
        return DB::transaction(function () use ($userId, $leaveTypeId, $year, $txnType, $amount, $sourceType, $sourceId, $actorId, $reason) {
            $prior = LeaveLedger::query()
                ->where('user_id', $userId)->where('leave_type', $leaveTypeId)->where('period_year', $year)
                ->lockForUpdate()->orderByDesc('id')->value('balance_after');

            $balanceAfter = round((float) ($prior ?? 0) + $amount, 2);

            return LeaveLedger::create([
                'user_id' => $userId,
                'leave_type' => $leaveTypeId,
                'period_year' => $year,
                'txn_type' => $txnType,
                'amount' => round($amount, 2),
                'balance_after' => $balanceAfter,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'actor_id' => $actorId ?? auth()->id(),
                'reason' => $reason,
            ]);
        });
    }

    public function balance(int $userId, int $leaveTypeId, int $year): float
    {
        $last = LeaveLedger::query()
            ->where('user_id', $userId)->where('leave_type', $leaveTypeId)->where('period_year', $year)
            ->orderByDesc('id')->value('balance_after');

        return (float) ($last ?? 0);
    }

    public function available(int $userId, int $leaveTypeId, ?CarbonInterface $asOf = null): float
    {
        $year = ($asOf ?? Carbon::now())->year;

        return $this->balance($userId, $leaveTypeId, $year);
    }

    public function reverseConsumption(int $leaveId, ?string $reason = null): void
    {
        $leave = Leave::find($leaveId);
        if (! $leave) {
            return;
        }

        $alreadyReversed = LeaveLedger::where('source_type', 'leave')->where('source_id', $leaveId)
            ->where('txn_type', 'consumption_reversal')->exists();
        if ($alreadyReversed) {
            return; // idempotent
        }

        $consumed = (float) LeaveLedger::where('source_type', 'leave')->where('source_id', $leaveId)
            ->where('txn_type', 'consumption')->sum('amount'); // negative
        if ($consumed === 0.0) {
            return;
        }

        $year = (int) Carbon::parse($leave->from_date)->year;
        $this->post($leave->user_id, (int) $leave->leave_type, $year, 'consumption_reversal', -$consumed,
            'leave', $leaveId, null, $reason ?? 'Leave reversed');
    }
}
