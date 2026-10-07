<?php

namespace Tests\Feature\Biometric;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDownloadSession;
use App\Models\User;
use App\Services\Biometric\BiometricProcessingService;
use App\Services\Biometric\DeviceClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-device clock offset: measured from live pushes, applied at ingest.
 *
 * ── The incident these numbers come from ────────────────────────────────────
 *
 * The production MB460 (`AF6P231260266`) has reported timestamps exactly two
 * hours in the future since installation — 827 live pushes over four months,
 * median -2.00 h against the moment our server received them, stable to the
 * second. Earliest observed: punch 15:13:19, received 13:13:21. So the offset
 * used throughout this test is the real one, +7196 s (1 h 59 m 56 s), and the
 * four seconds matter: they are why AttendancePunchService's future-punch guard
 * (MAX_CLOCK_DRIFT_HOURS = 2) never fired and 827 punches were written two hours
 * late. An 09:00 arrival was stored as 11:00 and read as two hours late.
 *
 * ── What is pinned here ─────────────────────────────────────────────────────
 *
 *  - the offset is measured from LIVE pushes and applied, so attendance lands on
 *    the real moment;
 *  - batched/downloaded rows never contribute a sample;
 *  - a device with no measurement is corrected by nothing at all;
 *  - **a device whose clock gets fixed stops being corrected** — the single most
 *    dangerous failure mode here, because a statically-applied offset would
 *    start shifting punches two hours into the PAST the moment the hardware was
 *    repaired;
 *  - the raw device timestamp survives correction and stays recoverable;
 *  - sub-threshold offsets are left alone.
 */
class DeviceClockOffsetTest extends TestCase
{
    use RefreshDatabase;

    /** The live MB460's measured offset, device-minus-server, in seconds. */
    private const REAL_OFFSET = 7196;

    /** PIN with no employee behind it — pushes for it measure without punching. */
    private const UNENROLLED_PIN = '9999';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────

