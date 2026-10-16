<?php

namespace App\Services\Attendance;

use App\Models\HRM\CompOffLedger;
use App\Models\HRM\ReplacementLeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Replacement-leave (compensatory off) ledger, in days.
 *  - credit(): an approved rest-day claim earns 1 or 0.5 day, expiring 60 days after the work date.
 *  - allocate(): the days a replacement request uses, drawn first-expiring-first. A whole day only from whole-day credits;
 *    a half day from the earliest credit that still has at least half a day (so a half-day credit is only usable as a half day).
 *  - consume(): writes the use at approval. expire(): expired days are written to the ledger, never silently dropped.
 */
class CompOffService
{
    /** @param Carbon|null $workDate the day the rest-day work happened (the expiry counts from it) */
    public function credit(string $userId, float $days, string $sourceType, ?int $sourceId = null, ?string $note = null, ?CarbonInterface $workDate = null): CompOffLedger
    {
        $workDate = ($workDate ?? Carbon::today())->copy()->startOfDay();

        return CompOffLedger::create([
            'user_id' => $userId, 'minutes' => 0, 'days' => abs($days), 'work_date' => $workDate->toDateString(),
            'source_type' => $sourceType, 'source_id' => $sourceId, 'note' => $note,
            'expires_at' => $workDate->copy()->addDays((int) config('overtime_claims.replacement_expiry_days'))->toDateString(),
        ]);
    }

    /** Whole replacement days left (credits not yet used or expired), regardless of pending requests. */
    public function balance(string $userId): float
    {
        return round((float) CompOffLedger::where('user_id', $userId)->sum('days'), 1);
    }

