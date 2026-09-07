<?php

namespace App\Console\Commands;

use App\Models\HRM\BiometricDevice;
use App\Services\Biometric\DeviceClockService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Shift the attendance history a skewed device wrote before we started
 * correcting it at ingest.
 *
 * ── The damage ──────────────────────────────────────────────────────────────
 *
 * The production MB460 (`AF6P231260266`) has reported timestamps exactly two
 * hours in the future since the day it was installed — 827 live-push samples
 * across four months, constant to the second, a timezone the firmware recomputes
 * from rather than drift. `DeviceClockService` now measures that and corrects it
 * at ingest on both the live-push and downloaded-import paths, but it only
 * started on 2026-08-06. Every biometric punch written before that is two hours
 * late in `attendances`: a 09:00 arrival stored as 11:00, a 17:00 departure that
 * reads as two hours of overtime. This command corrects that history. It does
 * not touch the forward path, which is already handled.
 *
 * ── Why the correction is 7200 s and not the measured 7195 ──────────────────
 *
 * The live estimator is a rolling median of `device_time − server_receive_time`,
 * and it currently reads 7195 s. That number is the truth plus transport, not
 * the truth. Observed samples ranged 7186–7197 s and **never once exceeded
 * 7197**. If the device's real error were 7195, roughly half the samples would
 * have to sit above it — and a sample above the true offset requires the push to
 * arrive before it was sent. Negative network latency is not a thing. A constant
 * two-hour timezone error, minus 3–14 s of transmission and handler delay, fits
 * every one of the 827 samples and puts the ceiling exactly where one was
 * observed. So the device's clock is +7200, and the estimator's 7195 is 7200
 * minus a typical round trip.
 *
 * That reasoning is written down here because it is an argument, not a
 * measurement, and the next device will need its own. `--seconds` is therefore
 * REQUIRED and has no default: nobody should ever correct a second device by
 * inheriting a number that was argued about this one.
 *
 * ── Sign convention (the same one DeviceClockService uses) ──────────────────
 *
 *   --seconds = device_time − server_time.
 *               POSITIVE means the device ran FAST and the stored punch times
 *               are too LATE, so they shift BACK. The MB460 takes --seconds=7200
 *               and every affected punch moves by -7200.
 *
 * ── How a biometric row is identified (there is no `source` column) ─────────
 *
 * `attendances` does not record where a punch came from. A row is biometric-
 * derived when its `punchin` or `punchout` is exactly the `punch_time` of a
 * `processed` `biometric_att_logs` row for the same user — that is, the log says
 * "this punch reached attendance" and the timestamp it reached it with is still
 * sitting in the row. Web, mobile and manually-entered attendance has no such
 * log and is never selected.
 *
 * That test is applied PER COLUMN, not per row, and the distinction is not
 * academic. A mixed day is ordinary: the employee taps in at the terminal and an
 * admin closes the day by hand, or a web punch-in is paired with a device
 * punch-out, or two terminals cover one shift and only one of them has a skewed
 * clock. Selecting the row and then shifting both of its punches would take a
 * correct 09:00 web arrival to 07:00 and invent two hours of work — the exact
 * class of silent payroll damage this command exists to undo. So `punchin` and
 * `punchout` are matched independently against THIS device's logs and only the
 * side that matches is moved; the ledger records the untouched side as NULL,
 * which is also how a reviewer can see that the row was mixed.
 *
 * Two further conditions narrow it to history that predates the fix:
 *
 *  - `biometric_att_logs.biometric_device_id` must be the device named by
 *    `--device`. This command never operates on "all devices"; a clock error is
 *    a property of one unit.
 *  - `corrected_punch_time IS NULL`. A log row with that column set was already
 *    corrected at ingest, so `attendances` holds the corrected moment and
 *    `punch_time` is merely the raw device string. Excluding those rows means a
 *    coincidental match against a raw timestamp cannot pull in a punch that is
 *    already right.
 *
 * ── Never twice: the primary design constraint ─────────────────────────────
 *
 * Shifting the same punch by another two hours would be silent, plausible and
 * payroll-visible. The guard is UNIQUE `(attendance_id, punch_column)` on
 * `attendance_clock_corrections`: ONE LEDGER ROW PER CORRECTED PUNCH. Before a
 * punch is updated its row's full original is committed there, so:
 *
 *  - the claim is per punch, keyed on the attendance PRIMARY KEY plus which
 *    column, so a re-run with a different `--seconds`, a wider date range or the
 *    wrong device still hits it;
 *  - it is a constraint, not a filter, so two concurrent runs cannot both pass a
 *    check-then-act — the loser's batch insert throws and its transaction never
 *    reaches the `UPDATE`;
 *  - it does not rely on the shifted timestamp ceasing to match
 *    `biometric_att_logs.punch_time`. That is true at 7200 s, but it is a
 *    property of this offset, not of the design.
 *
 * The granularity is per COLUMN and not per row because a row can need
 * correcting twice for two different reasons. Attendance 9901 had its punch-in
 * corrected in the first production run and its punch-out skipped, because the
 * punch-out's only log row was marked `duplicate`. Under a per-row key that
 * punch-out could never be reached again: the archive insert would collide with
 * the row's existing entry before any update was issued, and the day would stay
 * at 9.5 hours for 7.5 worked — half-corrected, which reads as correct. Per
 * column, the punch-in stays permanently claimed while the punch-out is free.
 *
 * The archive is committed in its OWN transaction, before the update transaction
 * opens. A crash between the two leaves a guarded-but-unshifted row
 * (`applied_at IS NULL`), which this command reports and refuses to adopt. The
 * failure mode is under-correction, which is visible and repairable; the
 * alternative ordering risks a shifted row with no ledger entry, which is not.
 *
 * ── Dates never move ────────────────────────────────────────────────────────
 *
 * No affected punch falls before 02:00, so a -2 h shift never lands on the
 * previous day and `attendances.date` never needs to change. That is an
 * observation about today's data, not a guarantee, so it is re-checked at run
 * time for every row: if any shifted punch would change calendar date the whole
 * run aborts before writing anything. Moving a row across midnight can collide
 * with the adjacent day's row and is a materially harder operation than this
 * command should ever attempt silently.
 *
 * ── What this command does NOT do ───────────────────────────────────────────
 *
 * It does not recompute `symbol`, `policy_status`, `needs_approval` or
 * `policy_exception_reason`. Of those, `symbol` is not derived from punch times
 * at all and late/OT minutes are computed at read time, so both are fine. The
 * policy trio is decided at punch-IN by PunchPolicyGuard and only its
 * `restrict` branch is time-dependent, so only those verdicts can go stale. The
 * run counts them rather than quietly re-deciding a policy exception an approver
 * may already have actioned. See the "derived state" block printed at the end of
 * every run for the exact conditions and counts.
 *
 * ── Portability ─────────────────────────────────────────────────────────────
 *
 * Query builder only. No `DATE_ADD`, no `datetime(?, '+N seconds')`, no
 * `DB::getDriverName()` branch: every timestamp is shifted in PHP and written as
 * a plain `Y-m-d H:i:s` string, so SQLite (tests) and MySQL (production) execute
 * the same statements. Rows are walked by keyset id in bounded chunks, so the
 * memory cost does not depend on how many rows are affected.
 */