    private function device(array $overrides = []): BiometricDevice
    {
        return BiometricDevice::create(array_merge([
            'name' => 'Gate MB460',
            'serial_number' => 'AF6P231260266-'.uniqid(),
            'protocol' => 'adms',
            'auth_token' => 'token-'.uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    private function zonedUser(string $employeeId, BiometricDevice $device): User
    {
        $type = AttendanceType::where('slug', 'biometric')->firstOrFail();
        $type->biometricDevices()->syncWithoutDetaching([$device->id]);

        return User::factory()->create([
            'employee_id' => $employeeId,
            'attendance_type_id' => $type->id,
        ]);
    }

    private function service(): BiometricProcessingService
    {
        return app(BiometricProcessingService::class);
    }

    private function clock(): DeviceClockService
    {
        return app(DeviceClockService::class);
    }

    /**
     * One ATTLOG line, as a device pushes it: PIN, device timestamp, status.
     */
    private function line(string $pin, string $deviceTime, string $status = '0'): string
    {
        return $pin."\t".$deviceTime."\t".$status;
    }

    /**
     * Push one live ATTLOG body, with the server clock pinned at $serverNow.
     */
    private function livePush(BiometricDevice $device, string $body, Carbon $serverNow): array
    {
        Carbon::setTestNow($serverNow);

        return $this->service()->processAttendanceLogs($body, $device, $device->serial_number);
    }

    /**
     * Drive $count real live pushes whose device timestamps sit $offsetSeconds
     * away from server time, so the estimator learns that offset the same way
     * production does.
     *
     * The pushes carry an UNENROLLED pin: they are measurements, not punches, so
     * nothing here can create an attendance row and confuse a later assertion.
     */
    private function measureFromLivePushes(
        BiometricDevice $device,
        int $offsetSeconds,
        int $count,
        string $from = '2026-06-19 06:00:00'
    ): void {
        $base = Carbon::parse($from);

        for ($i = 0; $i < $count; $i++) {
            $serverMoment = $base->copy()->addMinutes($i);
            $deviceMoment = $serverMoment->copy()->addSeconds($offsetSeconds);

            $this->livePush(
                $device,
                $this->line(self::UNENROLLED_PIN, $deviceMoment->format('Y-m-d H:i:s')),
                $serverMoment
            );
        }

        $device->refresh();
    }

    private function attLogFor(BiometricDevice $device, string $pin): object
    {
        return DB::table('biometric_att_logs')
            ->where('biometric_device_id', $device->id)
            ->where('user_pin', $pin)
            ->orderByDesc('id')
            ->first();
    }

    private function sampleCount(BiometricDevice $device): int
    {
        return DB::table(DeviceClockService::SAMPLES_TABLE)
            ->where('biometric_device_id', $device->id)
            ->count();
    }

    // ── 1. measurement ──────────────────────────────────────────────

    public function test_a_two_hour_offset_is_measured_from_live_pushes(): void
    {
        $device = $this->device();

        $this->assertNull($device->clock_offset_seconds, 'A new device starts unmeasured, not at zero.');

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);

        $this->assertSame(self::REAL_OFFSET, $device->clock_offset_seconds);
        $this->assertSame(6, $device->clock_offset_samples);
        $this->assertNotNull($device->clock_offset_measured_at);

        $snapshot = $this->clock()->snapshot($device);

        $this->assertTrue($snapshot['measured']);
        $this->assertTrue($snapshot['device_is_ahead']);
        $this->assertTrue($snapshot['is_applied']);
        // The correction is the negation of the offset: the device reads 1h59m56s
        // late, so its punches are moved back by exactly that.
        $this->assertSame(-self::REAL_OFFSET, $snapshot['applied_seconds']);
        $this->assertSame(DeviceClockService::REASON_APPLIED, $snapshot['reason']);
        $this->assertSame('+1h 59m 56s', $snapshot['offset_human']);
        $this->assertSame('-1h 59m 56s', $snapshot['applied_human']);
    }

    public function test_one_catch_up_burst_cannot_drag_the_estimate(): void
    {
        // Why the estimator uses a median and not a mean. A device that was
        // offline pushes genuinely old punches through the LIVE path; those are
        // real live pushes, so they are sampled — but they say nothing about its
        // clock. One such sample must not move the answer.
        $device = $this->device();

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);

        Carbon::setTestNow(Carbon::parse('2026-06-19 07:00:00'));
        $this->clock()->recordLiveSample($device, Carbon::parse('2026-06-19 07:00:00')->subHours(11));
        $device->refresh();

        $this->assertSame(self::REAL_OFFSET, $device->clock_offset_seconds);
        $this->assertSame(7, $device->clock_offset_samples);

        // A mean over the same seven samples would have been dragged by well over
        // an hour, which would then be applied to every punch.
        $mean = (int) round(((6 * self::REAL_OFFSET) + (-11 * 3600)) / 7);
        $this->assertNotSame($mean, $device->clock_offset_seconds);
    }

    // ── 2. application ──────────────────────────────────────────────

    public function test_measured_offset_is_applied_so_stored_attendance_matches_real_time(): void
    {
        $device = $this->device();
        $user = $this->zonedUser('42', $device);

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);

        // The employee really arrives at 09:00:04. The device, two hours fast,
        // stamps the punch 11:00:00 and pushes it immediately.
        $realArrival = Carbon::parse('2026-06-19 09:00:04');
        $deviceStamp = '2026-06-19 11:00:00';

        $result = $this->livePush($device, $this->line('42', $deviceStamp), $realArrival);

        $this->assertSame(1, $result['processed']);

        $attendance = Attendance::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(
            '2026-06-19 09:00:04',
            Carbon::parse($attendance->punchin)->format('Y-m-d H:i:s'),
            'The punch must land on the real arrival, not the device\'s +2h stamp.'
        );
        $this->assertNotSame($deviceStamp, Carbon::parse($attendance->punchin)->format('Y-m-d H:i:s'));
    }

    public function test_a_device_with_no_measurement_applies_no_correction(): void
    {
        $device = $this->device();
        $user = $this->zonedUser('43', $device);

        // Never measured: null offset, and null is NOT a measured zero.
        $snapshot = $this->clock()->snapshot($device);
        $this->assertFalse($snapshot['measured']);
        $this->assertNull($snapshot['offset_seconds']);
        $this->assertFalse($snapshot['is_applied']);
        $this->assertSame(DeviceClockService::REASON_NOT_MEASURED, $snapshot['reason']);

        // The first push both takes the very first sample AND is processed; one
        // sample is nowhere near enough to act on, so the punch is stored exactly
        // as the device reported it.
        $this->livePush($device, $this->line('43', '2026-06-19 11:00:00'), Carbon::parse('2026-06-19 09:00:04'));

        $attendance = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-06-19 11:00:00', Carbon::parse($attendance->punchin)->format('Y-m-d H:i:s'));

        $log = $this->attLogFor($device, '43');
        $this->assertNull($log->corrected_punch_time);
        $this->assertNull($log->clock_offset_applied_seconds);

        $device->refresh();
        $this->assertSame(1, $device->clock_offset_samples);
        $this->assertSame(
            DeviceClockService::REASON_INSUFFICIENT_SAMPLES,
            $this->clock()->snapshot($device)['reason']
        );
    }

    public function test_sub_threshold_offsets_are_ignored(): void
    {
        $device = $this->device();
        $user = $this->zonedUser('44', $device);

        // Eight seconds is transport latency and second-boundary rounding, not
        // accuracy. Correcting by it would make every row look adjusted for no
        // gain anybody can act on.
        $this->measureFromLivePushes($device, 8, 6);

        $this->assertSame(8, $device->clock_offset_seconds);

        $snapshot = $this->clock()->snapshot($device);
        $this->assertTrue($snapshot['measured']);
        $this->assertFalse($snapshot['is_applied']);
        $this->assertSame(0, $snapshot['applied_seconds']);
        $this->assertSame(DeviceClockService::REASON_BELOW_THRESHOLD, $snapshot['reason']);

        $this->livePush($device, $this->line('44', '2026-06-19 09:00:08'), Carbon::parse('2026-06-19 09:00:00'));

        $attendance = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-06-19 09:00:08', Carbon::parse($attendance->punchin)->format('Y-m-d H:i:s'));

        $log = $this->attLogFor($device, '44');
        $this->assertNull($log->clock_offset_applied_seconds);
    }

    // ── 3. the anti-double-correction case ──────────────────────────

    public function test_a_device_whose_clock_is_fixed_stops_being_corrected(): void
    {
        $device = $this->device();
        $user = $this->zonedUser('45', $device);

        // A full window of evidence that the device is two hours fast.
        $this->measureFromLivePushes($device, self::REAL_OFFSET, DeviceClockService::SAMPLE_WINDOW);
        $this->assertTrue($this->clock()->snapshot($device)['is_applied']);

        // Somebody repairs the clock. New live pushes now agree with the server.
        $this->measureFromLivePushes($device, 0, 13, '2026-06-20 06:00:00');

        $snapshot = $this->clock()->snapshot($device);

        $this->assertSame(0, $snapshot['offset_seconds'], 'The rolling median follows the repaired clock.');
        $this->assertFalse($snapshot['is_applied']);
        $this->assertSame(0, $snapshot['applied_seconds']);
        $this->assertSame(DeviceClockService::REASON_BELOW_THRESHOLD, $snapshot['reason']);

        // The punch that proves it. The device is now honest: it stamps 09:00:00
        // and the server receives it at 09:00:00. A statically-applied offset
        // would store this as 07:00:04 — two hours WRONG, in the opposite
        // direction, silently, from the moment the hardware was fixed.
        $this->livePush($device, $this->line('45', '2026-06-20 09:00:00'), Carbon::parse('2026-06-20 09:00:00'));

        $attendance = Attendance::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('2026-06-20 09:00:00', Carbon::parse($attendance->punchin)->format('Y-m-d H:i:s'));
        $this->assertNotSame('2026-06-20 07:00:04', Carbon::parse($attendance->punchin)->format('Y-m-d H:i:s'));

        $log = $this->attLogFor($device, '45');
        $this->assertNull($log->corrected_punch_time);
        $this->assertNull($log->clock_offset_applied_seconds);
    }

    public function test_correcting_the_same_raw_timestamp_twice_yields_the_same_answer(): void
    {
        // Correction is a pure function of (raw device value, current estimate).
        // That is what makes a replayed row re-correct rather than compound: the
        // raw timestamp is preserved and is always the input.
        $device = $this->device();
        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);

        $first = $this->clock()->correct($device, '2026-06-19 11:00:00');
        $second = $this->clock()->correct($device, $first['raw_punch_time']);

        $this->assertSame('2026-06-19 09:00:04', $first['punch_time']);
        $this->assertSame($first['punch_time'], $second['punch_time']);
        $this->assertSame(-self::REAL_OFFSET, $second['applied_seconds']);

        // And feeding an ALREADY corrected value back in would visibly shift it
        // again — which is precisely why nothing in the ingest path ever does.
        $doubled = $this->clock()->correct($device, $first['punch_time']);
        $this->assertSame('2026-06-19 07:00:08', $doubled['punch_time']);
    }

