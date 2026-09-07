<?php

namespace App\Services\Biometric;

use App\Models\HRM\BiometricDevice;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Answer, from the ERP alone, the question that started this: *is this employee
 * really absent, or did we lose his punches?*
 *
 * ── Why it exists ───────────────────────────────────────────────────────────
 *
 * Answering it once took a full raw pull off the production MB460
 * (`AF6P231260266`) plus a dozen ad-hoc SQL queries. It will be asked again,
 * about a different employee, every month. This service is that question as a
 * read model.
 *
 * The pull itself settled half of it: 1,054 records, #1 → #1054 — the device's
 * entire history — reconciled as `new=0, duplicate=1054`. **Every device record
 * is already in the ERP.** Ingestion is not the problem. Conversion is: of 540
 * device user-days, 33 (6.1 %) never became an `attendances` row.
 *
 * ── Ingestion and conversion are never blended ──────────────────────────────
 *
 * Those are two different systems with two different failure modes and two
 * different fixes, and a single "sync health %" that averaged 100 % ingestion
 * with 94 % conversion would hide which one is broken — the only thing the
 * number is for. So this service returns them as two separate blocks with two
 * separate sentences, and deliberately computes no combined score.
 *
 * It also cannot measure ingestion by itself, and says so. "Did the device send
 * us everything it holds" is only answerable by asking the device, i.e. a full
 * log download. What this service reports under `ingestion` is therefore what
 * the last completed download session found, attributed to that session — never
 * an ERP-side inference dressed up as proof.
 *
 * ── What counts as a finding (and what must never) ──────────────────────────
 *
 * ONLY "the device has a punch on this day, the ERP has no attendance on this
 * day" is a finding.
 *
 * The inverse — ERP days far exceeding device days — is normal and must never be
 * flagged. Employees on WiFi/GPS attendance types punch through channels this
 * terminal never sees: Debashis Jha has 21 device days against 298 ERP days.
 * A reconciliation that reported that as a discrepancy would bury the 33 real
 * gaps under hundreds of false ones and would be worse than not existing.
 *
 * ── Day bucketing under a two-hour clock error ──────────────────────────────
 *
 * The MB460 runs exactly 2 h fast. `DeviceClockService` now corrects that at
 * ingest, but only since 2026-08-06, so `biometric_att_logs` holds a mixture:
 * recent rows carry `corrected_punch_time`, older ones do not. Bucketing device
 * punches by the raw `punch_time` would therefore drift for exactly the rows
 * that were corrected — a raw 01:30 punch is a real 23:30 punch on the PREVIOUS
 * day, so the punch would be counted against a day the attendance row was never
 * filed under, inventing a missing day and an unmatched attendance at once.
 *
 * The rule that removes the drift entirely:
 *
 *   **effective punch moment = corrected_punch_time ?? punch_time**
 *
 * That is, per row, the exact instant the capture path handed to
 * AttendancePunchService (see BiometricProcessingService: the synthetic request
 * is built from the corrected moment, and `corrected_punch_time` is written with
 * that same value whenever a correction was applied). AttendancePunchService
 * dates the row it creates from that instant's calendar day. Bucket and
 * attendance date therefore derive from one identical moment, and no offset —
 * applied, not applied, or changed later — can put them on different days.
 *
 * Today's data never exercises the drift window: no punch on this device falls
 * before 02:00 raw, so nothing would actually cross midnight. That is an
 * observation about this month's data, not a property of the design, which is
 * why the rule is the coalesce and not "it does not matter here".
 *
 * One consequence is worth stating because it is invisible otherwise: after
 * `biometric:correct-historical-clock-offset --apply` runs, historical
 * `attendances.punchin/punchout` shift by -2 h while `punch_time` stays raw and
 * `corrected_punch_time` stays NULL. Missing-day detection is unaffected — that
 * command aborts rather than move a punch across a date boundary, so
 * `attendances.date` never changes — but the exact-timestamp match used for
 * `device_derived_days` stops matching those rows. That figure is reported as a
 * secondary, best-effort attribution for exactly this reason; nothing in the
 * headline depends on it.
 *
 * ── Identifying device-derived attendance ───────────────────────────────────
 *
 * `attendances` has no `source` column. Consistent with
 * CorrectHistoricalClockOffset, a row is device-derived when its `punchin` or
 * `punchout` equals the punch moment of a `processed` `biometric_att_logs` row
 * for the same user on this device — the log says the punch reached attendance,
 * and the timestamp it reached it with is still sitting in the row.
 *
 * ── Portability ─────────────────────────────────────────────────────────────
 *
 * Query builder only, and all grouping/date arithmetic in PHP. No `DATE()`, no
 * `DATE_ADD`, no `COALESCE` over timestamps, no driver branch — SQLite (tests)
 * and MySQL (production) execute identical statements, so the behaviour the
 * tests pin is the behaviour production gets.
 */