    /** @return Collection<int, array{credit: CompOffLedger, remaining: float}> credits with days left, earliest expiry first */
    public function openCredits(string $userId, ?CarbonInterface $asOf = null, ?int $ignoreRequestId = null): Collection
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $credits = CompOffLedger::where('user_id', $userId)->where('days', '>', 0)->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $asOf->toDateString())->orderBy('expires_at')->orderBy('id')->get();
        if ($credits->isEmpty()) {
            return collect();
        }

        $used = CompOffLedger::whereIn('credit_id', $credits->pluck('id'))->selectRaw('credit_id, SUM(days) as d')->groupBy('credit_id')->pluck('d', 'credit_id');

        // Days already earmarked by this employee's other pending replacement requests.
        $reserved = [];
        ReplacementLeaveRequest::where('user_id', $userId)->where('status', 'pending')
            ->when($ignoreRequestId, fn ($q) => $q->where('id', '!=', $ignoreRequestId))->get()
            ->each(function ($r) use (&$reserved) {
                foreach ($r->allocations ?? [] as $a) {
                    $reserved[$a['credit_id']] = ($reserved[$a['credit_id']] ?? 0) + (float) $a['days'];
                }
            });

        return $credits->map(fn ($c) => ['credit' => $c, 'remaining' => round($c->days + (float) ($used[$c->id] ?? 0) - ($reserved[$c->id] ?? 0), 1)])
            ->filter(fn ($row) => $row['remaining'] > 0)->values();
    }

    /** Days available for a new request (unexpired, not used, not earmarked). */
    public function available(string $userId, ?CarbonInterface $asOf = null): float
    {
        return round((float) $this->openCredits($userId, $asOf)->sum('remaining'), 1);
    }

    /**
     * First-expiring-first allocation for a request of $days (1 or 0.5); null when the credit cannot cover it.
     *
     * @return list<array{credit_id: int, days: float, work_date: string}>|null
     */
    public function allocate(string $userId, float $days, ?CarbonInterface $asOf = null, ?int $ignoreRequestId = null): ?array
    {
        foreach ($this->openCredits($userId, $asOf, $ignoreRequestId) as $row) {
            // A whole day needs a whole-day credit left; half a day can come from any credit with half a day left.
            if ($row['remaining'] >= $days) {
                return [['credit_id' => $row['credit']->id, 'days' => $days, 'work_date' => $row['credit']->work_date->toDateString()]];
            }
        }

        return null;
    }

    /** The approved request uses its allocated credit. */
    public function consume(ReplacementLeaveRequest $request): void
    {
        foreach ($request->allocations as $a) {
            CompOffLedger::create([
                'user_id' => $request->user_id, 'minutes' => 0, 'days' => -abs((float) $a['days']), 'credit_id' => $a['credit_id'],
                'work_date' => $a['work_date'], 'source_type' => 'used', 'source_id' => $request->id,
                'note' => 'Replacement leave '.$request->replacement_date->toDateString(),
            ]);
        }
    }

    /** Expire every credit past its expiry; the unused days are written as an 'expired' row. @return int rows written */
    public function expire(?CarbonInterface $today = null): int
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $written = 0;
        CompOffLedger::where('days', '>', 0)->whereNotNull('expires_at')->whereDate('expires_at', '<', $today->toDateString())->orderBy('id')
            ->each(function (CompOffLedger $credit) use (&$written) {
                $left = round($credit->days + (float) CompOffLedger::where('credit_id', $credit->id)->sum('days'), 1);
                if ($left > 0) {
                    CompOffLedger::create([
                        'user_id' => $credit->user_id, 'minutes' => 0, 'days' => -$left, 'credit_id' => $credit->id, 'work_date' => $credit->work_date,
                        'source_type' => CompOffLedger::EXPIRED, 'note' => 'Expired '.$credit->expires_at->toDateString(),
                    ]);
                    $written++;
                }
            });

        return $written;
    }

    /** Open replacement days that expire within the first reminder window (14 days) as of the given date. */
    public function expiringDays(string $userId, ?CarbonInterface $asOf = null): float
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $window = (int) (config('overtime_claims.replacement_reminder_days')[0] ?? 14);

        return round((float) $this->openCredits($userId, $asOf)
            ->filter(fn ($row) => $asOf->diffInDays($row['credit']->expires_at, false) <= $window)->sum('remaining'), 1);
    }

    /**
     * Reminders before expiry (14 and 3 days by default), each sent once per credit through the notification channels (web + push).
     * A day missed by the scheduler still triggers the reminder it owes.
     *
     * @return int reminders sent
     */
    public function remind(?CarbonInterface $today = null): int
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        [$first, $second] = array_pad(array_values((array) config('overtime_claims.replacement_reminder_days')), 2, 3);
        $sent = 0;

        CompOffLedger::where('days', '>', 0)->whereNotNull('expires_at')->whereDate('expires_at', '>=', $today->toDateString())
            ->whereDate('expires_at', '<=', $today->copy()->addDays(max($first, $second))->toDateString())->orderBy('id')
            ->each(function (CompOffLedger $credit) use ($today, $first, $second, &$sent) {
                $left = round($credit->days + (float) CompOffLedger::where('credit_id', $credit->id)->sum('days'), 1);
                $daysLeft = (int) $today->diffInDays($credit->expires_at, false);
                $field = null;
                if ($left > 0 && $daysLeft <= $second && $credit->reminded_3_at === null) {
                    $field = 'reminded_3_at';
                } elseif ($left > 0 && $daysLeft <= $first && $daysLeft > $second && $credit->reminded_14_at === null) {
                    $field = 'reminded_14_at';
                }
                $user = $field ? \App\Models\User::find($credit->user_id) : null;
                if ($field && $user) {
                    try {
                        $user->notify(new \App\Notifications\Attendance\ReplacementLeaveExpiringNotification($left, $credit->work_date?->toDateString() ?? '', $credit->expires_at->toDateString(), $daysLeft));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('ReplacementLeaveExpiringNotification failed', ['credit' => $credit->id, 'error' => $e->getMessage()]);
                    }
                    $credit->forceFill([$field => now()] + ($field === 'reminded_3_at' && $credit->reminded_14_at === null ? ['reminded_14_at' => now()] : []))->save();
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * What the employee sees: the balance in days, each open credit with its expiry, the recent ledger, and the totals the register's summary uses.
     *
     * @return array<string, mixed>
     */
    public function summary(string $userId): array
    {
        $today = Carbon::today();
        $open = $this->openCredits($userId, $today, null);
        $reminder = (int) (config('overtime_claims.replacement_reminder_days')[0] ?? 14);

        return [
            'balance_days' => $this->balance($userId),
            'available_days' => $this->available($userId, $today),
            'credits' => $open->map(fn ($row) => [
                'credit_id' => $row['credit']->id, 'days' => $row['remaining'], 'work_date' => $row['credit']->work_date?->toDateString(),
                'expires_at' => $row['credit']->expires_at->toDateString(), 'days_left' => (int) $today->diffInDays($row['credit']->expires_at, false),
            ])->values(),
            'expiring_days' => round((float) $open->filter(fn ($r) => $today->diffInDays($r['credit']->expires_at, false) <= $reminder)->sum('remaining'), 1),
            'earned_days' => round((float) CompOffLedger::where('user_id', $userId)->where('days', '>', 0)->sum('days'), 1),
            'used_days' => round(abs((float) CompOffLedger::where('user_id', $userId)->where('source_type', 'used')->sum('days')), 1),
            'expired_days' => round(abs((float) CompOffLedger::where('user_id', $userId)->where('source_type', CompOffLedger::EXPIRED)->sum('days')), 1),
            'balance_minutes' => (int) CompOffLedger::where('user_id', $userId)->sum('minutes'), // legacy history
            'entries' => CompOffLedger::where('user_id', $userId)->orderByDesc('id')->limit(50)->get(),
        ];
    }
}
