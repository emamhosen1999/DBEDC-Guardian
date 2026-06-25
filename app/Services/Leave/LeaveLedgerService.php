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

    /**
     * True once a balance is established (any ledger row) for (user, type, year).
     * Enforcement is dormant until the ledger is seeded, so leave isn't blocked
     * on un-onboarded types — distinguishes "0 because used up" from "untracked".
     */
    public function isTracked(int $userId, int $leaveTypeId, int $year): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $leaveTypeId)
            ->where('period_year', $year)->exists();
    }

    /**
     * Post a `consumption` for an approved leave. Net-idempotent: if the leave is
     * already net-consuming, no-op; if it was reversed (net 0), a fresh consumption
     * posts (covers edit re-posting).
     */
    public function consume(Leave $leave): void
    {
        $net = (float) LeaveLedger::where('source_type', 'leave')->where('source_id', $leave->id)
            ->whereIn('txn_type', ['consumption', 'consumption_reversal'])->sum('amount');
        if ($net < 0) {
            return;
        }

        $this->post(
            (int) $leave->user_id, (int) $leave->leave_type, (int) Carbon::parse($leave->from_date)->year,
            'consumption', -(float) $leave->no_of_days, 'leave', $leave->id, null, 'Leave taken'
        );
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
