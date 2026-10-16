<?php

namespace App\Support\Attendance;

/**
 * The QCD register symbol key: the ONE source of truth for how an attendance day is
 * written, on screens, in Excel and PDF exports and in API payloads.
 *
 * The JS twin is resources/js/utils/attendanceSymbols.js; a test keeps the two in step.
 */
final class AttendanceSymbol
{
    public const PRESENT = '√';

    public const ABSENT = '▼';

    public const DAY_OFF = '/';

    public const NOT_YET = '·';

    public const PERSONAL = '§';

    public const SICK = '×';

    public const MARITAL = '□';

    public const FUNERAL = '■';

    public const MATERNITY = '◎';

    public const ANNUAL = '△';

    public const FESTIVAL = '#';

    /**
     * Shown for approved leave whose type carries no symbol. Deliberately NOT '/'
     * (that is the weekly off / rest day) and not one of the eleven key symbols.
     */
    public const UNKNOWN_LEAVE = 'L';

    /** Status wording used on every screen, export and API (the JS twin is TERM in attendanceSymbols.js). */
    public const TERM_PRESENT = 'Present';

    public const TERM_ABSENT = 'Absent';

    public const TERM_DAY_OFF = 'Weekly Off / Rest Day';

    public const TERM_NOT_YET = 'Not Yet Recorded';

    public const TERM_ON_LEAVE = 'On Leave';

    public const TERM_FESTIVAL = 'Festival Holiday';

    /** @var array<string, string> symbol => label used in the key row */
    public const KEY = [
        self::PRESENT => self::TERM_PRESENT,
        self::ABSENT => self::TERM_ABSENT,
        self::DAY_OFF => self::TERM_DAY_OFF,
        self::NOT_YET => self::TERM_NOT_YET,
        self::PERSONAL => 'Personal Leave',
        self::SICK => 'Sick Leave',
        self::MARITAL => 'Marital Leave',
        self::FUNERAL => 'Funeral Leave',
        self::MATERNITY => 'Maternity Leave',
        self::ANNUAL => 'Annual Holiday',
        self::FESTIVAL => self::TERM_FESTIVAL,
    ];

    /**
     * The eight "Reason for Leave (Days)" total columns, in register order.
     *
     * @var list<array{0: string, 1: string}> [heading, symbol]
     */
    public const TOTALS = [
        ['Abs', self::ABSENT],
        ['Per', self::PERSONAL],
        ['Sick', self::SICK],
        ['Mar', self::MARITAL],
        ['Fun', self::FUNERAL],
        ['Mat', self::MATERNITY],
        ['Ann', self::ANNUAL],
        ['Fest', self::FESTIVAL],
    ];

    /** Leave type name => symbol (used by the data migration and as a fallback). */
    public const LEAVE_TYPE_SYMBOLS = [
        'Casual' => self::PERSONAL,
        'Earned' => self::ANNUAL,
        'Sick' => self::SICK,
        'Marital' => self::MARITAL,
        'Funeral' => self::FUNERAL,
        'Maternity' => self::MATERNITY,
    ];

    /** The key row text, exactly as the register prints it. */
    public static function keyText(): string
    {
        $parts = [];
        foreach (self::KEY as $symbol => $label) {
            $parts[] = $symbol.' = '.$label;
        }

        return 'Symbol Key :    '.implode('     ', $parts);
    }

    public static function label(string $symbol): string
    {
        return self::KEY[$symbol] ?? ($symbol === self::UNKNOWN_LEAVE ? self::TERM_ON_LEAVE : 'No data');
    }

    /** The symbol for a leave type's configured symbol; never '/' and never empty. */
    public static function forLeaveSymbol(?string $configured): string
    {
        $configured = trim((string) $configured);

        return $configured === '' || $configured === self::DAY_OFF ? self::UNKNOWN_LEAVE : $configured;
    }

    /** @return array<string, string> */
    public static function toArray(): array
    {
        return [
            'key' => self::KEY,
            'key_text' => self::keyText(),
            'totals' => self::TOTALS,
            'unknown_leave' => self::UNKNOWN_LEAVE,
        ];
    }

    /** Fewest minutes worked on a rest day / holiday that can be claimed (a half day from 2 h; a full day from half the shift). */
    public const MIN_CLAIM_MINUTES = 120;

    /** Overtime application states for a worked day. */
    public const OT_APPROVED = 'approved';