class DeviceReconciliationService
{
    // ── Reason categories ──────────────────────────────────────────
    //
    // A missing day always carries exactly one category and every distinct
    // reason string verbatim. The category is what an admin acts on; the raw
    // strings are what they check the category against.

    /**
     * The punches were there and the punch path could not pair them.
     *
     * The dominant real cause: the terminal sent the day's FIRST punch as a
     * check-out (`status=1`), so `AttendancePunchService` had nothing to close
     * and answered "No open attendance record to punch out from." 22 of the 33
     * gaps, across 6 employees, and on 2026-07-11 three employees at once —
     * which is what makes it the terminal's IN/OUT mode rather than user error.
     * This is genuine data loss and it is recoverable.
     */
    public const CATEGORY_PAIRING = 'recoverable_pairing';

    /**
     * The punch was deliberately not converted, because of how the employee or
     * the device is configured: no attendance type, a non-biometric attendance
     * type (`wifi_ip_3`), or a device outside the type's zone.
     *
     * **This is not a bug and must never be reported as data loss.** The system
     * did what it was configured to do. It is surfaced as its own category
     * because the configuration may still be wrong — an employee on a WiFi type
     * who is standing at a fingerprint reader is a question for HR, not a defect
     * in ingestion.
     */
    public const CATEGORY_CONFIGURATION = 'configuration';

    /** The PIN matched no employee. Remediable from ATTLOG → Link to employee. */
    public const CATEGORY_UNKNOWN_PIN = 'unknown_pin';

    /**
     * Captured by a download session and still staged — nothing has attempted
     * to convert it yet. Real, unfinished work rather than a failure.
     */
    public const CATEGORY_AWAITING_IMPORT = 'awaiting_import';

    /** Anything else, including a punch marked processed whose day has no row. */
    public const CATEGORY_OTHER = 'other';

    /**
     * Which category wins when one day's punches disagree.
     *
     * Ordered by how completely the category explains the whole day. A PIN that
     * belongs to nobody, or an employee with no biometric attendance type,
     * accounts for every punch that day on its own; a pairing failure accounts
     * only for the punch it happened to. `other` is always last so a recognised
     * cause is never hidden behind an unrecognised one.
     *
     * @var list<string>
     */
    private const CATEGORY_PRECEDENCE = [
        self::CATEGORY_UNKNOWN_PIN,
        self::CATEGORY_CONFIGURATION,
        self::CATEGORY_AWAITING_IMPORT,
        self::CATEGORY_PAIRING,
        self::CATEGORY_OTHER,
    ];

