<?php

namespace Tests\Feature\Biometric;

use App\Models\HRM\BiometricAttLog;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\HRM\BiometricDownloadSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Do the biometric models honestly describe their tables?
 *
 * This module grew across many agents and months, and the same two defects keep
 * recurring in both directions:
 *
 *  - A migration adds a column and the model never catches up, so Eloquent hands
 *    the value back as whatever the driver produced. `corrected_punch_time` and
 *    `clock_offset_applied_seconds` (2026_08_06_000001) shipped this way: absent
 *    from `$fillable` (so a `create()` carrying a correction dropped it in
 *    silence) and absent from `$casts` (so the column an auditor compares
 *    against `punch_time` came back as a raw string, and any consumer doing date
 *    arithmetic on it got a subtly wrong answer).
 *  - A `$fillable` entry names a column that does not exist. `port` and `notes`
 *    sat in BiometricDevice::$fillable for months with no column behind them —
 *    unreachable twice over, and the kind of gap where simply adding validation
 *    converts a silently-dropped field into a hard SQL error.
 *
 * So the reconciliation tests below are driven by `Schema::getColumnListing()`
 * rather than by a hand-written list. A hand-written list is the same artefact
 * that went stale in the first place; asking the database means a future
 * migration that adds a column fails here until a human decides whether it is
 * fillable or deliberately not.
 *
 * The cast assertions are round-trips, not `assertArrayHasKey` on `$casts`:
 * values go in through the query builder as raw strings (which is what MySQL
 * hands back for integer and timestamp columns) and come out through Eloquent,
 * so what is being proven is the type a consumer actually receives.
 *
 * The last test is the valuable one. BiometricDeviceCommand carries three const
 * catalogues of command types, read by the queuing layer, the capability
 * endpoint and command history. Nothing previously connected them to
 * `toAdmsString()`, and a previous round found two types catalogued as
 * destructive while the switch had no `case` for them — so they were advertised
 * in the UI, confirmed by an admin, queued, and emitted as `C:<id>:UNKNOWN`.
 */
class BiometricModelIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columns that exist but are deliberately kept out of `$fillable`, per model.
     *
     * Asserted explicitly in both directions below: the named column must be a
     * real column AND must stay out of `$fillable`. An empty list means every
     * non-key column on that table is mass-assignable.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const DELIBERATE_NON_FILLABLE = [
        BiometricDevice::class => [
            // Maintained by DeviceCapabilityService::touchProbedAt(), which sets
            // it with setAttribute() precisely because it is not admin form
            // input. It records when WE last probed the device; a form that
            // could set it could fake a probe.
            'capabilities_probed_at',
        ],
        BiometricAttLog::class => [],
        BiometricDeviceCommand::class => [],
        BiometricDownloadSession::class => [],
    ];

    /** Columns every model manages itself; never expected in `$fillable`. */
    private const MANAGED_COLUMNS = ['id', 'created_at', 'updated_at'];

    /**
     * Columns that exist ONLY on the test driver, as an artefact of a migration
     * that guarded itself by driver. They are not part of the real schema and
     * must never be made fillable — a `$fillable` entry for one of these would
     * be a phantom in production while looking perfectly valid here.
     *
     * `biometric_att_logs.employee_id`: added unconditionally by
     * 2026_05_17_120133 and dropped again by 2026_05_17_121505 — except that the
     * drop begins `if (DB::getDriverName() === 'sqlite') { return; }`. MySQL
     * therefore has no such column and SQLite does, so the test database carries
     * a column production does not have. Recorded rather than silently tolerated
     * because this is the divergence the migration comments elsewhere in this
     * module warn about: what the tests run against is not what production got.
     *
     * @var array<string, list<string>> table => columns
     */
    private const SQLITE_ONLY_ARTEFACT_COLUMNS = [
        'biometric_att_logs' => ['employee_id'],
    ];

    private ?BiometricDevice $emitDevice = null;

    /** @return list<class-string<Model>> */
    public static function ownedModels(): array
    {
        return [
            BiometricAttLog::class,
            BiometricDevice::class,
            BiometricDeviceCommand::class,
            BiometricDownloadSession::class,
        ];
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function ownedModelProvider(): array
    {
        $cases = [];

        foreach (self::ownedModels() as $model) {
            $cases[class_basename($model)] = [$model];
        }

        return $cases;
    }

    // ───────────────────────────────────────────── schema ↔ $fillable

    /**
     * Every real column is fillable, deliberately excluded, or model-managed.
     *
     * This is the direction that catches "a migration added a column and the
     * model never caught up" — the class of defect that hid
     * `corrected_punch_time` from `create()`.
     *
     * @dataProvider ownedModelProvider
     *
     * @param  class-string<Model>  $modelClass
     */
    public function test_every_column_is_fillable_deliberately_excluded_or_managed(string $modelClass): void
    {
        $model = new $modelClass;
        $table = $model->getTable();

        $columns = Schema::getColumnListing($table);
        $this->assertNotEmpty($columns, "{$table} reported no columns; the schema-driven check would pass vacuously.");

        $artefacts = self::SQLITE_ONLY_ARTEFACT_COLUMNS[$table] ?? [];

        $accountedFor = array_merge(
            $model->getFillable(),
            self::DELIBERATE_NON_FILLABLE[$modelClass],
            self::MANAGED_COLUMNS,
            $artefacts,
        );

        // An artefact column must stay unreachable: it does not exist on MySQL,
        // so making it fillable would be a production phantom that looks fine here.
        foreach ($artefacts as $artefact) {
            $this->assertNotContains($artefact, $model->getFillable(), sprintf(
                '%s.%s exists only on the test driver (see SQLITE_ONLY_ARTEFACT_COLUMNS) and must not be fillable.',
                $table,
                $artefact,
            ));
        }

        $unaccounted = array_values(array_diff($columns, $accountedFor));

        $this->assertSame([], $unaccounted, sprintf(
            '%s: column(s) [%s] exist on `%s` but are neither in $fillable nor listed as a deliberate exclusion. '.
            'Decide which, then say so here — do not leave the model quietly out of step with its table.',
            class_basename($modelClass),
            implode(', ', $unaccounted),
            $table,
        ));
    }

    /**
     * No `$fillable` entry names a column that does not exist.
     *
     * The `port` / `notes` defect, in the direction that produced it: the model
     * advertised fields the table could not store.
     *
     * @dataProvider ownedModelProvider
     *
     * @param  class-string<Model>  $modelClass
     */
    public function test_no_fillable_entry_names_a_nonexistent_column(string $modelClass): void
    {
        $model = new $modelClass;
        $table = $model->getTable();

        $phantom = array_values(array_diff($model->getFillable(), Schema::getColumnListing($table)));

        $this->assertSame([], $phantom, sprintf(
            '%s: $fillable names [%s], which do not exist on `%s`. A phantom fillable is silently dropped on write '.
            '(and becomes a hard SQL error the moment someone adds validation for it).',
            class_basename($modelClass),
            implode(', ', $phantom),
            $table,
        ));
    }

    /**
     * The deliberate exclusions are real columns, and are still excluded.
     *
     * Guards against both halves going stale: a column that gets dropped should
     * not linger on the exclusion list, and one that gets quietly added to
     * `$fillable` should fail here rather than opening a write path nobody
     * intended.
     *
     * @dataProvider ownedModelProvider
     *
     * @param  class-string<Model>  $modelClass
     */
    public function test_deliberate_exclusions_are_real_columns_and_stay_excluded(string $modelClass): void
    {
        $model = new $modelClass;
        $exclusions = self::DELIBERATE_NON_FILLABLE[$modelClass];

        if ($exclusions === []) {
            $this->assertSame([], $exclusions);

            return;
        }

        foreach ($exclusions as $column) {
            $this->assertTrue(
                Schema::hasColumn($model->getTable(), $column),
                "{$model->getTable()}.{$column} is listed as a deliberate non-fillable but no longer exists."
            );
            $this->assertNotContains(
                $column,
                $model->getFillable(),
                "{$column} is documented as deliberately non-fillable but has been added to ".class_basename($modelClass).'::$fillable.'
            );
        }
    }

    /**
     * `capabilities_probed_at` is service-maintained, and stays that way.
     *
     * Called out on its own rather than only through the data-driven check
     * above, because the reason is not visible from the schema:
     * DeviceCapabilityService writes it with setAttribute() and its comment says
     * outright that it is deliberately absent from $fillable.
     */
    public function test_capabilities_probed_at_is_not_mass_assignable_but_is_cast(): void
    {
        $device = new BiometricDevice;

        $this->assertNotContains('capabilities_probed_at', $device->getFillable());

        // $fillable governs writes and $casts governs reads; the two are
        // independent, and this column needs the cast regardless. The service
        // reads it back through Carbon::parse() to answer "last probed N hours
        // ago", which worked on a raw string by luck rather than by contract.
        $this->assertArrayHasKey('capabilities_probed_at', $device->getCasts());
    }

    /**
     * `adms_token` IS fillable, and that is deliberate.
     *
     * Pinned because the obvious "fix" is wrong. The protection against an admin
     * choosing a shared secret lives in the controller's validation rules, which
     * omit the field, and BiometricDeviceProvisioningTest asserts a posted
     * `adms_token` is ignored. `$fillable` has to keep it because the
     * server-side regenerateAdmsToken() writes it through update() — pulling it
     * out breaks provisioning while adding no protection the controller does not
     * already provide.
     */
    public function test_adms_token_stays_fillable_because_regeneration_writes_through_update(): void
    {
        $this->assertContains('adms_token', (new BiometricDevice)->getFillable());

        $device = BiometricDevice::create([
            'name' => 'Provisioning Check',
            'serial_number' => 'SN-INTEGRITY-ADMS',
        ]);

        $this->assertNull($device->adms_token, 'NULL means allowlist-only; it must not be auto-generated on create.');

        $token = $device->regenerateAdmsToken();

        $this->assertSame($token, $device->fresh()->adms_token);
    }

    // ───────────────────────────────────────────── schema ↔ $casts

    /**
     * The one the flagged defect was about.
     *
     * `punch_time` holds the RAW device value and is part of the punch natural
     * key that 2026_08_03_000001 made unique; `corrected_punch_time` holds what
     * was actually written to `attendances`. An auditor reads the pair. Both
     * must come back as dates, and the raw column must survive the round trip
     * byte-for-byte — the correction is additive, never a rewrite.
     */
    public function test_att_log_punch_times_and_offset_come_back_typed(): void
    {
        $device = $this->device('SN-INTEGRITY-LOG');

        $rawPunch = '2026-06-19 11:00:00';   // what the terminal claimed (2 h fast)
        $corrected = '2026-06-19 09:00:04';  // what actually landed in attendances

        DB::table('biometric_att_logs')->insert([
            'biometric_device_id' => (string) $device->id,
            'serial_number' => $device->serial_number,
            'user_pin' => '1024',
            'user_id' => '42',
            'punch_time' => $rawPunch,
            'corrected_punch_time' => $corrected,
            'clock_offset_applied_seconds' => '-7196',
            'check_type' => 'in',
            'punch_status' => 'processed',
            'context' => json_encode(['Status' => '0', 'VerifyCode' => '1']),
            'occurred_at' => $rawPunch,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $log = BiometricAttLog::firstOrFail();

        $this->assertInstanceOf(Carbon::class, $log->punch_time);
        $this->assertInstanceOf(Carbon::class, $log->corrected_punch_time, 'corrected_punch_time must not come back as a raw string.');
        $this->assertInstanceOf(Carbon::class, $log->occurred_at);
        $this->assertIsInt($log->clock_offset_applied_seconds);
        $this->assertIsArray($log->context);
        $this->assertIsInt($log->biometric_device_id);
        $this->assertIsString($log->user_id); // employee_id keys are strings (model cast)

        // Date arithmetic on the pair — the exact thing an uncast string breaks.
        // Carbon 3 returns a float from diffInSeconds(); the cast to int is on
        // the diff, never on the assertion's expected value.
        $this->assertSame(
            -7196,
            (int) $log->punch_time->diffInSeconds($log->corrected_punch_time, false),
            'corrected = raw + clock_offset_applied_seconds must hold as dates, not as strings.'
        );
        $this->assertSame($log->clock_offset_applied_seconds, (int) $log->punch_time->diffInSeconds($log->corrected_punch_time, false));

        // The raw device account is never rewritten: it is one quarter of the
        // unique natural key, and a moving key would reopen the duplicate defect
        // 2026_08_03_000001 exists to close.
        $this->assertSame($rawPunch, $log->getRawOriginal('punch_time'));
        $this->assertSame($corrected, $log->getRawOriginal('corrected_punch_time'));
    }

    /**
     * NULL correction columns stay NULL.
     *
     * NULL means "no correction was applied and `punch_time` is what was used",
     * which is not the same as a correction of zero. A cast that coerced null to
     * 0 (or to an epoch date) would turn "we did nothing" into "we verified this
     * clock", which is exactly the distinction the migration insisted on.
     */
    public function test_att_log_correction_columns_preserve_null(): void
    {
        $device = $this->device('SN-INTEGRITY-NULL');

        DB::table('biometric_att_logs')->insert([
            'biometric_device_id' => $device->id,
            'serial_number' => $device->serial_number,
            'user_pin' => '1024',
            'punch_time' => '2026-06-19 09:00:00',
            'corrected_punch_time' => null,
            'clock_offset_applied_seconds' => null,
            'check_type' => 'in',
            'punch_status' => 'processed',
            'occurred_at' => '2026-06-19 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $log = BiometricAttLog::firstOrFail();

        $this->assertNull($log->corrected_punch_time);
        $this->assertNull($log->clock_offset_applied_seconds);
        $this->assertNull($log->user_id);
    }

    /**
     * The correction columns are reachable through mass assignment.
     *
     * The ADMS path writes through the query builder, but the direct webhook
     * path builds an array and hands it to BiometricAttLog::create(). Before
     * these entries existed a correction passed that way was dropped in silence.
     */
    public function test_att_log_correction_columns_are_mass_assignable(): void
    {
        $device = $this->device('SN-INTEGRITY-FILL');

        $log = BiometricAttLog::create([
            'biometric_device_id' => $device->id,
            'serial_number' => $device->serial_number,
            'user_pin' => '1024',
            'punch_time' => '2026-06-19 11:00:00',
            'corrected_punch_time' => '2026-06-19 09:00:04',
            'clock_offset_applied_seconds' => -7196,
            'check_type' => 'in',
            'punch_status' => 'processed',
            'occurred_at' => '2026-06-19 11:00:00',
        ]);

        $stored = DB::table('biometric_att_logs')->where('id', $log->id)->first();

        $this->assertNotNull($stored->corrected_punch_time, 'corrected_punch_time was dropped by mass assignment.');
        $this->assertSame(-7196, (int) $stored->clock_offset_applied_seconds);
    }

    /**
     * Device columns come back as the right PHP types.
     *
     * Values are inserted as strings on purpose: that is what MySQL hands back
     * for integer, boolean and timestamp columns, and it is the shape SQLite
     * would otherwise quietly paper over.
     */
    public function test_device_columns_come_back_typed(): void
    {
        DB::table('biometric_devices')->insert([
            'name' => 'Typed Device',
            'serial_number' => 'SN-INTEGRITY-TYPES',
            'auth_token' => 'token-integrity-types',
            'ip_address' => '10.0.0.9',
            'port' => '4370',
            'protocol' => 'adms',
            'users_count' => '13',
            'is_active' => '1',
            'config' => json_encode(['timezone' => 'Asia/Dhaka']),
            'last_heartbeat_at' => '2026-08-07 10:00:00',
            'last_log_download_at' => '2026-08-07 09:00:00',
            'capabilities_probed_at' => '2026-08-07 08:00:00',
            'clock_offset_seconds' => '7196',
            'clock_offset_samples' => '827',
            'clock_offset_measured_at' => '2026-08-07 07:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $device = BiometricDevice::where('serial_number', 'SN-INTEGRITY-TYPES')->firstOrFail();

        $this->assertIsBool($device->is_active);
        $this->assertIsArray($device->config);
        $this->assertIsInt($device->port);
        $this->assertIsInt($device->users_count);
        $this->assertIsInt($device->clock_offset_seconds);
        $this->assertIsInt($device->clock_offset_samples);

        foreach (['last_heartbeat_at', 'last_log_download_at', 'capabilities_probed_at', 'clock_offset_measured_at'] as $column) {
            $this->assertInstanceOf(Carbon::class, $device->{$column}, "{$column} came back uncast.");
        }
    }

    /**
     * A never-measured clock stays never-measured.
     *
     * NULL `clock_offset_seconds` means "this device's clock has never been
     * measured", which behaves differently from a measured zero (DeviceClockService).
     * An 'integer' cast returns null for null; anything that coerced it to 0
     * would make every unmeasured device claim a verified-correct clock.
     */
    public function test_unmeasured_clock_offset_stays_null_not_zero(): void
    {
        $device = $this->device('SN-INTEGRITY-UNMEASURED');

        $device = $device->fresh();

        $this->assertNull($device->clock_offset_seconds);
        $this->assertNull($device->clock_offset_samples);
        $this->assertNull($device->clock_offset_measured_at);
        $this->assertNotSame(0, $device->clock_offset_seconds);
    }

    /** Command columns come back as the right PHP types. */
    public function test_command_columns_come_back_typed(): void
    {
        $device = $this->device('SN-INTEGRITY-CMD');

        DB::table('biometric_device_commands')->insert([
            'biometric_device_id' => (string) $device->id,
            'command_type' => 'CHECK_ATTLOG',
            'payload' => json_encode(['start_time' => '2026-08-01 00:00:00']),
            'status' => BiometricDeviceCommand::STATUS_SENT,
            'retry_count' => '2',
            'sent_at' => '2026-08-07 10:00:00',
            'executed_at' => '2026-08-07 10:00:05',
            'scheduled_at' => '2026-08-07 09:59:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $command = BiometricDeviceCommand::firstOrFail();

        $this->assertIsArray($command->payload);
        $this->assertIsInt($command->retry_count);
        $this->assertIsInt($command->biometric_device_id);
        $this->assertInstanceOf(Carbon::class, $command->sent_at);
        $this->assertInstanceOf(Carbon::class, $command->executed_at);
        $this->assertInstanceOf(Carbon::class, $command->scheduled_at);

        // markAsSent() does `$this->retry_count + 1`; prove it stays an integer
        // rather than relying on PHP coercing a MySQL string.
        $command->markAsSent();
        $this->assertSame(3, $command->fresh()->retry_count);
    }

    /**
     * Every status in STATUSES actually persists.
     *
     * `status` was created as a MySQL ENUM (a CHECK constraint on SQLite) and
     * widened for `unsupported` by 2026_07_29_101500. The const list is only
     * authoritative if the column agrees with it on every driver.
     */
    public function test_every_declared_command_status_persists(): void
    {
        $device = $this->device('SN-INTEGRITY-STATUS');

        foreach (BiometricDeviceCommand::STATUSES as $status) {
            $command = BiometricDeviceCommand::create([
                'biometric_device_id' => $device->id,
                'command_type' => 'INFO',
                'status' => $status,
            ]);

            $this->assertSame($status, $command->fresh()->status, "status '{$status}' did not survive a round trip.");
        }
    }

    /** Download-session columns come back as the right PHP types. */
    public function test_download_session_columns_come_back_typed(): void
    {
        $device = $this->device('SN-INTEGRITY-SESSION');

        DB::table('biometric_download_sessions')->insert([
            'biometric_device_id' => (string) $device->id,
            'trigger_type' => 'manual',
            'status' => 'partial',
            'total_records' => '953',
            'processed_count' => '900',
            'duplicate_count' => '50',
            'failed_count' => '3',
            'started_at' => '2026-08-07 10:00:00',
            'completed_at' => '2026-08-07 10:05:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = BiometricDownloadSession::firstOrFail();

        foreach (['total_records', 'processed_count', 'duplicate_count', 'failed_count'] as $counter) {
            $this->assertIsInt($session->{$counter}, "{$counter} came back uncast.");
        }

        $this->assertIsInt($session->biometric_device_id);
        $this->assertInstanceOf(Carbon::class, $session->started_at);
        $this->assertInstanceOf(Carbon::class, $session->completed_at);

        // ProcessBiometricDownloadSession branches on these to choose
        // completed / partial / failed. Strings compared loosely happened to
        // work; integers make it true by construction.
        $this->assertTrue($session->failed_count > 0 && $session->processed_count > 0);
    }

    // ───────────────────────────────────────────── relationships

    /** @dataProvider ownedModelProvider */
    public function test_declared_relationships_resolve_against_real_columns(string $modelClass): void
    {
        $device = $this->device('SN-INTEGRITY-REL-'.substr(md5($modelClass), 0, 8));

        $rows = [
            BiometricAttLog::class => fn () => BiometricAttLog::create([
                'biometric_device_id' => $device->id,
                'serial_number' => $device->serial_number,
                'user_pin' => '1024',
                'punch_time' => '2026-06-19 09:00:00',
                'check_type' => 'in',
                'punch_status' => 'processed',
                'occurred_at' => '2026-06-19 09:00:00',
            ]),
            BiometricDevice::class => fn () => $device,
            BiometricDeviceCommand::class => fn () => BiometricDeviceCommand::create([
                'biometric_device_id' => $device->id,
                'command_type' => 'INFO',
                'status' => BiometricDeviceCommand::STATUS_PENDING,
            ]),
            BiometricDownloadSession::class => fn () => BiometricDownloadSession::create([
                'biometric_device_id' => $device->id,
                'trigger_type' => 'manual',
                'status' => 'pending',
            ]),
        ];

        $model = $rows[$modelClass]();

        match ($modelClass) {
            BiometricAttLog::class => $this->assertTrue(
                $model->device()->exists() && $model->user()->doesntExist()
            ),
            BiometricDevice::class => $this->assertSame(
                0,
                $model->downloadSessions()->count() + $model->attendanceTypes()->count()
            ),
            BiometricDeviceCommand::class => $this->assertTrue($model->device()->exists()),
            BiometricDownloadSession::class => $this->assertTrue(
                $model->device()->exists()
                    && $model->command()->doesntExist()
                    && $model->creator()->doesntExist()
            ),
        };
    }

    // ───────────────────────────── command catalogues ↔ toAdmsString()

    /**
     * THE guard.
     *
     * `DESTRUCTIVE_COMMAND_TYPES` and `HARDWARE_UNVERIFIED_COMMAND_TYPES` are
     * read by the queuing layer, the UI catalogue endpoint and command history.
     * A type catalogued there is advertised to an admin as a real command — so
     * if `toAdmsString()` has no `case` for it, the admin confirms a scary
     * warning, the row is queued, and the device is handed `C:<id>:UNKNOWN`.
     * That is exactly what happened to `CLEAR_PHOTO` and `CLEAR_BIODATA` before
     * their cases existed, and nothing connected the two definitions.
     */
    public function test_catalogued_command_types_all_emit_a_real_adms_string(): void
    {
        $catalogues = [
            'DESTRUCTIVE_COMMAND_TYPES' => array_keys(BiometricDeviceCommand::DESTRUCTIVE_COMMAND_TYPES),
            'HARDWARE_UNVERIFIED_COMMAND_TYPES' => array_keys(BiometricDeviceCommand::HARDWARE_UNVERIFIED_COMMAND_TYPES),
        ];

        foreach ($catalogues as $catalogue => $types) {
            $this->assertNotEmpty($types, "{$catalogue} is empty; this guard would pass vacuously.");

            foreach ($types as $type) {
                $emitted = $this->emit($type);

                $this->assertStringNotContainsString('UNKNOWN', $emitted, sprintf(
                    '%s catalogues "%s" but toAdmsString() has no case for it and emits "%s". '.
                    'The UI advertises this command; the device receives nothing it can act on.',
                    $catalogue,
                    $type,
                    $emitted,
                ));

                $this->assertMatchesRegularExpression('/^C:\d+:\S/', $emitted, sprintf(
                    '%s: "%s" emitted "%s", which is not a well-formed C:<id>:<command> string.',
                    $catalogue,
                    $type,
                    $emitted,
                ));
            }
        }
    }

    /**
     * Nothing on the refusal list is emittable.
     *
     * `DELIBERATELY_UNIMPLEMENTED` is the inverse guard: `Shell` is arbitrary OS
     * command execution on a terminal sitting on the office LAN, and the others
     * are refused for the reasons recorded on the const. If someone ever adds a
     * `case` for one of these, it must fail here rather than quietly becoming
     * reachable.
     */
    public function test_deliberately_unimplemented_command_types_are_not_emittable(): void
    {
        $this->assertNotEmpty(BiometricDeviceCommand::DELIBERATELY_UNIMPLEMENTED);

        foreach (BiometricDeviceCommand::DELIBERATELY_UNIMPLEMENTED as $type) {
            $this->assertTrue(
                BiometricDeviceCommand::isDeliberatelyUnimplementedType($type),
                "{$type} is on DELIBERATELY_UNIMPLEMENTED but isDeliberatelyUnimplementedType() does not recognise it."
            );

            $this->assertStringEndsWith(':UNKNOWN', $this->emit($type), sprintf(
                '%s is documented as deliberately unimplemented but toAdmsString() now emits a real command for it. '.
                'Read the reasoning on DELIBERATELY_UNIMPLEMENTED before making it reachable.',
                $type,
            ));

            $this->assertFalse(
                BiometricDeviceCommand::isDestructiveType($type),
                "{$type} cannot be both deliberately unimplemented and catalogued as destructive."
            );
            $this->assertFalse(
                BiometricDeviceCommand::isHardwareUnverifiedType($type),
                "{$type} cannot be both deliberately unimplemented and on the hardware-probe worklist."
            );
        }
    }

    /**
     * The catalogue accessors answer from the catalogues.
     *
     * The comment on the accessors says the point of them is that the queuing
     * layer, the catalogue endpoint and command history all read one definition
     * instead of each keeping a list; this pins that they actually do.
     */
    public function test_catalogue_accessors_agree_with_their_const_arrays(): void
    {
        foreach (BiometricDeviceCommand::DESTRUCTIVE_COMMAND_TYPES as $type => $warning) {
            $this->assertTrue(BiometricDeviceCommand::isDestructiveType($type));
            $this->assertSame($warning, BiometricDeviceCommand::destructiveWarningFor($type));
            $this->assertNotSame('', trim($warning), "{$type} is flagged destructive with no warning text for the admin.");
        }

        foreach (BiometricDeviceCommand::HARDWARE_UNVERIFIED_COMMAND_TYPES as $type => $reason) {
            $this->assertTrue(BiometricDeviceCommand::isHardwareUnverifiedType($type));
            $this->assertSame($reason, BiometricDeviceCommand::hardwareUnverifiedReasonFor($type));
            $this->assertNotSame('', trim($reason), "{$type} is flagged hardware-unverified with no reason recorded.");
        }

        $this->assertFalse(BiometricDeviceCommand::isDestructiveType(null));
        $this->assertNull(BiometricDeviceCommand::destructiveWarningFor('INFO'));
        $this->assertFalse(BiometricDeviceCommand::isHardwareUnverifiedType('CHECK_ATTLOG'), 'CHECK_ATTLOG is one of the six hardware-verified commands.');
    }

    /**
     * Every RETURN_CODE decodes, and the unsupported flag survives to `status`.
     *
     * `unsupported` exists precisely so a -1004 is not folded into `failed`;
     * markAsExecuted() is where that distinction is either kept or lost.
     */
    public function test_return_codes_map_to_the_declared_statuses(): void
    {
        $device = $this->device('SN-INTEGRITY-RETURN');

        foreach (BiometricDeviceCommand::RETURN_CODES as $code => $meaning) {
            $command = BiometricDeviceCommand::create([
                'biometric_device_id' => $device->id,
                'command_type' => 'INFO',
                'status' => BiometricDeviceCommand::STATUS_SENT,
            ]);

            $command->markAsExecuted((string) $code);

            $expected = match (true) {
                $meaning['ok'] => BiometricDeviceCommand::STATUS_EXECUTED,
                $meaning['unsupported'] => BiometricDeviceCommand::STATUS_UNSUPPORTED,
                default => BiometricDeviceCommand::STATUS_FAILED,
            };

            $fresh = $command->fresh();

            $this->assertSame($expected, $fresh->status, "return code {$code} landed on the wrong status.");
            $this->assertContains($fresh->status, BiometricDeviceCommand::STATUSES);
            $this->assertTrue($fresh->returnCodeMeaning()['known'], "return code {$code} is in RETURN_CODES but decodes as unknown.");
        }
    }

    // ───────────────────────────────────────────── helpers

    private function device(string $serial): BiometricDevice
    {
        return BiometricDevice::create([
            'name' => 'Integrity '.$serial,
            'serial_number' => $serial,
            'protocol' => 'adms',
            'is_active' => true,
        ]);
    }

    /**
     * Emit the ADMS string for a command type, from a persisted row so the
     * `C:<id>:` prefix is the real one.
     *
     * Payloads are deliberately minimal — a catalogued command type must produce
     * a real string from an empty-ish payload, because that is what the queuing
     * layer sends when an admin clicks the button with nothing to fill in.
     */
    private function emit(string $commandType): string
    {
        // Per-test, not static: RefreshDatabase rolls back between tests, so a
        // cached device would be a dangling id on the second test to call this.
        $this->emitDevice ??= $this->device('SN-INTEGRITY-EMIT');

        return BiometricDeviceCommand::create([
            'biometric_device_id' => $this->emitDevice->id,
            'command_type' => $commandType,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
        ])->toAdmsString();
    }
}