    // ── 4. batched / downloaded logs ────────────────────────────────

    public function test_downloaded_rows_never_pollute_the_measurement(): void
    {
        $device = $this->device();
        $this->zonedUser('46', $device);

        BiometricDownloadSession::create([
            'biometric_device_id' => $device->id,
            'trigger_type' => 'manual',
            'status' => 'in_progress',
            'started_at' => Carbon::parse('2026-06-19 09:00:00'),
        ]);

        // A history replay: punches from weeks ago, arriving now. Subtracting
        // receive time here would read as an offset of days.
        $body = implode("\n", [
            $this->line('46', '2026-05-17 15:13:19'),
            $this->line('46', '2026-05-18 15:13:19'),
            $this->line('46', '2026-05-19 15:13:19'),
            $this->line('46', '2026-05-20 15:13:19'),
            $this->line('46', '2026-05-21 15:13:19'),
            $this->line('46', '2026-05-22 15:13:19'),
        ]);

        $result = $this->livePush($device, $body, Carbon::parse('2026-06-19 09:00:00'));

        // The rows really were captured — otherwise this test would pass by
        // doing nothing at all.
        $this->assertSame(6, $result['processed']);
        $this->assertSame(6, DB::table('biometric_att_logs')
            ->where('biometric_device_id', $device->id)
            ->where('punch_status', 'downloaded')
            ->count());

        $device->refresh();
        $this->assertSame(0, $this->sampleCount($device));
        $this->assertNull($device->clock_offset_seconds);
        $this->assertNull($device->clock_offset_measured_at);
    }