    public const OT_DECLINED = 'declined';

    public const OT_PENDING = 'pending';

    /** The wording of each mark, used by every legend (Excel/PDF "Marks :" row and the Monthly Calendar). */
    public const MARK_LEGEND = [
        'ot_approved' => 'green √ = overtime approved',
        'ot_unapplied' => 'yellow = worked on a rest day/holiday, not claimed',
        'ot_declined' => 'light red = overtime declined',
        'rest_day_work' => 'bold border = worked on rest day',
        'leave_bold' => 'bold symbol = on leave',
    ];

    public static function marksLegendText(): string
    {
        return 'Marks :    '.implode('     ', self::MARK_LEGEND);
    }

    /**
     * Normalise an overtime claim status to approved | declined | pending.
     */
    public static function otState(?string $status): ?string
    {
        return match (strtolower((string) $status)) {
            'approved' => self::OT_APPROVED,
            'rejected', 'declined' => self::OT_DECLINED,
            'pending', 'submitted', 'in_review' => self::OT_PENDING,
            default => null,
        };
    }

    /** Symbols that mean "on leave" (shown in bold): the six leave types and the unknown-leave fallback. A replacement day is NOT leave: it is the plain weekly-off '/'. */
    public static function leaveSymbols(): array
    {
        return [self::PERSONAL, self::SICK, self::MARITAL, self::FUNERAL, self::MATERNITY, self::ANNUAL, self::UNKNOWN_LEAVE];
    }

    /**
     * The overtime, leave and rest-day marks of one day: the ONE rule set behind Excel, PDF and the Monthly Calendar
     * (owner rules, Amendment 3, as amended: overtime is NOT hourly).
     *
     *  - Overtime marks apply ONLY to days WORKED on a rostered rest day or on a holiday. Extra hours on a
     *    normal working day are not overtime and get no mark, however late the punch-out.
     *  - Claim approved: the √ turns green (font). No claim: yellow fill, but only where a claim is possible (a full day when at
     *    least half the rostered shift was worked, a half day from 2 h; under 2 h nothing is claimable, so no yellow; a rest day or holiday with a punch-in and no punch-out stays yellow: it can be claimed on a declared end time).
     *    Declined: light-red fill. Pending: no mark.
     *  - Rest-day work: bold border and a green √ (plus the fill above).
     *  - Holiday work keeps the QCD green background unless a claim fill (yellow / light red) applies; a holiday
     *    taken is the pink '#'.
     *  - Leave (and replacement leave): the symbol in bold.
     *
     * @param  ?string  $ot  approved | declined | pending | null (no claim)
     * @param  bool  $claimable  at least 2 h worked that day, or a rest day / holiday punched in without a punch-out (claimable on a declared end time)
     * @return array{fill: ?string, font: ?string, bold: bool, border: bool, ot: ?string}
     */
    public static function marks(string $symbol, bool $holiday, bool $restDayWork, ?string $ot, bool $claimable = true): array
    {
        $none = ['fill' => null, 'font' => null, 'bold' => false, 'border' => false, 'ot' => $ot];
        if (in_array($symbol, self::leaveSymbols(), true) && ! $holiday) {
            return ['bold' => true] + $none;
        }
        if ($holiday && $symbol === self::FESTIVAL) {
            return ['fill' => 'festival_taken'] + $none;
        }
        $holidayWork = $holiday && $symbol === self::PRESENT;
        if (! $holidayWork && ! ($symbol === self::PRESENT && $restDayWork)) {
            return $none; // a normal working day (or anything not worked): no overtime mark
        }

        $claimFill = match (true) {
            $ot === self::OT_DECLINED => 'ot_declined',
            $ot === null && $claimable => 'ot_unapplied',
            default => null,
        };

        return [
            'fill' => $claimFill ?? ($holidayWork ? 'festival_worked' : null),
            'font' => ($ot === self::OT_APPROVED || $restDayWork) ? 'ot_green' : null,
            'bold' => false,
            'border' => $restDayWork,
            'ot' => $ot,
        ];
    }

    /** True when the marks change how the cell looks beyond the QCD reference style (so a legend is needed). */
    public static function hasVisibleMark(array $marks): bool
    {
        return in_array($marks['fill'] ?? null, ['ot_unapplied', 'ot_declined'], true) || ($marks['font'] ?? null) !== null || ! empty($marks['bold']) || ! empty($marks['border']);
    }
}
