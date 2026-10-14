<?php

namespace App\Services\Jurisdiction;

use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The rule for every dated change to jurisdictions, teams and handovers:
 *  - effective today or later (a planned change is fine, it takes effect by date): nothing extra;
 *  - effective in the PAST is a retroactive correction: it needs jurisdiction.history.correct AND a reason, and is flagged.
 */
final class HistoryGuard
{
    public const MIN_REASON = 5;

    /** @return array{date: string, correction: bool, reason: ?string} */
    public static function check(?User $actor, ?string $effective, ?string $reason): array
    {
        $today = ChainageResolver::today();
        $date = $effective === null || $effective === '' ? $today : $effective;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw ValidationException::withMessages(['effective_date' => 'Enter the effective date as a calendar date.']);
        }
        $reason = $reason !== null ? trim($reason) : null;
        $correction = $date < $today;
        if ($correction) {
            if ($actor === null || ! $actor->can('jurisdiction.history.correct')) {
                throw new AuthorizationException('A back-dated change needs the "Correct history" permission.');
            }
            if ($reason === null || mb_strlen($reason) < self::MIN_REASON) {
                throw ValidationException::withMessages(['reason' => 'A back-dated change needs a reason (at least '.self::MIN_REASON.' characters).']);
            }
        }

        return ['date' => $date, 'correction' => $correction, 'reason' => $reason === '' ? null : $reason];
    }

    public static function dayBefore(string $date): string
    {
        return date('Y-m-d', strtotime($date.' -1 day'));
    }
}