class CorrectHistoricalClockOffset extends Command
{
    protected $signature = 'biometric:correct-historical-clock-offset
        {--device= : REQUIRED. Serial number (preferred) or id of the device whose history to correct}
        {--seconds= : REQUIRED. Signed offset, device_time minus server_time. Positive = device ran fast, punches shift back. The MB460 takes 7200}
        {--from= : Only correct attendance dated on or after this date (Y-m-d)}
        {--until= : Only correct attendance dated on or before this date (Y-m-d)}
        {--apply : Actually write. WITHOUT THIS FLAG THE COMMAND IS A DRY RUN and changes nothing}
        {--chunk=200 : Attendance rows per batch}
        {--samples=10 : Before/after rows to print}';

    protected $description = 'Retroactively correct attendance punch times written by a device with a known clock offset (dry run by default)';

    public const LEDGER = 'attendance_clock_corrections';

    /**
     * Result-set aliases carrying "did THIS device report this punch".
     *
     * Prefixed so they cannot collide with a real `attendances` column now or
     * after the table grows; stripped again before the row is archived, so the
     * payload stays a faithful copy of `attendances` and not of this query.
     */
    private const MATCH_IN = 'clock_correction_match_punchin';

    private const MATCH_OUT = 'clock_correction_match_punchout';

    private const GUARD_IN = 'clock_correction_guard_punchin';

    private const GUARD_OUT = 'clock_correction_guard_punchout';

    /** The two punch columns this command can shift, in row order. */
    private const PUNCH_COLUMNS = ['punchin', 'punchout'];

    /**
     * Staged but never converted into attendance. Excluded from correction and
     * reported instead — see reportedByDevice().
     */
    private const STATUS_DOWNLOADED = 'downloaded';

    /**
     * Refuse an offset larger than this. Shares DeviceClockService's bound: two
     * days is far past anything a timezone or a wrong-by-a-day clock produces,
     * and anything beyond it is a typo or a misunderstanding of the sign.
     */
    private const MAX_OFFSET_SECONDS = DeviceClockService::MAX_PLAUSIBLE_OFFSET_SECONDS;

    /** Boundary-crossing rows listed in the abort message before it is truncated. */
    private const MAX_REPORTED_CROSSINGS = 20;

    private BiometricDevice $device;

    /** Signed adjustment ADDED to stored punch times. Negation of --seconds. */
    private int $appliedSeconds;

    private ?string $from = null;

    private ?string $until = null;

    private int $chunkSize = 200;