    public function test_a_burst_of_live_lines_contributes_exactly_one_sample(): void
    {
        // A backlog pushed through the live path is many lines in one body. If
        // every line were sampled, one burst could fill the whole window with
        // punches that are genuinely old.
        $device = $this->device();

        $body = implode("\n", [
            $this->line(self::UNENROLLED_PIN, '2026-06-19 05:00:00'),
            $this->line(self::UNENROLLED_PIN, '2026-06-19 06:00:00'),
            $this->line(self::UNENROLLED_PIN, '2026-06-19 08:59:56'),
        ]);

        $this->livePush($device, $body, Carbon::parse('2026-06-19 07:00:00'));

        $device->refresh();

        $this->assertSame(1, $this->sampleCount($device));
        // Taken from the NEWEST line in the body — the one most likely to be
        // "just now" — so a burst reports the device's clock, not its backlog.
        $this->assertSame(7196, $device->clock_offset_seconds);
    }

    public function test_downloaded_logs_are_corrected_by_the_devices_stored_offset(): void
    {
        // Downloaded logs carry the same skew but cannot be measured from receive
        // time, so they consume the estimate the live path measured.
        $device = $this->device();
        $user = $this->zonedUser('47', $device);

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);

        $session = BiometricDownloadSession::create([
            'biometric_device_id' => $device->id,
            'trigger_type' => 'manual',
            'status' => 'in_progress',
            'started_at' => Carbon::parse('2026-06-19 10:00:00'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-19 10:00:00'));
        $this->service()->processAttendanceLogs(
            $this->line('47', '2026-06-18 11:00:00'),
            $device,
            $device->serial_number
        );

        $samplesAfterCapture = $this->sampleCount($device);

        Carbon::setTestNow(Carbon::parse('2026-06-19 10:05:00'));
        $session->update(['status' => 'completed', 'completed_at' => Carbon::parse('2026-06-19 10:04:00')]);

        $totals = $this->service()->importDownloadedLogs($session->fresh());

        $this->assertSame(1, $totals['imported']);

        $attendance = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-06-18 09:00:04', Carbon::parse($attendance->punchin)->format('Y-m-d H:i:s'));

        $log = $this->attLogFor($device, '47');
        $this->assertSame('processed', $log->punch_status);
        $this->assertSame(-self::REAL_OFFSET, (int) $log->clock_offset_applied_seconds);

        // Importing a batch measures nothing — it only consumes.
        $this->assertSame($samplesAfterCapture, $this->sampleCount($device));
    }

    // ── 5. the raw device timestamp survives ────────────────────────

    public function test_the_raw_device_timestamp_remains_recoverable(): void
    {
        $device = $this->device();
        $this->zonedUser('48', $device);

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);

        $this->livePush($device, $this->line('48', '2026-06-19 11:00:00'), Carbon::parse('2026-06-19 09:00:04'));

        $log = $this->attLogFor($device, '48');

        // punch_time is the device's own account, untouched. It is also part of
        // the punch natural key, so it must not move when an estimate moves.
        $this->assertStringStartsWith('2026-06-19 11:00:00', (string) $log->punch_time);
        $this->assertStringStartsWith('2026-06-19 11:00:00', (string) $log->occurred_at);
        $this->assertStringContainsString('2026-06-19 11:00:00', (string) $log->raw_data);

        // …beside what we did with it.
        $this->assertStringStartsWith('2026-06-19 09:00:04', (string) $log->corrected_punch_time);
        $this->assertSame(-self::REAL_OFFSET, (int) $log->clock_offset_applied_seconds);
        $this->assertSame('processed', $log->punch_status);
    }

    public function test_a_repushed_punch_still_dedupes_after_the_offset_changes(): void
    {
        // The corollary of keeping raw time in punch_time: the punch natural key
        // (device, pin, punch_time, check_type) does not move when the estimate
        // moves, so a device redelivering a punch is still recognised. Had the
        // corrected value gone into that column, a shifted estimate would have
        // made the same punch look like a new one.
        $device = $this->device();
        $this->zonedUser('49', $device);

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);
        $this->livePush($device, $this->line('49', '2026-06-19 11:00:00'), Carbon::parse('2026-06-19 09:00:04'));

        $this->assertSame(1, DB::table('biometric_att_logs')->where('user_pin', '49')->count());

        // The device's clock is repaired; the estimate collapses to ~0.
        $this->measureFromLivePushes($device, 0, 13, '2026-06-20 06:00:00');

        // …and it redelivers the same punch.
        $again = $this->livePush($device, $this->line('49', '2026-06-19 11:00:00'), Carbon::parse('2026-06-20 07:00:00'));

        $this->assertSame(1, $again['duplicates']);
        $this->assertSame(1, DB::table('biometric_att_logs')->where('user_pin', '49')->count());
        $this->assertSame(1, Attendance::whereNotNull('punchin')->where('user_id', '49')->count());
    }

