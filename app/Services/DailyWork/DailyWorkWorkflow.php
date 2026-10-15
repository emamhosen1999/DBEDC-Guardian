<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The RFI / inspection lifecycle as an explicit allowed-transition map, enforced on the server for every write path
 * (the model refuses anything else) and used by the UI to offer only what the viewer may do.
 *
 * The statuses are the ones the register actually holds (new, in-progress, pending, emergency, resubmission, completed,
 * rejected; the legacy spelling "complete" is the same state as "completed"). Nothing is invented. In the field:
 *
 *   new -> in-progress -> completed (the inspection result, pass or fail, is recorded with it)
 *   any open state may be flagged pending or emergency, sent back to new, rejected, or resubmitted
 *   rejected  -> resubmission                          (a rejected RFI can only come back as a resubmission)
 *   completed -> resubmission (when the result is fail) (a passed inspection is closed)
 *
 * Re-opening a closed RFI (rejected or completed back to new / in-progress, or a passed inspection to anything) is a
 * separate permission, daily-works.status.reopen. Moving to "resubmission" increments the resubmission count: the work
 * keeps one identity and its resubmissions are its numbered revisions.
 */
final class DailyWorkWorkflow
{
    public const OPEN = [
        DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_PENDING, DailyWork::STATUS_EMERGENCY, DailyWork::STATUS_RESUBMISSION,
    ];

    /** Moves anyone allowed to change a status may make from an open state. */
    private const FROM_OPEN = [
        DailyWork::STATUS_NEW => [DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_PENDING, DailyWork::STATUS_EMERGENCY, DailyWork::STATUS_COMPLETED, DailyWork::STATUS_REJECTED, DailyWork::STATUS_RESUBMISSION],
        DailyWork::STATUS_IN_PROGRESS => [DailyWork::STATUS_NEW, DailyWork::STATUS_PENDING, DailyWork::STATUS_EMERGENCY, DailyWork::STATUS_COMPLETED, DailyWork::STATUS_REJECTED, DailyWork::STATUS_RESUBMISSION],
        DailyWork::STATUS_PENDING => [DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_EMERGENCY, DailyWork::STATUS_COMPLETED, DailyWork::STATUS_REJECTED, DailyWork::STATUS_RESUBMISSION],
        DailyWork::STATUS_EMERGENCY => [DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_PENDING, DailyWork::STATUS_COMPLETED, DailyWork::STATUS_REJECTED, DailyWork::STATUS_RESUBMISSION],
        DailyWork::STATUS_RESUBMISSION => [DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_PENDING, DailyWork::STATUS_EMERGENCY, DailyWork::STATUS_COMPLETED, DailyWork::STATUS_REJECTED],
    ];

    /** Set while a trusted write path (the import's own resubmission rule, a batch reversal) saves; bypasses the map. */
    private static bool $unrestricted = false;

    /** @template T @param callable(): T $callback @return T */
    public static function unrestricted(callable $callback): mixed
    {
        $was = self::$unrestricted;
        self::$unrestricted = true;
        try {
            return $callback();
        } finally {
            self::$unrestricted = $was;
        }
    }

    /** A completed work keeps its in-charge and assignee for good (history); only a trusted path (a batch reversal) may. */
    public static function assertMayChangeHands(): void
    {
        if (self::$unrestricted) {
            return;
        }
        throw ValidationException::withMessages(['incharge' => 'A completed RFI cannot change hands: its in-charge and assignee are part of its record.']);
    }

    /** "complete" is the legacy spelling of "completed". */
    public static function normalise(?string $status): ?string
    {
        return $status === 'complete' ? DailyWork::STATUS_COMPLETED : $status;
    }

    public static function isClosed(?string $status, ?string $result = null): bool
    {
        $status = self::normalise($status);

        return $status === DailyWork::STATUS_COMPLETED || $status === DailyWork::STATUS_REJECTED;
    }

    /**
     * The statuses a work may move to. $canReopen adds the moves out of a closed state.
     *
     * @return array<int, string>
     */
    public static function targets(?string $from, ?string $result = null, bool $canReopen = false): array
    {
        $from = self::normalise($from);
        if ($from === null || ! DailyWork::isValidStatus($from)) {
            return $canReopen ? DailyWork::$statuses : [];
        }
        if (isset(self::FROM_OPEN[$from])) {
            return self::FROM_OPEN[$from];
        }
        $out = [];
        if ($from === DailyWork::STATUS_REJECTED) {
            $out = [DailyWork::STATUS_RESUBMISSION];
            if ($canReopen) {
                $out = array_merge($out, [DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS]);
            }
        } elseif ($from === DailyWork::STATUS_COMPLETED) {
            if ($result === DailyWork::INSPECTION_FAIL) {
                $out[] = DailyWork::STATUS_RESUBMISSION;
            }
            if ($canReopen) {
                $out = array_merge($out, [DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_RESUBMISSION, DailyWork::STATUS_REJECTED]);
            }
        }

        return array_values(array_unique($out));
    }

    /** The statuses the viewer may move this work to (what the UI offers). @return array<int, string> */
    public static function availableFor(?User $viewer, DailyWork $work): array
    {
        if ($viewer === null || ! $viewer->can('daily-works.status.update')) {
            return [];
        }

        return self::targets($work->status, $work->inspection_result, $viewer->can('daily-works.status.reopen'));
    }

    /** Refuse a status change the map does not allow. A save that leaves the status as it is passes. */
    public static function assertAllowed(?string $from, ?string $to, ?string $fromResult, ?User $actor, bool $actorKnown = true): void
    {
        $from = self::normalise($from);
        $to = self::normalise($to);
        if (self::$unrestricted || $from === null || $to === null || $from === $to) {
            return;
        }
        $reopen = ! $actorKnown || $actor === null || $actor->can('daily-works.status.reopen');
        if (in_array($to, self::targets($from, $fromResult, $reopen), true)) {
            return;
        }
        $reason = self::isClosed($from)
            ? ($actor !== null && ! $actor->can('daily-works.status.reopen')
                ? "A {$from} RFI cannot be moved to {$to} without the \"Re-open RFIs\" permission."
                : "A {$from} RFI cannot be moved to {$to}.")
            : "An RFI cannot move from {$from} to {$to}.";
        throw ValidationException::withMessages(['status' => $reason]);
    }
}
