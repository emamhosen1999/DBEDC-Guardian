<?php

namespace App\Services\Leave;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Year-boundary carry-forward (capped) + post-window expiry of unused carried days.
 * Both operations are idempotent per user/type/year.
 */
class CarryForwardService
{
    public function __construct(private LeaveLedgerService $ledger) {}

    public function rollOver(int $fromYear, int $toYear, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::whereNotNull('carry_forward_cap')->get();
        $posted = 0;

        foreach ($this->users($userId) as $user) {
            foreach ($types as $type) {
                if ($this->hasCarry($user->id, $type->id, $toYear)) {
                    continue;
                }
                $carried = min($this->ledger->balance($user->id, $type->id, $fromYear), (float) $type->carry_forward_cap);
                if ($carried <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $toYear, 'carry_forward', $carried, 'command', null, null, "Carry-forward from {$fromYear}");
                }
                $posted++;
            }
        }

        return $posted;
    }

    public function expireCarried(CarbonInterface $asOf, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::whereNotNull('carry_forward_cap')->whereNotNull('carry_expiry_months')->get();
        $posted = 0;
        $year = $asOf->year;

        foreach ($this->users($userId) as $user) {
            foreach ($types as $type) {
                $carryRow = LeaveLedger::where('user_id', $user->id)->where('leave_type', $type->id)
                    ->where('period_year', $year)->where('txn_type', 'carry_forward')->first();
                if (! $carryRow) {
                    continue;
                }
                $expiry = Carbon::create($year, 1, 1)->addMonths((int) $type->carry_expiry_months);
                if ($asOf->lessThan($expiry)) {
                    continue;
                }
                if ($this->hasExpiry($user->id, $type->id, $year)) {
                    continue;
                }
                // Unused carried = min(carried amount, current remaining balance).
                $unused = min((float) $carryRow->amount, max(0.0, $this->ledger->balance($user->id, $type->id, $year)));
                if ($unused <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $year, 'carry_expiry', -$unused, 'command', null, null, 'Carried days expired');
                }
                $posted++;
            }
        }

        return $posted;
    }

    private function users(?int $userId)
    {
        return User::query()->when($userId, fn ($q) => $q->where('id', $userId))->get();
    }

    private function hasCarry(int $userId, int $typeId, int $toYear): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $toYear)->where('txn_type', 'carry_forward')->exists();
    }

    private function hasExpiry(int $userId, int $typeId, int $year): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $year)->where('txn_type', 'carry_expiry')->exists();
    }
}