    /**
     * Reason-text fragments → category, matched case-insensitively in order.
     *
     * Kept as data rather than a chain of ifs so the mapping is auditable
     * against the strings the two producing services actually emit
     * (AttendancePunchService's result messages and
     * BiometricProcessingService::validateAttendanceEligibility). Fragments, not
     * equality: several of these are concatenated with a slug or a trailing
     * detail.
     *
     * @var array<string, string>
     */
    private const REASON_PATTERNS = [
        // AttendancePunchService — pairing.
        'no open attendance record to punch out from' => self::CATEGORY_PAIRING,
        'punch-out cannot be before punch-in' => self::CATEGORY_PAIRING,
        'already punched in for this period' => self::CATEGORY_PAIRING,
        'duplicate punch ignored' => self::CATEGORY_PAIRING,

        // BiometricProcessingService::validateAttendanceEligibility — config.
        'user has no attendance type assigned' => self::CATEGORY_CONFIGURATION,
        'attendance type is not biometric' => self::CATEGORY_CONFIGURATION,
        'device not in attendance zone' => self::CATEGORY_CONFIGURATION,

        // The placeholder minted for a PIN nobody carries.
        'auto-created as inactive placeholder' => self::CATEGORY_UNKNOWN_PIN,

        // Staged by a download session, not yet replayed.
        'downloaded via active sync session' => self::CATEGORY_AWAITING_IMPORT,
    ];

    /**
     * `punch_status` → category, consulted BEFORE the reason text.
     *
     * The status is the pipeline's own verdict and is set even when the reason
     * is null, so it is the more reliable signal of the two. `failed` is absent
     * deliberately: it is the catch-all the punch path writes with the actual
     * message attached, so those rows must fall through to REASON_PATTERNS.
     *
     * @var array<string, string>
     */
    private const STATUS_CATEGORIES = [
        'unknown_user' => self::CATEGORY_UNKNOWN_PIN,
        'wrong_device' => self::CATEGORY_CONFIGURATION,
        'downloaded' => self::CATEGORY_AWAITING_IMPORT,
    ];

    /**
     * Longest range this will reconcile, in days.
     *
     * The whole production device holds 1,054 records, so this is not about
     * volume — it is a bound on what one admin click can make the server read,
     * on a screen that is reached from a polled admin panel.
     */
    public const MAX_RANGE_DAYS = 366;

    /** Default window when the caller names neither bound: the last 30 days. */
    public const DEFAULT_RANGE_DAYS = 30;

    /** Log rows read per pass. */
    private const CHUNK = 2000;

    /**
     * Days of slack on the raw-time pre-filter.
     *
     * The SQL filter can only use the RAW `punch_time`, but membership of the
     * range is decided on the corrected moment, which may sit on a different
     * day. Two days is DeviceClockService::MAX_PLAUSIBLE_OFFSET_SECONDS — the
     * largest correction that service will ever apply — so the pre-filter cannot
     * exclude a row the exact test would have kept.
     */
    private const RANGE_PAD_DAYS = 2;

    public function __construct(private readonly DeviceClockService $clock) {}

    // ──────────────────────────────────────────────────────────────
    //  Public read model
    // ──────────────────────────────────────────────────────────────