    // ── 6. the read model ───────────────────────────────────────────

    public function test_the_snapshot_says_what_is_being_applied_and_why(): void
    {
        // Silent correction is its own hazard: an admin has to be able to see
        // that punches are being moved, by how much, and on what evidence.
        $device = $this->device();

        $unmeasured = $this->clock()->snapshot($device);
        $this->assertFalse($unmeasured['measured']);
        $this->assertNull($unmeasured['offset_seconds']);
        $this->assertNull($unmeasured['measured_at']);
        $this->assertSame(0, $unmeasured['sample_count']);
        $this->assertStringContainsString('not been measured', $unmeasured['reason_label']);

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);

        $measured = $this->clock()->snapshot($device);

        $this->assertSame($device->id, $measured['device_id']);
        $this->assertSame($device->serial_number, $measured['serial_number']);
        $this->assertSame(self::REAL_OFFSET, $measured['offset_seconds']);
        $this->assertSame(6, $measured['sample_count']);
        $this->assertNotNull($measured['measured_at']);
        $this->assertTrue($measured['is_applied']);
        $this->assertStringContainsString('-1h 59m 56s', $measured['reason_label']);
        $this->assertSame(DeviceClockService::MIN_TRUSTED_SAMPLES, $measured['min_samples']);
        $this->assertSame(DeviceClockService::MIN_APPLY_SECONDS, $measured['apply_threshold_seconds']);

