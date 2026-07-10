<?php

namespace App\Services\Leave;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Carbon\Carbon;

/**
 * Posts entitlement/accrual transactions into the ledger. Idempotent:
 *  - annual_upfront types get ONE `opening` per user/type/year (pro-rated if joined that year);
 *  - monthly types get ONE `accrual` per user/type/month (probation-gated, pro-rated on join month).
 */
class LeaveAccrualService
{
    public function __construct(private LeaveLedgerService $ledger) {}

    public function grantAnnual(int $year, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::where('accrual_method', 'annual_upfront')->get();
        $posted = 0;

        foreach ($this->users($userId) as $user) {
            $join = $user->date_of_joining ? Carbon::parse($user->date_of_joining) : null;
            if ($join && $join->year > $year) {
                continue; // not yet employed
            }
            foreach ($types as $type) {
                if ($this->hasOpening($user->id, $type->id, $year)) {
                    continue;
                }
                $entitlement = (float) ($type->accrual_rate ?? $type->days);
                if ($type->prorate_on_join && $join && $join->year === $year) {
                    $remainingMonths = 12 - $join->month + 1;
                    $entitlement = round($entitlement * $remainingMonths / 12, 2);
                }
                if ($entitlement <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $year, 'opening', $entitlement, 'command', null, null,
                        'Annual entitlement', "op:{$user->id}:{$type->id}:{$year}");
                }
                $posted++;
            }
        }

        return $posted;
    }

    public function accrueMonthly(int $year, int $month, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::where('accrual_method', 'monthly')->get();
        $accrualDate = Carbon::create($year, $month, 1)->startOfMonth();
        $posted = 0;

        foreach ($this->users($userId) as $user) {
            $join = $user->date_of_joining ? Carbon::parse($user->date_of_joining) : null;
            if (! $join || $join->isAfter($accrualDate->copy()->endOfMonth())) {
                continue;
            }
            // Whole months of service at the accrual month.
            $serviceMonths = $join->copy()->startOfMonth()->diffInMonths($accrualDate);
            foreach ($types as $type) {
                if ($serviceMonths < (int) $type->probation_months) {
                    continue;
                }
                if ($this->hasAccrualForMonth($user->id, $type->id, $year, $month)) {
                    continue;
                }
                $monthly = round((float) ($type->accrual_rate ?? $type->days) / 12, 2);
                if ($type->prorate_on_join && $join->year === $year && $join->month === $month) {
                    $dim = $accrualDate->daysInMonth;
                    $monthly = round($monthly * ($dim - $join->day + 1) / $dim, 2);
                }
                if ($monthly <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $year, 'accrual', $monthly, 'command', null, null,
                        "Accrual {$year}-{$month}", "ac:{$user->id}:{$type->id}:{$year}-{$month}");
                }
                $posted++;
            }
        }

        return $posted;
    }

    /**
     * Lazily seed one user's ledger for a year from every configured accrual
     * policy (annual grant + monthly back-fill up to the current month).
     * Idempotent — safe to call on every leave request; used to close the
     * "untracked balance = unlimited leave" gap.
     */
    public function seedFor(int $userId, int $year): void
    {
        $this->grantAnnual($year, $userId);

        $now = Carbon::now();
        if ($year > $now->year) {
            return; // no monthly accrual for future years
        }
        $lastMonth = ($year < $now->year) ? 12 : $now->month;
        for ($m = 1; $m <= $lastMonth; $m++) {
            $this->accrueMonthly($year, $m, $userId);
        }
    }

    private function users(?int $userId)
    {
        return User::query()->when($userId, fn ($q) => $q->where('id', $userId))->get();
    }

    private function hasOpening(int $userId, int $typeId, int $year): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $year)->where('txn_type', 'opening')->exists();
    }

    private function hasAccrualForMonth(int $userId, int $typeId, int $year, int $month): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $year)->where('txn_type', 'accrual')
            ->where('reason', "Accrual {$year}-{$month}")->exists();
    }
}