    public function __construct(private readonly DeviceClockService $clock)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable(self::LEDGER)) {
            $this->error('The '.self::LEDGER.' table does not exist. Run `php artisan migrate` first — it is the archive and the re-run guard, and this command will not touch attendance without it.');

            return self::FAILURE;
        }

        if (($resolved = $this->resolveOptions()) !== self::SUCCESS) {
            return $resolved;
        }

        $this->printHeader();

        $scan = $this->scan();

        $this->printScan($scan);

        if ($scan['rows'] === 0) {
            $this->newLine();
            $this->info('Nothing to correct.');

            return self::SUCCESS;
        }

        if ($scan['crossing_count'] > 0) {
            return $this->abortOnDateCrossing($scan);
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --apply to commit these changes.');

            return self::SUCCESS;
        }

        return $this->apply($scan);
    }

    // ──────────────────────────────────────────────────────────────
    //  Options
    // ──────────────────────────────────────────────────────────────

    private function resolveOptions(): int
    {
        $deviceOption = trim((string) $this->option('device'));

        if ($deviceOption === '') {
            $this->error('--device is required. This command never operates on every device implicitly: a clock error belongs to one unit, and applying one unit\'s offset to another\'s history would corrupt correct data.');

            return self::FAILURE;
        }

        $device = $this->findDevice($deviceOption);

        if ($device === null) {
            $this->error("No biometric device matches --device={$deviceOption} (tried serial number, then id).");

            return self::FAILURE;
        }

        $this->device = $device;

        $secondsOption = trim((string) $this->option('seconds'));

        if ($secondsOption === '') {
            $this->error('--seconds is required and has no default. It is the device\'s clock offset (device_time minus server_time); positive means the device ran fast and stored punches shift back. The MB460 AF6P231260266 takes --seconds=7200.');

            return self::FAILURE;
        }

        if (! preg_match('/^-?\d+$/', $secondsOption)) {
            $this->error("--seconds must be a whole number of seconds, signed. Got: {$secondsOption}");

            return self::FAILURE;
        }

        $seconds = (int) $secondsOption;

        if ($seconds === 0) {
            $this->error('--seconds=0 would archive every matching row while changing nothing, burning the one-shot re-run guard for no benefit. Refusing.');

            return self::FAILURE;
        }

        if (abs($seconds) > self::MAX_OFFSET_SECONDS) {
            $this->error('--seconds='.$seconds.' exceeds the '.self::MAX_OFFSET_SECONDS.'s (2 day) sanity bound. A real clock error — even a wrong timezone or a wrong day — is far smaller than this.');

            return self::FAILURE;
        }

        // The sign flip happens exactly once, here. A device that reads LATE than
        // us wrote punches that are too late, so they move back.
        $this->appliedSeconds = -$seconds;

        foreach (['from', 'until'] as $bound) {
            $raw = trim((string) $this->option($bound));

            if ($raw === '') {
                continue;
            }

            try {
                $this->{$bound} = Carbon::parse($raw)->format('Y-m-d');
            } catch (\Throwable) {
                $this->error("--{$bound} is not a date this command can read: {$raw}");

                return self::FAILURE;
            }
        }

        if ($this->from !== null && $this->until !== null && $this->from > $this->until) {
            $this->error("--from ({$this->from}) is after --until ({$this->until}); that range selects nothing.");

            return self::FAILURE;
        }

        $this->chunkSize = max(1, (int) $this->option('chunk'));

        return self::SUCCESS;
    }

    /**
     * Serial first, then id.
     *
     * Serial is what an operator reads off the unit and what every log line
     * carries, so it wins even when it looks like a number. The id is accepted as
     * a convenience for someone working from the admin screen.
     */
    private function findDevice(string $value): ?BiometricDevice
    {
        $bySerial = BiometricDevice::where('serial_number', $value)->first();

        if ($bySerial !== null) {
            return $bySerial;
        }

        if (ctype_digit($value)) {
            return BiometricDevice::find((int) $value);
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Selection
    // ──────────────────────────────────────────────────────────────

    /**
     * Attendance rows this run is allowed to touch.
     *
     * `whereExists` rather than a join: a row can match several log rows (its in
     * punch and its out punch, at least) and a join would return it once per
     * match, which then needs a DISTINCT and defeats keyset chunking. EXISTS asks
     * the only question that matters — is there at least one — and returns each
     * attendance row once.
     */
    private function candidates()
    {
        $query = DB::table('attendances')
            ->select('attendances.*')
            // Per-column state, decided by the database. Scalar subqueries rather
            // than a raw EXISTS: `addSelect(['alias' => $builder])` emits the same
            // standard SQL on SQLite and MySQL, returning 1 or NULL, with no
            // driver branch and no raw fragment to get wrong.
            ->addSelect([
                self::MATCH_IN => $this->reportedByDevice('punchin'),
                self::MATCH_OUT => $this->reportedByDevice('punchout'),
                self::GUARD_IN => $this->alreadyCorrected('punchin'),
                self::GUARD_OUT => $this->alreadyCorrected('punchout'),
            ])
            // Selected when EITHER punch is this device's AND that same punch has
            // not already been corrected. Per column, both halves — a row whose
            // punch-in was corrected in an earlier run is still selected for its
            // punch-out, and vice versa.
            ->where(function ($outer) {
                foreach (self::PUNCH_COLUMNS as $column) {
                    $outer->orWhere(function ($clause) use ($column) {
                        $clause->whereExists($this->reportedByDevice($column))
                            // An OPTIMISATION, not the guard — say so plainly,
                            // because mislabelling it invites someone to trust
                            // it. A row can be selected here and still have one
                            // guarded column (that is the whole point of the OR),
                            // so the per-column decision has to be re-made in
                            // shiftFor() regardless. What this buys is not
                            // safety: it keeps the 459 punches already corrected
                            // on production out of the walk entirely instead of
                            // dragging them through every chunk to be discarded.
                            ->whereNotExists($this->alreadyCorrected($column));
                    });
                }
            });

        if ($this->from !== null) {
            $query->where('attendances.date', '>=', $this->from);
        }

        if ($this->until !== null) {
            $query->where('attendances.date', '<=', $this->until);
        }

        return $query;
    }

    /**
     * "Did THIS device report the punch currently sitting in `$column`?"
     *
     * The evidence is EXACT TIMESTAMP EQUALITY between the stored punch and a raw
     * `biometric_att_logs.punch_time` for the same user and device. Nothing else
     * identifies a device-sourced value: `attendances` has no `source` column,
     * and a human cannot type a time that coincides with a terminal's reading to
     * the second.
     *
     * ── Why `punch_status` is NOT part of the test ──────────────────────────
     *
     * It used to require `processed`, and that was wrong. `punch_status`
     * describes the LOG ROW — whether that particular capture converted into
     * attendance — not whether the value in `attendances` came from the device.
     * A punch pushed once and re-captured later has one `processed` row and one
     * `duplicate` row for the same instant, and which of the two the query finds
     * says nothing about the punch. Attendance 9901 lost its punch-out correction
     * to exactly that: a genuine device punch whose only surviving log row was
     * marked `duplicate`, leaving the day reading 9.5 hours instead of 7.5.
     *
     * `failed`, `unknown_user` and `wrong_device` are included for the same
     * reason. Each describes what happened to that capture attempt; if the
     * timestamp is nonetheless sitting in `attendances`, the punch reached
     * attendance by some route and the value is the device's.
     *
     * ── The two exclusions, and why they are different ─────────────────────
     *
     *  - `corrected_punch_time IS NOT NULL` — the forward fix already handled
     *    this punch. `attendances` holds the CORRECTED moment and `punch_time` is
     *    only the raw string it arrived as, so an equality match here would be a
     *    coincidence against a value that is already right.
     *  - `punch_status = 'downloaded'` — staged and never converted. Its time
     *    should not be in `attendances` at all. If it is, something imported it
     *    by a path this command does not model, and correcting it on that
     *    assumption would be guessing. Those are counted and REPORTED instead;
     *    see reportStagedOnly(). Note this excludes the log ROW, not the punch:
     *    a punch that has both a `downloaded` row and a `duplicate` one still
     *    matches through the latter and is corrected normally.
     *
     * The `limit(1)` matters: a scalar subquery must return at most one row, and
     * a device can legitimately hold several log rows for the same instant.
     *
     * `check_type` is deliberately NOT compared against which column this is.
     * The ingest path can record a device's check-OUT as the day's check-in when
     * it is the first punch of the day (AttendancePunchService's orphaned-punch
     * recovery), so a log row typed `out` legitimately sits in `punchin`.
     * Requiring the types to agree would leave exactly those recovered rows
     * uncorrected, which is a worse failure than matching on the timestamp alone
     * — and the timestamp is what identifies the punch this command is fixing.
     */
    private function reportedByDevice(string $column)
    {
        return $this->deviceLogsFor($column)
            ->where('biometric_att_logs.punch_status', '!=', self::STATUS_DOWNLOADED);
    }

    /**
     * The staged-only case: this punch matches a `downloaded` log and nothing
     * else. Reported, never corrected.
     */
    private function stagedByDevice(string $column)
    {
        return $this->deviceLogsFor($column)
            ->where('biometric_att_logs.punch_status', self::STATUS_DOWNLOADED);
    }

    /**
     * Raw device logs whose untouched `punch_time` equals `attendances.$column`.
     */
    private function deviceLogsFor(string $column)
    {
        return DB::table('biometric_att_logs')
            ->select(DB::raw(1))
            ->whereColumn('biometric_att_logs.user_id', 'attendances.user_id')
            ->where('biometric_att_logs.biometric_device_id', $this->device->id)
            ->whereNull('biometric_att_logs.corrected_punch_time')
            ->whereColumn('biometric_att_logs.punch_time', 'attendances.'.$column)
            ->limit(1);
    }

    /**
     * "Has THIS punch already been shifted by this command?"
     *
     * The ledger is keyed on (attendance_id, punch_column) and holds one row per
     * corrected punch, so the question is asked and answered per column. A row
     * whose punch-in was corrected in an earlier run answers yes for `punchin`
     * and no for `punchout`, which is exactly what attendance 9901 needed.
     *
     * Deliberately not scoped to this device or this run: a punch that has been
     * shifted once must never be shifted again, whoever asks and with whatever
     * `--seconds`.
     */
    private function alreadyCorrected(string $column)
    {
        return DB::table(self::LEDGER)
            ->select(DB::raw(1))
            ->whereColumn(self::LEDGER.'.attendance_id', 'attendances.id')
            ->where(self::LEDGER.'.punch_column', $column)
            ->limit(1);
    }

    /**
     * The row as `attendances` holds it, without this command's query aliases.
     *
     * What gets archived, so the payload restores the row it came from rather
     * than the shape of the query that found it.
     *
     * @return array<string, mixed>
     */
    private function originalRow(object $row): array
    {
        $columns = (array) $row;

        unset(
            $columns[self::MATCH_IN],
            $columns[self::MATCH_OUT],
            $columns[self::GUARD_IN],
            $columns[self::GUARD_OUT],
        );

        return $columns;
    }

    /**
     * Walk candidates in bounded, ordered chunks.
     *
     * Keyset on `id`, not OFFSET: during --apply a corrected row acquires a
     * ledger entry and drops out of the candidate set, which would make every
     * subsequent OFFSET page skip rows. `id > $lastId` is immune to the set
     * shrinking underneath it.
     *
     * @param  callable(Collection<int, object>): void  $handler
     */
    private function eachChunk(callable $handler): void
    {
        $lastId = 0;

        while (true) {
            $rows = $this->candidates()
                ->where('attendances.id', '>', $lastId)
                ->orderBy('attendances.id')
                ->limit($this->chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $lastId = (int) $rows->last()->id;

            $handler($rows);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Pre-flight scan
    // ──────────────────────────────────────────────────────────────

    /**
     * Read every candidate without writing anything.
     *
     * Deliberately a separate full pass, even in --apply mode. A date-boundary
     * crossing discovered halfway through would otherwise leave the run
     * half-applied; scanning first means the abort happens with zero rows
     * mutated. The pass holds only counters and a capped sample, so its memory
     * cost does not grow with the number of affected rows.
     *
     * @return array<string, mixed>
     */
    private function scan(): array
    {
        $scan = [
            'rows' => 0,
            'punches' => 0,
            'with_punchin' => 0,
            'with_punchout' => 0,
            'mixed_source' => 0,
            'resumed' => 0,
            'already_complete' => 0,
            'crossing' => [],
            'crossing_count' => 0,
            'samples' => [],
            'min_date' => null,
            'max_date' => null,
            'users' => [],
            'policy_not_accepted' => 0,
            'stale_candidates' => 0,
            'needs_approval' => 0,
            'policy_reasons' => [],
            'symbols' => [],
        ];

        $sampleLimit = max(0, (int) $this->option('samples'));

        $this->eachChunk(function (Collection $rows) use (&$scan, $sampleLimit) {
            foreach ($rows as $row) {
                $shift = $this->shiftFor($row);

                if ($shift['shifts'] === []) {
                    // Nothing left to move on this row: every punch this device
                    // reported is already claimed. Counted separately from
                    // `rows`, because the run will not touch it — and counted at
                    // all because it is the one place the pre-filter's effect is
                    // observable. A healthy re-run reports zero here; a non-zero
                    // count means rows reached the walk that the query should
                    // have excluded.
                    $scan['already_complete']++;

                    continue;
                }

                $scan['rows']++;
                $scan['punches'] += count($shift['shifts']);
                $scan['with_punchin'] += $shift['punchin_before'] !== null ? 1 : 0;
                $scan['with_punchout'] += $shift['punchout_before'] !== null ? 1 : 0;
                $scan['mixed_source'] += $shift['mixed_source'] ? 1 : 0;
                $scan['resumed'] += $shift['resumed'] ? 1 : 0;

                $date = (string) $row->date;
                $date = $date !== '' ? substr($date, 0, 10) : null;

                if ($date !== null) {
                    $scan['min_date'] = $scan['min_date'] === null ? $date : min($scan['min_date'], $date);
                    $scan['max_date'] = $scan['max_date'] === null ? $date : max($scan['max_date'], $date);
                }

                $scan['users'][(string) $row->user_id] = true;

                if ($shift['crosses_date']) {
                    $scan['crossing_count']++;

                    if (count($scan['crossing']) < self::MAX_REPORTED_CROSSINGS) {
                        $scan['crossing'][] = $shift;
                    }
                }

                if (count($scan['samples']) < $sampleLimit) {
                    $scan['samples'][] = $shift;
                }

                // Derived state that was decided from the WRONG times. Counted,
                // never touched — see reportDerivedState().
                $policyStatus = (string) ($row->policy_status ?? '');
                $punchinMoved = $shift['punchin_after'] !== null;

                if ($policyStatus !== '' && $policyStatus !== 'accepted') {
                    $scan['policy_not_accepted']++;

                    // Only a row whose PUNCH-IN moved can have a stale verdict:
                    // PunchPolicyGuard::assess() is called from exactly one place
                    // (AttendancePunchService::punchIn) and reads the punch-in
                    // moment alone. A shifted punch-OUT cannot invalidate it.
                    if ($punchinMoved) {
                        $scan['stale_candidates']++;
                    }
                }

                if (! empty($row->needs_approval)) {
                    $scan['needs_approval']++;
                }

                $reason = $row->policy_exception_reason ?? null;

                if ($reason !== null && $reason !== '') {
                    $scan['policy_reasons'][(string) $reason] = ($scan['policy_reasons'][(string) $reason] ?? 0) + 1;
                }

                $symbol = $row->symbol ?? null;

                if ($symbol !== null && $symbol !== '') {
                    $scan['symbols'][(string) $symbol] = ($scan['symbols'][(string) $symbol] ?? 0) + 1;
                }
            }
        });

        $scan['user_count'] = count($scan['users']);
        unset($scan['users']);

        return $scan;
    }

    /**
     * What this row becomes.
     *
     * The shift is computed in PHP and written back as a plain `Y-m-d H:i:s`
     * string, so no driver-specific date arithmetic is ever emitted. A null punch
     * stays null: an open row (punched in, never out) is corrected on the half it
     * has.
     *
     * @return array<string, mixed>
     */
    private function shiftFor(object $row): array
    {
        $match = [
            'punchin' => ! empty($row->{self::MATCH_IN}),
            'punchout' => ! empty($row->{self::MATCH_OUT}),
        ];

        $guarded = [
            'punchin' => ! empty($row->{self::GUARD_IN}),
            'punchout' => ! empty($row->{self::GUARD_OUT}),
        ];

        $shifts = [];

        foreach (self::PUNCH_COLUMNS as $column) {
            // Three independent reasons to leave a punch alone, and each one is
            // sufficient on its own:
            //   · this device never reported it (web, admin, another terminal);
            //   · it has already been corrected — the ledger says so, per column;
            //   · it is absent or unparseable.
            // All three land in the same place: no entry in $shifts, so nothing
            // is written back and no ledger row claims it.
            if (! $match[$column] || $guarded[$column]) {
                continue;
            }

            $moved = $this->shiftTimestamp($row->{$column} ?? null);

            if ($moved['after'] === null) {
                continue;
            }

            $shifts[$column] = $moved;
        }

        $in = $shifts['punchin'] ?? $this->noShift();
        $out = $shifts['punchout'] ?? $this->noShift();

        return [
            'id' => (int) $row->id,
            'user_id' => (string) $row->user_id,
            'date' => substr((string) $row->date, 0, 10),
            // Keyed by column, and the single source of truth for what this run
            // writes: both the UPDATE and the ledger rows are built from it, so
            // they cannot disagree about which punches moved.
            'shifts' => $shifts,
            'punchin_before' => $in['before'],
            'punchin_after' => $in['after'],
            'punchout_before' => $out['before'],
            'punchout_after' => $out['after'],
            // True when the row has BOTH punches but the device reported only
            // one of them — the other belongs to the web, an admin, or another
            // terminal, and must not move. An open row (punched in, never out)
            // is not mixed: it has one punch, not two sources.
            //
            // A punch this command ALREADY corrected also fails the match test,
            // because its log still holds the pre-correction timestamp and no
            // longer equals the stored value. That is a resumed row, not a mixed
            // one, and conflating the two would tell an operator that attendance
            // 9901 has a web punch-in when it has a corrected one. So a column
            // that is merely claimed does not make the row mixed.
            'mixed_source' => ($row->punchin ?? null) !== null
                && ($row->punchout ?? null) !== null
                && $match['punchin'] !== $match['punchout']
                && ! $guarded[$match['punchin'] ? 'punchout' : 'punchin'],
            // A row whose other punch this command corrected on an earlier run.
            // Not a problem — it is the case attendance 9901 needed — but worth
            // showing, because the row will look half-corrected until this runs.
            'resumed' => $shifts !== [] && ($guarded['punchin'] || $guarded['punchout']),
            // A shifted punch that lands on a different calendar day than it
            // started on. `attendances.date` would then disagree with its own
            // punches, and the row may collide with the adjacent day's row.
            'crosses_date' => $in['crosses'] || $out['crosses'],
            'row' => $row,
        ];
    }

    /**
     * A punch this run does not touch: absent, unparseable, or not this
     * device's.
     *
     * @return array{before: string|null, after: string|null, crosses: bool}
     */
    private function noShift(): array
    {
        return ['before' => null, 'after' => null, 'crosses' => false];
    }

    /**
     * @return array{before: string|null, after: string|null, crosses: bool}
     */
    private function shiftTimestamp($value): array
    {
        if ($value === null || $value === '') {
            return $this->noShift();
        }

        try {
            $moment = Carbon::parse((string) $value);
        } catch (\Throwable) {
            // Unparseable stored punch. Treated as "no punch on this side" rather
            // than guessed at; the row's other punch is still corrected and the
            // archive keeps the original bytes either way.
            Log::warning('Historical clock correction: unparseable punch value skipped', [
                'device_id' => $this->device->id,
                'value' => (string) $value,
            ]);

            return $this->noShift();
        }

        $shifted = $moment->copy()->addSeconds($this->appliedSeconds);

        return [
            'before' => $moment->format('Y-m-d H:i:s'),
            'after' => $shifted->format('Y-m-d H:i:s'),
            'crosses' => $moment->format('Y-m-d') !== $shifted->format('Y-m-d'),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Apply
    // ──────────────────────────────────────────────────────────────

    private function apply(array $scan): int
    {
        $runId = (string) Str::uuid();
        $archived = 0;
        $applied = 0;

        $this->newLine();
        $this->info("APPLYING — run {$runId}");

        try {
            $this->eachChunk(function (Collection $rows) use ($runId, &$archived, &$applied) {
                $plan = [];

                foreach ($rows as $row) {
                    $shift = $this->shiftFor($row);

                    if ($shift['crosses_date']) {
                        // The scan said this could not happen. If it does, the data
                        // moved under us; throwing rolls this batch back and leaves
                        // everything already committed intact and archived.
                        throw new \RuntimeException(
                            'Attendance '.$shift['id'].' would cross a date boundary ('.
                            $shift['date'].'); aborting mid-run. Rows already corrected are archived in '.
                            self::LEDGER.' under run '.$runId.'.'
                        );
                    }

                    if ($shift['shifts'] === []) {
                        // Selected by the query but with nothing left to move —
                        // an unparseable punch, or the other column raced ahead.
                        // Archiving it would claim a correction that never
                        // happened and burn the guard for that punch.
                        continue;
                    }

                    $plan[] = $shift;
                }

                if ($plan === []) {
                    return;
                }

                // 1. Archive, COMMITTED, before anything is touched. A crash after
                //    this and before the update leaves a guarded-but-unshifted row
                //    (applied_at IS NULL) — visible under-correction, never a
                //    silent second shift.
                $archived += $this->archive($plan, $runId);

                // 2. Mutate. One transaction per batch, and `applied_at` is stamped
                //    inside it, so a row is never recorded as corrected unless its
                //    punch times actually changed in the same commit.
                $applied += $this->shiftRows($plan);
            });
        } catch (QueryException $e) {
            // Overwhelmingly the UNIQUE on (attendance_id, punch_column):
            // another run corrected this punch first. The batch insert failed as
            // a unit, so nothing in it was updated.
            $this->newLine();
            $this->error('Aborted: the archive rejected a punch that is already corrected. Another run has covered it. Nothing in the failing batch was changed.');
            $this->line('  '.Str::limit($e->getMessage(), 300));

            $this->summariseRun($runId, $archived, $applied);

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->newLine();
            $this->error('Aborted: '.$e->getMessage());

            $this->summariseRun($runId, $archived, $applied);

            return self::FAILURE;
        }

        $this->summariseRun($runId, $archived, $applied);
        $this->reportDerivedState($scan);

        Log::info('Historical device clock correction applied', [
            'run_id' => $runId,
            'device_id' => $this->device->id,
            'serial' => $this->device->serial_number,
            'applied_seconds' => $this->appliedSeconds,
            'from' => $this->from,
            'until' => $this->until,
            'rows_archived' => $archived,
            'rows_corrected' => $applied,
        ]);

        return self::SUCCESS;
    }

    /**
     * Commit one archive row per PUNCH about to be shifted.
     *
     * One row per corrected punch, not per attendance row. That is the whole
     * shape of the idempotency guard: `(attendance_id, punch_column)` is UNIQUE,
     * so a punch that has an archive row can never acquire a second one, and a
     * punch that has none is free to be corrected regardless of what happened to
     * the other punch on the same day. Attendance 9901 could not be finished
     * under the old per-row key; under this one its punch-out inserts cleanly
     * while its punch-in stays permanently claimed.
     *
     * `payload` is the complete `attendances` row as the database returned it,
     * id included — not a chosen subset, so a restore does not depend on this
     * command having anticipated which columns would matter later. Both siblings
     * of a row corrected on both punches carry it, so either one restores the
     * original in full.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    private function archive(array $plan, string $runId): int
    {
        $now = Carbon::now();
        $records = [];

        foreach ($plan as $shift) {
            $payload = json_encode($this->originalRow($shift['row']));

            foreach ($shift['shifts'] as $column => $moved) {
                $records[] = [
                    'attendance_id' => $shift['id'],
                    // The guard. Never inferred from the before/after columns —
                    // it is stated, and the database enforces one per pair.
                    'punch_column' => $column,
                    'biometric_device_id' => $this->device->id,
                    'device_serial' => $this->device->serial_number,
                    'user_id' => $shift['user_id'],
                    'attendance_date' => $shift['date'],
                    'applied_seconds' => $this->appliedSeconds,
                    'punchin_before' => $column === 'punchin' ? $moved['before'] : null,
                    'punchin_after' => $column === 'punchin' ? $moved['after'] : null,
                    'punchout_before' => $column === 'punchout' ? $moved['before'] : null,
                    'punchout_after' => $column === 'punchout' ? $moved['after'] : null,
                    'payload' => $payload,
                    'run_id' => $runId,
                    'archived_at' => $now,
                    'applied_at' => null,
                ];
            }
        }

        if ($records === []) {
            return 0;
        }

        // Its own transaction, and it commits here. Deliberately NOT shared with
        // the update below: the archive existing without the shift is safe, the
        // shift existing without the archive is the unrecoverable case.
        DB::transaction(function () use ($records) {
            DB::table(self::LEDGER)->insert($records);
        });

        return count($records);
    }

    /**
     * Write the corrected punches, and confirm them in the ledger.
     *
     * Returns the number of PUNCHES moved, so it is directly comparable with the
     * archive count — the two must agree, and summariseRun() says so when they
     * do not.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    private function shiftRows(array $plan): int
    {
        $now = Carbon::now();

        return DB::transaction(function () use ($plan, $now) {
            $count = 0;

            foreach ($plan as $shift) {
                if ($shift['shifts'] === []) {
                    continue;
                }

                $update = ['updated_at' => $now];

                foreach ($shift['shifts'] as $column => $moved) {
                    $update[$column] = $moved['after'];
                }

                DB::table('attendances')->where('id', $shift['id'])->update($update);

                // Confirm exactly the columns just written. A ledger row for a
                // punch this batch did not touch keeps applied_at NULL and is
                // reported rather than silently adopted.
                DB::table(self::LEDGER)
                    ->where('attendance_id', $shift['id'])
                    ->whereIn('punch_column', array_keys($shift['shifts']))
                    ->whereNull('applied_at')
                    ->update(['applied_at' => $now]);

                $count += count($shift['shifts']);
            }

            return $count;
        });
    }

    // ──────────────────────────────────────────────────────────────
    //  Output
    // ──────────────────────────────────────────────────────────────

    private function printHeader(): void
    {
        $mode = $this->option('apply') ? 'APPLY' : 'DRY RUN (default)';

        $this->newLine();
        $this->line('<comment>Historical device clock correction</comment>');
        $this->table([], [
            ['Mode', $mode],
            ['Device', $this->device->serial_number.' (id '.$this->device->id.', '.($this->device->name ?? 'unnamed').')'],
            ['Correction applied to punches', $this->clock->humanise($this->appliedSeconds).' ('.$this->appliedSeconds.'s)'],
            ['Date range', ($this->from ?? 'beginning').' .. '.($this->until ?? 'end')],
            ['Chunk size', (string) $this->chunkSize],
        ]);

        $measured = $this->device->getAttribute('clock_offset_seconds');

        if ($measured !== null) {
            $requested = -$this->appliedSeconds;
            $delta = $requested - (int) $measured;

            $this->line(sprintf(
                '  Live estimator currently measures %+ds for this device; you are correcting history by %+ds (difference %+ds).',
                (int) $measured,
                $requested,
                $delta
            ));
            $this->line('  The estimator is the true offset MINUS transport latency, so a small positive difference is expected and correct.');
        }
    }

    /**
     * Punches whose ONLY matching log row is `downloaded`.
     *
     * `downloaded` means staged for import and never converted into attendance,
     * so its timestamp should not be sitting in `attendances` at all. When one
     * is, something put it there by a route this command does not model — a
     * partial import, a hand-edit that happened to copy the staged value, a
     * status left stale by an interrupted run. Correcting it would be acting on
     * an assumption rather than on evidence, so it is named and left alone.
     *
     * Deliberately a separate query rather than part of the candidate walk: these
     * rows are an observation, not work, and folding them into the selection
     * would make "rows selected" mean two different things.
     */
    private function reportStagedOnly(): void
    {
        $query = DB::table('attendances')
            ->where(function ($outer) {
                foreach (self::PUNCH_COLUMNS as $column) {
                    $outer->orWhere(function ($clause) use ($column) {
                        $clause->whereExists($this->stagedByDevice($column))
                            ->whereNotExists($this->reportedByDevice($column));
                    });
                }
            });

        if ($this->from !== null) {
            $query->where('attendances.date', '>=', $this->from);
        }

        if ($this->until !== null) {
            $query->where('attendances.date', '<=', $this->until);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            return;
        }

        $sample = $query->orderBy('attendances.id')
            ->limit(self::MAX_REPORTED_CROSSINGS)
            ->get(['id', 'user_id', 'date', 'punchin', 'punchout']);

        $this->newLine();
        $this->warn("  {$count} attendance row(s) hold a punch that matches only a 'downloaded' log for this device.");
        $this->line('  NOT corrected, and not counted above. A downloaded row was staged and never converted, so its');
        $this->line('  timestamp should not be in `attendances`; that it is means something imported it by a path this');
        $this->line('  command does not model. Correcting it would be a guess. Review these by hand:');

        $this->table(
            ['attendance', 'user', 'date', 'punch-in', 'punch-out'],
            $sample->map(fn ($row) => [
                $row->id,
                $row->user_id,
                substr((string) $row->date, 0, 10),
                $row->punchin ?? '—',
                $row->punchout ?? '—',
            ])->all()
        );

        if ($count > $sample->count()) {
            $this->line('  … and '.($count - $sample->count()).' more.');
        }
    }

    private function printScan(array $scan): void
    {
        $this->newLine();
        $this->line('<comment>Rows this run would change</comment>');

        $this->table([], [
            ['Attendance rows selected', (string) $scan['rows']],
            ['Punch values that would move', (string) $scan['punches']],
            ['Distinct users', (string) $scan['user_count']],
            ['Punch-in values that would move', (string) $scan['with_punchin']],
            ['Punch-out values that would move', (string) $scan['with_punchout']],
            ['Mixed rows (only one punch is this device\'s)', (string) $scan['mixed_source']],
            ['Rows resuming an earlier run (other punch already done)', (string) $scan['resumed']],
            ['Rows reached but already fully corrected (expect 0)', (string) $scan['already_complete']],
            ['Date span', $scan['rows'] === 0 ? '—' : $scan['min_date'].' .. '.$scan['max_date']],
            ['Rows crossing a date boundary', (string) $scan['crossing_count']],
        ]);

        if ($scan['mixed_source'] > 0) {
            $this->line('  On a mixed row only the punch this device reported is moved. The other side came from the web,');
            $this->line('  an admin, or another terminal, is already correct, and is left exactly as it is — the sample');
            $this->line('  below shows it with no "→" value.');
        }

        if ($scan['resumed'] > 0) {
            $this->line('  A "resumed" row already has one punch in the ledger from an earlier run, and only its OTHER');
            $this->line('  punch is being corrected now. That punch is claimed per column, so the finished one cannot');
            $this->line('  move again. This is the attendance-9901 case: punch-in corrected in the first pass, punch-out');
            $this->line('  left behind because its only log row was marked "duplicate".');
        }

        $this->reportStagedOnly();

        $alreadyCorrected = DB::table(self::LEDGER)
            ->where('biometric_device_id', $this->device->id)
            ->count();

        $unconfirmed = DB::table(self::LEDGER)
            ->where('biometric_device_id', $this->device->id)
            ->whereNull('applied_at')
            ->count();

        if ($alreadyCorrected > 0) {
            $this->line("  {$alreadyCorrected} punch(es) for this device are already in ".self::LEDGER.' and are excluded from the selection above.');
        }

        if ($unconfirmed > 0) {
            $this->newLine();
            $this->warn("  {$unconfirmed} archived row(s) for this device have applied_at = NULL: archived but never confirmed shifted.");
            $this->line('  That is what a crash between the archive commit and the update looks like. They are NOT re-selected, because');
            $this->line('  this command cannot tell an un-shifted row from a shifted one by looking at it. Compare each ledger row\'s');
            $this->line('  punchin_before/punchin_after against the live attendance row and repair by hand.');
        }

        if ($scan['samples'] !== []) {
            $this->newLine();
            $this->line('<comment>Sample of before → after</comment>');

            $this->table(
                ['attendance', 'user', 'date', 'punch-in', '→', 'punch-out', '→'],
                array_map(fn (array $s) => [
                    $s['id'],
                    $s['user_id'],
                    $s['date'],
                    $s['punchin_before'] ?? '—',
                    $s['punchin_after'] ?? '—',
                    $s['punchout_before'] ?? '—',
                    $s['punchout_after'] ?? '—',
                ], $scan['samples'])
            );

            if ($scan['rows'] > count($scan['samples'])) {
                $this->line('  … and '.($scan['rows'] - count($scan['samples'])).' more. Raise --samples to see further.');
            }
        }

        if (! $this->option('apply')) {
            $this->reportDerivedState($scan);
        }
    }

    /**
     * The honest part of the report.
     *
     * Shifting a punch time does not shift the decisions that were made from it.
     * This block says which ones are now inconsistent and which are not, with
     * counts from the rows this run actually covers.
     */
    private function reportDerivedState(array $scan): void
    {
        $this->newLine();
        $this->line('<comment>Derived state — NOT recomputed by this command</comment>');

        $this->line('  late / early / overtime minutes and day status: SAFE. AttendanceStatusService computes them at read');
        $this->line('    time from punchin/punchout and stores nothing, so correcting the punches corrects the reports.');
        $this->newLine();
        $this->line('  attendances.symbol: NOT derived from punch times. It is a leave/manual-presence marker written by');
        $this->line('    the admin screens and the leave flow; the biometric path never sets it. Unaffected by this shift.');

        if ($scan['symbols'] !== []) {
            arsort($scan['symbols']);
            $pairs = [];

            foreach ($scan['symbols'] as $symbol => $count) {
                $pairs[] = $symbol.' × '.$count;
            }

            $this->line('    Symbols present on the selected rows (for information only): '.implode(', ', $pairs));
        }

        $this->newLine();
        $this->line('  policy_status / needs_approval / policy_exception_reason: <fg=red>may be STALE after this correction.</>');
        $this->line('    PunchPolicyGuard::assess() ran at ingest against the device\'s WRONG (too-late) time, and');
        $this->line('    AttendancePunchService persisted the verdict on the row. Correcting the punch does not re-run it.');
        $this->newLine();
        $this->line('    The exposure is narrower than "everything is now wrong", and the conditions are exact:');
        $this->line('      · assess() is called from ONE place, punchIn(), and reads the punch-in moment only. A row whose');
        $this->line('        punch-OUT moved and whose punch-in did not cannot have a stale verdict.');
        $this->line('      · punch_strictness = \'warn\' (the default, and the value when no attendance_policies row matches)');
        $this->line('        persists nothing at all — the out-of-window case returns a transient UI warning. NOT stale.');
        $this->line('      · punch_strictness = \'flag\' marks every punch provisional regardless of its time. Time-independent,');
        $this->line('        so shifting the punch cannot change it. NOT stale. Reason string: "flagged by policy".');
        $this->line('      · punch_strictness = \'restrict\' is the only time-dependent verdict: provisional iff the punch-in');
        $this->line('        falls outside [shift.start - outside_window_minutes, shift.end + outside_window_minutes].');
        $this->line('        Reason string: "outside permitted window". THIS is what goes stale, in both directions —');
        $this->line('        a punch flagged only because 09:00 was stored as 11:00, and a genuinely out-of-window punch');
        $this->line('        that the +2 h skew happened to push INSIDE the window and so was never flagged.');

        $this->table([], [
            ['Selected rows with policy_status != accepted', (string) $scan['policy_not_accepted']],
            ['…of those, rows whose punch-in actually moved', (string) $scan['stale_candidates']],
            ['Selected rows with needs_approval = true', (string) $scan['needs_approval']],
        ]);

        if ($scan['policy_reasons'] !== []) {
            arsort($scan['policy_reasons']);

            $this->line('    Stored exception reasons on the selected rows:');

            foreach ($scan['policy_reasons'] as $reason => $count) {
                $timeDependent = str_contains(strtolower((string) $reason), 'window');

                $this->line('      '.$count.' × "'.$reason.'"'.($timeDependent
                    ? '  <fg=red>← time-dependent, review these</>'
                    : '  (time-independent, unaffected)'));
            }
        } else {
            $this->line('    No selected row carries a stored policy exception, so nothing in this group is stale.');
        }

        $this->newLine();
        $this->line('    The counts above are the upper bound on the FALSE-POSITIVE direction (flagged, should not be).');
        $this->line('    The false-negative direction — accepted rows that should have been flagged — cannot be counted');
        $this->line('    without replaying the policy, because the evidence for it is precisely what was never written.');
        $this->newLine();
        $this->line('    Nothing here is recomputed. Re-deciding would silently overwrite exceptions an approver may');
        $this->line('    already have actioned, and an honest replay needs the roster, policy and holiday state as they');
        $this->line('    were on each historical date, not today\'s. Repairing them is a separate, reviewed operation:');
        $this->line('    replay PunchPolicyGuard::assess(user_id, corrected punchin) per row, diff against the stored');
        $this->line('    verdict, and have an approver clear the ones that change. '.self::LEDGER.' identifies exactly');
        $this->line('    which rows are in scope, and holds the pre-correction punch times to replay the old verdict from.');
    }

    private function abortOnDateCrossing(array $scan): int
    {
        $this->newLine();
        $this->error('ABORTED: '.$scan['crossing_count'].' row(s) would move a punch onto a different calendar date.');
        $this->line('  Nothing has been written.');
        $this->newLine();
        $this->line('  attendances.date is the business date these rows are filed and reported under, and a punch that');
        $this->line('  lands on the adjacent day can collide with that day\'s existing row for the same user. Resolving');
        $this->line('  that is a materially harder operation than shifting a timestamp, and this command will not attempt');
        $this->line('  it silently. Narrow the range with --from/--until to exclude these rows, or handle them by hand.');
        $this->newLine();

        $this->table(
            ['attendance', 'user', 'date', 'punch-in', '→', 'punch-out', '→'],
            array_map(fn (array $s) => [
                $s['id'],
                $s['user_id'],
                $s['date'],
                $s['punchin_before'] ?? '—',
                $s['punchin_after'] ?? '—',
                $s['punchout_before'] ?? '—',
                $s['punchout_after'] ?? '—',
            ], $scan['crossing'])
        );

        if ($scan['crossing_count'] > count($scan['crossing'])) {
            $this->line('  … and '.($scan['crossing_count'] - count($scan['crossing'])).' more.');
        }

        return self::FAILURE;
    }

    private function summariseRun(string $runId, int $archived, int $applied): void
    {
        $this->newLine();
        $this->table([], [
            ['Run id', $runId],
            ['Punches archived', (string) $archived],
            ['Punches corrected', (string) $applied],
            ['Archive table', self::LEDGER],
        ]);

        if ($archived !== $applied) {
            $this->warn('  '.($archived - $applied).' archived punch(es) were not confirmed corrected (applied_at IS NULL). Review them before re-running.');
        }
    }
}