        // The evidence itself is readable, not just the conclusion.
        $samples = $this->clock()->recentSamples($device);
        $this->assertCount(6, $samples);
        $this->assertSame(self::REAL_OFFSET, $samples[0]['offset_seconds']);
    }

    public function test_the_migration_is_reversible_and_re_runnable(): void
    {
        // Every column add is hasColumn-guarded and there is no driver branch
        // anywhere in it, so the SQLite this suite runs on and the MySQL
        // production runs on take the same path. A half-applied run must be
        // re-runnable rather than fatal.
        $migration = require database_path('migrations/2026_08_06_000001_add_device_clock_offset_tracking.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('biometric_devices', 'clock_offset_seconds'));
        $this->assertFalse(Schema::hasColumn('biometric_devices', 'clock_offset_samples'));
        $this->assertFalse(Schema::hasColumn('biometric_devices', 'clock_offset_measured_at'));
        $this->assertFalse(Schema::hasTable(DeviceClockService::SAMPLES_TABLE));
        $this->assertFalse(Schema::hasColumn('biometric_att_logs', 'corrected_punch_time'));
        $this->assertFalse(Schema::hasColumn('biometric_att_logs', 'clock_offset_applied_seconds'));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('biometric_devices', 'clock_offset_seconds'));
        $this->assertTrue(Schema::hasTable(DeviceClockService::SAMPLES_TABLE));
        $this->assertTrue(Schema::hasColumn('biometric_att_logs', 'corrected_punch_time'));
    }

    public function test_a_stale_measurement_stops_being_applied(): void
    {
        // The estimate is only refreshed when a device pushes. A unit that has
        // gone quiet must not keep having a months-old offset applied to logs
        // downloaded from it long afterwards.
        $device = $this->device();

        $this->measureFromLivePushes($device, self::REAL_OFFSET, 6);
        $this->assertTrue($this->clock()->snapshot($device)['is_applied']);

        Carbon::setTestNow(Carbon::parse('2026-06-19 06:00:00')->addDays(DeviceClockService::MAX_OFFSET_AGE_DAYS + 1));

        $stale = $this->clock()->snapshot($device);

        $this->assertTrue($stale['measured'], 'The measurement is kept and still shown…');
        $this->assertSame(self::REAL_OFFSET, $stale['offset_seconds']);
        $this->assertFalse($stale['is_applied'], '…but it is no longer acted on.');
        $this->assertSame(DeviceClockService::REASON_STALE, $stale['reason']);

        $correction = $this->clock()->correct($device, '2026-06-19 11:00:00');
        $this->assertSame('2026-06-19 11:00:00', $correction['punch_time']);
        $this->assertFalse($correction['applied']);
    }
}