    /**
     * Reconcile one device's punches against `attendances` over a date range.
     *
     * @param  string|CarbonInterface|null  $from  inclusive, defaults to DEFAULT_RANGE_DAYS before $until
     * @param  string|CarbonInterface|null  $until  inclusive, defaults to today
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the range is inverted or too long
     */
    public function reconcile(BiometricDevice $device, $from = null, $until = null): array
    {
        $range = $this->resolveRange($from, $until);

        $punches = $this->loadPunches($device, $range['from'], $range['until']);

        $employees = $this->groupByEmployee($punches['rows']);

        $userIds = array_values(array_filter(array_map(
            fn (array $employee) => $employee['user_id'],
            $employees
        ), fn ($id) => $id !== null));

        $attendanceDays = $this->attendanceDaysByUser($userIds, $range['from'], $range['until']);
        $derivedDays = $this->deviceDerivedDaysByUser($punches['rows'], $userIds, $range['from'], $range['until']);
        $names = $this->resolveNames($userIds);

        $report = [];
        $totals = $this->emptyCategoryCounts();
        $deviceUserDays = 0;
        $missingUserDays = 0;
        $employeesWithGaps = 0;

        foreach ($employees as $employee) {
            $row = $this->buildEmployeeRow($employee, $attendanceDays, $derivedDays, $names);

            $deviceUserDays += $row['device_days'];
            $missingUserDays += $row['missing_days'];

            if ($row['missing_days'] > 0) {
                $employeesWithGaps++;
            }

            foreach ($row['by_category'] as $category => $count) {
                $totals[$category] += $count;
            }

            $report[] = $row;
        }

        // Worst first: the employee an admin has to look at is the one with the
        // most unexplained days, and ties break on who uses the device most.
        usort($report, function (array $a, array $b) {
            return [$b['missing_days'], $b['device_days'], $a['pin']]
                <=> [$a['missing_days'], $a['device_days'], $b['pin']];
        });

        $ingestion = $this->ingestion($device, $punches['record_count'], $range);
        $conversion = [
            'device_user_days' => $deviceUserDays,
            'converted_user_days' => $deviceUserDays - $missingUserDays,
            'missing_user_days' => $missingUserDays,
            'employees_seen' => count($report),
            'employees_with_gaps' => $employeesWithGaps,
            'by_category' => $totals,
        ];

        return [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'serial_number' => $device->serial_number,
            ],
            'range' => $range,
            'ingestion' => $ingestion,
            'conversion' => $conversion,
            'headline' => [
                'ingestion' => $ingestion['statement'],
                'conversion' => $this->conversionStatement($conversion),
            ],
            'employees' => $report,
            'categories' => self::categoryMeta(),
            // The device's clock, because every timestamp on this screen depends
            // on whether it is being corrected and a reader must not have to go
            // to another tab to find out.
            'clock' => $this->clock->snapshot($device),
            'unparsable_punches' => $punches['unparsable'],
        ];
    }

    /**
     * Which category a single log row falls into.
     *
     * Public because it is the whole judgement of this service in one function
     * and is worth pinning directly in tests, rather than only through the
     * aggregate that happens to call it.
     */
    public function categorise(?string $status, ?string $reason): string
    {
        $status = strtolower(trim((string) $status));

        if (isset(self::STATUS_CATEGORIES[$status])) {
            return self::STATUS_CATEGORIES[$status];
        }

        $reason = strtolower(trim((string) $reason));

        if ($reason !== '') {
            foreach (self::REASON_PATTERNS as $fragment => $category) {
                if (str_contains($reason, $fragment)) {
                    return $category;
                }
            }
        }

        return self::CATEGORY_OTHER;
    }

    /**
     * Category labels and — the part that matters — what each one MEANS.
     *
     * `nature` is what stops the UI averaging these together: a configuration
     * day and a pairing day are both "did not become attendance" and are not the
     * same event at all. Only `data_loss` is a punch we cannot account for.
     *
     * @return array<string, array{label: string, nature: string, summary: string}>
     */
    public static function categoryMeta(): array
    {
        return [
            self::CATEGORY_PAIRING => [
                'label' => 'Punch pairing failed',
                'nature' => 'data_loss',
                // The last clause is load-bearing. The obvious reading of "the
                // terminal sent it as a check-out" is "go and change the
                // terminal's IN/OUT mode", and that is not available: probing
                // this hardware for ~ShowState, AlwaysShowState, AS1 and AS2
                // returned `return=0` with all four keys silently omitted, so
                // attendance state is not remotely settable on this model. The
                // ERP-side recovery is the only remedy, and the screen must not
                // send anyone to the device for a fix that does not exist there.
                'summary' => 'The punches reached the ERP but could not be paired into a day — most often the terminal sent the day\'s first punch as a check-out, so there was no open record to close. This is not fixable at the terminal: this model does not expose its attendance-state keys to a remote write. Recoverable in the ERP, where the punch path can rebuild these days from the punches already stored.',
            ],
            self::CATEGORY_CONFIGURATION => [
                'label' => 'Configuration / policy',
                'nature' => 'needs_review',
                'summary' => 'The punch was deliberately not converted because of how the employee or device is set up — no attendance type, a non-biometric attendance type, or a device outside the type\'s zone. This is not data loss; it is a configuration question.',
            ],
            self::CATEGORY_UNKNOWN_PIN => [
                'label' => 'Unknown PIN',
                'nature' => 'data_loss',
                'summary' => 'The device PIN matched no employee, so the punch could not be attributed to anyone. Fix it from ATTLOG → Link to employee, which re-queues the stranded punches.',
            ],
            self::CATEGORY_AWAITING_IMPORT => [
                'label' => 'Staged, not imported',
                'nature' => 'unfinished',
                'summary' => 'Pulled off the device by a download session and still staged. Nothing has tried to convert these yet — run the session\'s Import action.',
            ],
            self::CATEGORY_OTHER => [
                'label' => 'Unexplained',
                'nature' => 'unknown',
                'summary' => 'No recognised reason was recorded against the punches for this day. Includes punches the pipeline marked processed whose attendance row is nonetheless absent — those are worth looking at by hand.',
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Range
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{from: string, until: string, days: int}
     */
    private function resolveRange($from, $until): array
    {
        $end = $this->parseDate($until) ?? Carbon::now()->startOfDay();
        $start = $this->parseDate($from) ?? $end->copy()->subDays(self::DEFAULT_RANGE_DAYS - 1);

        if ($start->gt($end)) {
            throw new InvalidArgumentException(
                'The start date ('.$start->format('Y-m-d').') is after the end date ('.$end->format('Y-m-d').'); that range selects nothing.'
            );
        }

        // +1: both bounds are inclusive, so 1 Jan .. 1 Jan is one day.
        $days = (int) $start->diffInDays($end) + 1;

        if ($days > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException(
                'That range covers '.$days.' days. This report reads every punch in the window, so it is capped at '.self::MAX_RANGE_DAYS.' days.'
            );
        }

        return [
            'from' => $start->format('Y-m-d'),
            'until' => $end->format('Y-m-d'),
            'days' => $days,
        ];
    }

    private function parseDate($value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value->toDateTime())->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('"'.$value.'" is not a date this report can read. Use YYYY-MM-DD.');
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────────────────────

    /**
     * Every punch this device holds whose EFFECTIVE moment falls in the range.
     *
     * The SQL filter is deliberately wider than the range (see RANGE_PAD_DAYS):
     * it can only see the raw timestamp, and the exact membership test is on the
     * corrected moment. Rows outside the real range are dropped in PHP.
     *
     * @return array{rows: list<array<string, mixed>>, record_count: int, unparsable: int}
     */
    private function loadPunches(BiometricDevice $device, string $from, string $until): array
    {
        $lower = Carbon::parse($from)->subDays(self::RANGE_PAD_DAYS)->format('Y-m-d H:i:s');
        $upper = Carbon::parse($until)->addDays(self::RANGE_PAD_DAYS + 1)->format('Y-m-d H:i:s');

        $rows = [];
        $unparsable = 0;

        DB::table('biometric_att_logs')
            ->select([
                'id',
                'user_id',
                'user_pin',
                'punch_time',
                'corrected_punch_time',
                'check_type',
                'punch_status',
                'punch_status_reason',
            ])
            ->where('biometric_device_id', $device->id)
            ->where('punch_time', '>=', $lower)
            ->where('punch_time', '<', $upper)
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($chunk) use (&$rows, &$unparsable, $from, $until) {
                foreach ($chunk as $row) {
                    // THE RULE. The instant the capture path handed to
                    // AttendancePunchService, which is the instant the
                    // attendance row was dated from. See the class docblock.
                    $effective = $this->parseMoment($row->corrected_punch_time ?? null)
                        ?? $this->parseMoment($row->punch_time ?? null);

                    if ($effective === null) {
                        // A device timestamp we cannot read. Counted and
                        // reported rather than dropped in silence — a device
                        // producing these is itself a finding.
                        $unparsable++;

                        continue;
                    }

                    $date = $effective->format('Y-m-d');

                    if ($date < $from || $date > $until) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => $row->user_id === null ? null : (string) $row->user_id,
                        'pin' => (string) $row->user_pin,
                        'date' => $date,
                        'effective' => $effective->format('Y-m-d H:i:s'),
                        'raw' => (string) $row->punch_time,
                        'corrected' => $row->corrected_punch_time !== null,
                        'check_type' => (string) ($row->check_type ?? ''),
                        'status' => (string) ($row->punch_status ?? ''),
                        'reason' => $row->punch_status_reason === null ? null : (string) $row->punch_status_reason,
                    ];
                }
            });

        return [
            'rows' => $rows,
            'record_count' => count($rows),
            'unparsable' => $unparsable,
        ];
    }

    private function parseMoment($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Punches → employees → days.
     *
     * Keyed on the device PIN, which is what the terminal knows and what the
     * question is always asked about; `user_id` rides along for the joins. A
     * punch whose PIN resolved to nobody still gets a group, which is the whole
     * point of the unknown-PIN category.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function groupByEmployee(array $rows): array
    {
        $employees = [];

        foreach ($rows as $row) {
            $pin = $row['pin'];

            if (! isset($employees[$pin])) {
                $employees[$pin] = [
                    'pin' => $pin,
                    'user_id' => $row['user_id'],
                    'days' => [],
                ];
            }

            if ($employees[$pin]['user_id'] === null && $row['user_id'] !== null) {
                $employees[$pin]['user_id'] = $row['user_id'];
            }

            $employees[$pin]['days'][$row['date']][] = $row;
        }

        return $employees;
    }

    /**
     * Distinct dates each user has ANY attendance row on.
     *
     * Every row, not only device-derived ones. An employee who also punches over
     * WiFi or GPS is present on those days, and the question this service exists
     * to answer — is he really absent — is answered by attendance existing at
     * all, whatever channel wrote it.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, true>>
     */
    private function attendanceDaysByUser(array $userIds, string $from, string $until): array
    {
        if ($userIds === []) {
            return [];
        }

        $days = [];

        DB::table('attendances')
            ->select(['user_id', 'date'])
            ->whereIn('user_id', $userIds)
            ->where('date', '>=', $from)
            // `<` the day AFTER `until`, not `<=` `until`: `date` is a DATE
            // column on MySQL but SQLite stores whatever string was written, and
            // a row saved as "2026-07-31 00:00:00" sorts after "2026-07-31".
            ->where('date', '<', Carbon::parse($until)->addDay()->format('Y-m-d'))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($chunk) use (&$days) {
                foreach ($chunk as $row) {
                    $days[(string) $row->user_id][substr((string) $row->date, 0, 10)] = true;
                }
            });

        return $days;
    }

    /**
     * Attendance days this device can be shown to have produced.
     *
     * Same identification rule as CorrectHistoricalClockOffset: `attendances`
     * has no `source` column, so a row is device-derived when a punch column
     * holds the punch moment of a `processed` log row for the same user. Only
     * `processed` rows are matched — a `duplicate` row exists precisely because
     * some other row already wrote that attendance, and counting it would
     * attribute one day twice.
     *
     * Secondary and best-effort by construction: it is an exact timestamp
     * comparison, so a historical clock correction that rewrites `punchin` will
     * silently stop matching. Nothing in the headline is computed from it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $userIds
     * @return array<int, array<string, true>>
     */
    private function deviceDerivedDaysByUser(array $rows, array $userIds, string $from, string $until): array
    {
        if ($userIds === []) {
            return [];
        }

        $moments = [];

        foreach ($rows as $row) {
            if ($row['user_id'] === null || $row['status'] !== 'processed') {
                continue;
            }

            $moments[$row['user_id']][$row['effective']] = true;
        }

        if ($moments === []) {
            return [];
        }

        $derived = [];

        DB::table('attendances')
            ->select(['user_id', 'date', 'punchin', 'punchout'])
            ->whereIn('user_id', array_keys($moments))
            ->where('date', '>=', $from)
            ->where('date', '<', Carbon::parse($until)->addDay()->format('Y-m-d'))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($chunk) use (&$derived, $moments) {
                foreach ($chunk as $row) {
                    $userId = (string) $row->user_id;
                    $candidates = $moments[$userId] ?? [];

                    if ($candidates === []) {
                        continue;
                    }

                    foreach (['punchin', 'punchout'] as $column) {
                        $moment = $this->parseMoment($row->{$column} ?? null);

                        if ($moment !== null && isset($candidates[$moment->format('Y-m-d H:i:s')])) {
                            $derived[$userId][substr((string) $row->date, 0, 10)] = true;

                            break;
                        }
                    }
                }
            });

        return $derived;
    }

    /**
     * Names for the report.
     *
     * Raw query builder, so soft-deleted users are included on purpose: an
     * unknown PIN's placeholder is soft-deleted by design, and a report that
     * showed those rows as nameless would hide the very thing it is reporting.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{name: string, employee_id: string|null, deleted: bool}>
     */
    private function resolveNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('users')
            ->select(['employee_id', 'name', 'deleted_at'])
            ->whereIn('employee_id', $userIds)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->employee_id => [
                'name' => (string) $row->name,
                'employee_id' => $row->employee_id === null ? null : (string) $row->employee_id,
                'deleted' => $row->deleted_at !== null,
            ]])
            ->all();
    }

    // ──────────────────────────────────────────────────────────────
    //  Per-employee assembly
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $employee
     * @param  array<string, array<string, true>>  $attendanceDays
     * @param  array<string, array<string, true>>  $derivedDays
     * @param  array<string, array{name: string, employee_id: string|null, deleted: bool}>  $names
     * @return array<string, mixed>
     */
    private function buildEmployeeRow(array $employee, array $attendanceDays, array $derivedDays, array $names): array
    {
        $userId = $employee['user_id'];
        $present = $userId === null ? [] : ($attendanceDays[$userId] ?? []);
        $derived = $userId === null ? [] : ($derivedDays[$userId] ?? []);

        $days = $employee['days'];
        ksort($days);

        $missing = [];
        $counts = $this->emptyCategoryCounts();

        foreach ($days as $date => $punches) {
            if (isset($present[$date])) {
                continue;
            }

            $day = $this->describeMissingDay((string) $date, $punches);
            $counts[$day['category']]++;
            $missing[] = $day;
        }

        $identity = $userId === null ? null : ($names[$userId] ?? null);

        return [
            'pin' => $employee['pin'],
            'user_id' => $userId,
            'name' => $identity['name'] ?? null,
            'employee_id' => $identity['employee_id'] ?? null,
            // A soft-deleted user behind a PIN is the auto-created placeholder,
            // i.e. this PIN belongs to nobody. Rendered as such, never as a name.
            'is_placeholder' => (bool) ($identity['deleted'] ?? false),
            'device_days' => count($days),
            // Deliberately ALL attendance days, which is why this can and should
            // exceed device_days for anyone who also uses WiFi/GPS. It is not a
            // discrepancy and is never subtracted from anything.
            'erp_days' => count($present),
            'device_derived_days' => count($derived),
            'missing_days' => count($missing),
            'by_category' => $counts,
            'missing' => $missing,
        ];
    }

    /**
     * One missing day: what the device saw, and why it did not become attendance.
     *
     * The day takes a single category — the most explanatory one present, see
     * CATEGORY_PRECEDENCE — and still carries every distinct status and reason
     * string verbatim, so the categorisation can always be checked against what
     * the pipeline actually wrote.
     *
     * @param  list<array<string, mixed>>  $punches
     * @return array<string, mixed>
     */
    private function describeMissingDay(string $date, array $punches): array
    {
        usort($punches, fn (array $a, array $b) => $a['effective'] <=> $b['effective']);

        $categories = [];
        $reasons = [];
        $statuses = [];
        $corrected = false;

        foreach ($punches as $punch) {
            $categories[$this->categorise($punch['status'], $punch['reason'])] = true;

            if ($punch['status'] !== '') {
                $statuses[$punch['status']] = true;
            }

            if ($punch['reason'] !== null && trim($punch['reason']) !== '') {
                $reasons[$punch['reason']] = true;
            }

            $corrected = $corrected || $punch['corrected'];
        }

        $first = $punches[0];
        $last = $punches[count($punches) - 1];

        return [
            'date' => $date,
            'punches' => count($punches),
            'first_punch' => $first['effective'],
            'last_punch' => $last['effective'],
            // The device's own account, kept next to the corrected one so a
            // reader can see the two-hour shift rather than take it on trust.
            'first_punch_raw' => $first['raw'],
            'last_punch_raw' => $last['raw'],
            'clock_corrected' => $corrected,
            'check_types' => array_values(array_unique(array_map(
                fn (array $punch) => $punch['check_type'],
                $punches
            ))),
            'category' => $this->dominantCategory(array_keys($categories)),
            'statuses' => array_keys($statuses),
            // array_KEYS, not values: $reasons is a set keyed on the reason
            // string so repeats collapse, and the values are all `true`.
            'reasons' => array_keys($reasons),
        ];
    }

    /**
     * @param  list<string>  $categories
     */
    private function dominantCategory(array $categories): string
    {
        foreach (self::CATEGORY_PRECEDENCE as $candidate) {
            if (in_array($candidate, $categories, true)) {
                return $candidate;
            }
        }

        return self::CATEGORY_OTHER;
    }

    /**
     * @return array<string, int>
     */
    private function emptyCategoryCounts(): array
    {
        return array_fill_keys(array_keys(self::categoryMeta()), 0);
    }

    // ──────────────────────────────────────────────────────────────
    //  The two statements
    // ──────────────────────────────────────────────────────────────

    /**
     * What we know about ingestion — and who told us.
     *
     * `records_in_range` is an ERP-side count and proves nothing about
     * completeness on its own: it is how many punches we hold, not how many the
     * device holds. The only evidence for completeness is a full log download,
     * so `last_full_pull` is reported as an attributed measurement with its own
     * date, and `statement` is null when no such pull exists rather than
     * inventing a reassuring number.
     *
     * @param  array{from: string, until: string, days: int}  $range
     * @return array<string, mixed>
     */
    private function ingestion(BiometricDevice $device, int $recordsInRange, array $range): array
    {
        $session = DB::table('biometric_download_sessions')
            ->where('biometric_device_id', $device->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        $pull = null;
        $statement = null;

        if ($session !== null) {
            $total = (int) $session->total_records;
            $newRecords = (int) $session->processed_count;
            $duplicates = (int) $session->duplicate_count;
            $failed = (int) $session->failed_count;

            $pull = [
                'session_id' => (int) $session->id,
                'completed_at' => $session->completed_at,
                'total_records' => $total,
                'new_records' => $newRecords,
                'already_held' => $duplicates,
                'failed' => $failed,
            ];

            $accounted = $newRecords + $duplicates;

            $statement = number_format($accounted).' of '.number_format($total)
                .' device records ingested'
                .($newRecords === 0 && $total > 0
                    ? ' — the last full pull found nothing the ERP did not already hold.'
                    : ' ('.number_format($newRecords).' new on the last full pull).')
                .($failed > 0 ? ' '.number_format($failed).' record(s) failed.' : '');
        }

        return [
            'records_in_range' => $recordsInRange,
            'last_full_pull' => $pull,
            'statement' => $statement,
            'note' => 'Whether the device still holds punches we have never seen can only be answered by the device. Run a full log download from the Downloads tab; the figures above are that download\'s own result, not an inference from this table.',
        ];
    }

    /**
     * @param  array<string, mixed>  $conversion
     */
    private function conversionStatement(array $conversion): string
    {
        $days = (int) $conversion['device_user_days'];
        $missing = (int) $conversion['missing_user_days'];

        if ($days === 0) {
            return 'This device recorded no punches in this range.';
        }

        if ($missing === 0) {
            return number_format($days).' device user-day(s) in range, and every one of them became an attendance record.';
        }

        $configuration = (int) $conversion['by_category'][self::CATEGORY_CONFIGURATION];
        $lost = $missing - $configuration;

        // The two halves are stated separately and never summed into a rate:
        // configuration days are the system doing as configured, and folding
        // them into a loss figure would overstate the defect by a third.
        return number_format($missing).' of '.number_format($days)
            .' device user-day(s) did not become attendance'
            .($configuration > 0
                ? ' — '.number_format($lost).' unaccounted for, '.number_format($configuration)
                    .' explained by attendance-type or zone configuration (not data loss).'
                : '.');
    }
}
