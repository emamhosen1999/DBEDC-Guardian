<?php

namespace Tests\Feature\Biometric;

use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\User;
use App\Services\Biometric\BiometricProcessingService;
use App\Services\Biometric\TemplateRoamingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Biometric roaming write-back (docs/zkteco-adms-capability-matrix.md §2).
 *
 * Templates have always been captured and never restorable, so replacing a unit
 * meant re-enrolling every person by hand. These tests pin:
 *
 *  - the exact `DATA UPDATE FINGERTMP` / `DATA DELETE FINGERTMP` wire strings,
 *  - that a restore queues one command per template and no more,
 *  - that templates the target already holds are skipped with a stated reason,
 *  - that face templates are refused rather than pushed with a guessed verb,
 *  - that an inactive or non-ADMS target is refused the same way
 *    initiateLogDownload() refuses one,
 *  - that the per-call command cap holds,
 *  - that the UI read model never carries template_data.
 */
class TemplateRoamingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TemplateRoamingService
    {
        return app(TemplateRoamingService::class);
    }

    private function device(array $overrides = []): BiometricDevice
    {
        return BiometricDevice::create(array_merge([
            'name' => 'Gate MB460',
            'serial_number' => 'SN-'.uniqid(),
            'protocol' => 'adms',
            'is_active' => true,
        ], $overrides));
    }

    private function command(BiometricDevice $device, string $type, ?array $payload = null): BiometricDeviceCommand
    {
        return BiometricDeviceCommand::create([
            'biometric_device_id' => $device->id,
            'command_type' => $type,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    /**
     * A stored template row. Mirrors what processTemplateUpload() writes: a
     * fingerprint lands in a real finger slot, and a face/palm row carries the
     * NO_FINGER_INDEX sentinel so it can never share a slot with finger 0.
     */
    private function template(User $user, BiometricDevice $source, array $overrides = []): int
    {
        $type = $overrides['template_type'] ?? 'fingerprint';

        return DB::table('biometric_templates')->insertGetId(array_merge([
            'user_id' => $user->id,
            'biometric_device_id' => $source->id,
            'device_user_id' => (string) $user->employee_id,
            'template_type' => $type,
            'finger_index' => $type === 'fingerprint'
                ? TemplateRoamingService::FALLBACK_FINGER_INDEX
                : TemplateRoamingService::NO_FINGER_INDEX,
            'template_data' => 'VGVtcGxhdGVCbG9i',
            'template_size' => 16,
            'template_version' => 'templatev10',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function capture(): BiometricProcessingService
    {
        return app(BiometricProcessingService::class);
    }

    /**
     * One fingerprint template push line, as a device sends it: tab-separated,
     * TMP last.
     */
    private function fingerPush(string $pin, ?int $fid, string $template): string
    {
        $fidField = $fid === null ? '' : "FID={$fid}\t";

        return "USERID={$pin}\t{$fidField}Size=".strlen($template)."\tValid=1\tTMP={$template}";
    }

    /**
     * A fresh instance of the finger-slot migration.
     *
     * `require` (not `require_once`) re-executes the file, so each call returns a
     * new anonymous-class instance even though the migrator already included it.
     */
    private function fingerSlotMigration(): Migration
    {
        return require database_path(
            'migrations/2026_08_05_000001_add_finger_slot_unique_to_biometric_templates_table.php'
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Task 1 — command vocabulary
    // ──────────────────────────────────────────────────────────────

    public function test_update_fingertmp_emits_the_documented_string(): void
    {
        $device = $this->device();

        $command = $this->command($device, 'UPDATE_FINGERTMP', [
            'pin' => '1024',
            'fid' => 3,
            'size' => 16,
            'valid' => 1,
            'template' => 'VGVtcGxhdGVCbG9i',
        ]);

        // TAB-separated, deliberately. A space-separated string parses as one
        // field on a device that splits on \t: PIN survives, FID/Size/TMP do not,
        // and the device still acks Return=0. See the case comment in
        // BiometricDeviceCommand::toAdmsString() for the three sources.
        $this->assertSame(
            "C:{$command->id}:DATA UPDATE FINGERTMP PIN=1024\tFID=3\tSize=16\tValid=1\tTMP=VGVtcGxhdGVCbG9i",
            $command->toAdmsString()
        );
    }

    public function test_update_fingertmp_defaults_fid_size_and_valid(): void
    {
        $device = $this->device();

        $command = $this->command($device, 'UPDATE_FINGERTMP', [
            'pin' => '7',
            'template' => 'QUJDRA==',
        ]);

        // FID falls back to slot 0 for a payload that carries none, Size is
        // derived from the template, Valid defaults to 1 (enrolled). The fallback
        // is now the exception; a captured template carries its real FID.
        $this->assertSame(
            "C:{$command->id}:DATA UPDATE FINGERTMP PIN=7\tFID=0\tSize=8\tValid=1\tTMP=QUJDRA==",
            $command->toAdmsString()
        );
    }

    public function test_update_fingertmp_uses_tabs_between_fields_and_never_spaces(): void
    {
        $device = $this->device();

        $command = $this->command($device, 'UPDATE_FINGERTMP', [
            'pin' => '11',
            'fid' => 1,
            'template' => 'QUFB',
        ]);

        $emitted = $command->toAdmsString();

        // Exactly four field separators, all tabs. Pinned as a count so a future
        // edit cannot quietly reintroduce a space between two fields.
        $this->assertSame(4, substr_count($emitted, "\t"));

        // Spaces appear only in the verb "DATA UPDATE FINGERTMP" (2) and once
        // between the verb and the first field (1) — exactly as the reference
        // implementation writes it: 'DATA UPDATE FINGERTMP PIN=%s'."\t".'FID=%s'…
        // Every separator *between fields* is a tab, so 3 spaces total and no more.
        $this->assertSame('DATA UPDATE FINGERTMP PIN=', substr($emitted, strpos($emitted, 'DATA'), 26));
        $this->assertSame(3, substr_count($emitted, ' '));
    }

    public function test_update_fingertmp_strips_whitespace_from_the_template(): void
    {
        $device = $this->device();

        // Captured templates can carry newlines: processTemplateUpload()'s regex
        // allows \s inside TMP. Whitespace inside TMP would either truncate the
        // command or, now that fields are tab-separated, split one field into two.
        $command = $this->command($device, 'UPDATE_FINGERTMP', [
            'pin' => '9',
            'fid' => 0,
            'template' => "QUJD\nRA ==\t",
        ]);

        $emitted = $command->toAdmsString();

        $this->assertStringNotContainsString("\n", $emitted);
        $this->assertSame(
            "C:{$command->id}:DATA UPDATE FINGERTMP PIN=9\tFID=0\tSize=8\tValid=1\tTMP=QUJDRA==",
            $emitted
        );

        // The template payload itself carries no separator that could be read as
        // a field boundary.
        $tmp = substr($emitted, strpos($emitted, 'TMP=') + 4);
        $this->assertSame('QUJDRA==', $tmp);
        $this->assertSame(0, preg_match('/\s/', $tmp));
    }

    public function test_delete_fingertmp_emits_pin_and_optional_fid(): void
    {
        $device = $this->device();

        $allFingers = $this->command($device, 'DELETE_FINGERTMP', ['pin' => '1024']);
        $oneFinger = $this->command($device, 'DELETE_FINGERTMP', ['pin' => '1024', 'fid' => 2]);

        $this->assertSame(
            "C:{$allFingers->id}:DATA DELETE FINGERTMP PIN=1024",
            $allFingers->toAdmsString()
        );
        $this->assertSame(
            "C:{$oneFinger->id}:DATA DELETE FINGERTMP PIN=1024\tFID=2",
            $oneFinger->toAdmsString()
        );

        // `FID=` on its own is a syntax error; omission must mean "all fingers".
        $this->assertStringNotContainsString('FID=', $allFingers->toAdmsString());
    }

    public function test_clear_photo_and_clear_biodata_emit_targeted_wipes(): void
    {
        $device = $this->device();

        $photo = $this->command($device, 'CLEAR_PHOTO');
        $biodata = $this->command($device, 'CLEAR_BIODATA');

        $this->assertSame("C:{$photo->id}:CLEAR PHOTO", $photo->toAdmsString());
        $this->assertSame("C:{$biodata->id}:CLEAR BIODATA", $biodata->toAdmsString());

        // Regression guard: both were catalogued as destructive before they had a
        // case in toAdmsString(), so they emitted the fallback. A wipe command that
        // silently degrades to UNKNOWN is the worst of both worlds — it looks
        // dangerous in the UI and does nothing on the device.
        $this->assertStringNotContainsString('UNKNOWN', $photo->toAdmsString());
        $this->assertStringNotContainsString('UNKNOWN', $biodata->toAdmsString());
    }

    public function test_destructive_commands_are_flagged_and_carry_a_warning(): void
    {
        $device = $this->device();

        foreach (['CLEAR_DATA', 'CLEAR_LOG', 'CLEAR_PHOTO', 'CLEAR_BIODATA', 'DELETE_USER', 'DELETE_FINGERTMP'] as $type) {
            $command = $this->command($device, $type, ['pin' => '1']);

            $this->assertTrue($command->isDestructive(), "{$type} must be flagged destructive.");
            $this->assertNotEmpty($command->destructiveWarning(), "{$type} must state what it destroys.");
            $this->assertTrue(BiometricDeviceCommand::isDestructiveType($type));
        }

        // A wipe of biometrics is unrecoverable for faces specifically, and the
        // warning has to say so — that is the whole reason an admin would pause.
        $this->assertStringContainsString(
            'CANNOT',
            BiometricDeviceCommand::destructiveWarningFor('CLEAR_BIODATA')
        );

        // Disruptive but destroys nothing: must NOT be flagged, or the flag stops
        // meaning anything.
        foreach (['REBOOT', 'SET_TIME', 'INFO', 'GET_OPTION', 'UPDATE_FINGERTMP'] as $type) {
            $this->assertFalse(
                BiometricDeviceCommand::isDestructiveType($type),
                "{$type} destroys nothing and must not be flagged destructive."
            );
            $this->assertNull(BiometricDeviceCommand::destructiveWarningFor($type));
        }
    }

    public function test_commands_never_acked_by_a_device_are_marked_hardware_unverified(): void
    {
        // This list is the live-probe worklist. Every command whose string we
        // inferred rather than observed must say so, so a -1002/-1004 reads as
        // "our string or this model", not "the hardware is broken".
        foreach (['UPDATE_FINGERTMP', 'DELETE_FINGERTMP', 'CLEAR_PHOTO', 'CLEAR_BIODATA'] as $type) {
            $this->assertTrue(
                BiometricDeviceCommand::isHardwareUnverifiedType($type),
                "{$type} has never been acked by a device and must be marked unverified."
            );
            $this->assertNotEmpty(BiometricDeviceCommand::hardwareUnverifiedReasonFor($type));
        }

        // ADD_USER / UPDATE_USER read as settled because they are long-standing,
        // which is not the same thing: the string is space-separated where every
        // reference implementation tab-separates USERINFO, and no device has
        // acked one. Long-standing is a reason not to change it, not a reason to
        // present it as verified.
        foreach (['ADD_USER', 'UPDATE_USER'] as $type) {
            $this->assertTrue(
                BiometricDeviceCommand::isHardwareUnverifiedType($type),
                "{$type} has never been acked Return=0 and must not read as settled."
            );
        }

        // The six a real MB460 has acked with Return=0 must not be in there, or
        // the list stops being a worklist.
        foreach (['INFO', 'GET_OPTION', 'SET_OPTION', 'QUERY_USERINFO', 'CHECK_ATTLOG', 'CHECK'] as $type) {
            $this->assertFalse(
                BiometricDeviceCommand::isHardwareUnverifiedType($type),
                "{$type} is hardware-verified in production and must not be listed as unverified."
            );
        }

        $command = $this->command($this->device(), 'UPDATE_FINGERTMP', ['pin' => '1', 'template' => 'QQ==']);
        $this->assertTrue($command->isHardwareUnverified());
        $this->assertNotEmpty($command->hardwareUnverifiedReason());
    }

    public function test_dangerous_commands_are_never_emitted(): void
    {
        $device = $this->device();

        // `Shell` is arbitrary OS command execution on a terminal on the office
        // LAN. AC_UNLOCK is a physical door release behind a 30-120 s poll queue.
        // PutFile/GetFile move firmware over a protocol we cannot test. None of
        // these may ever produce a real command string.
        foreach (['Shell', 'AC_UNLOCK', 'PutFile', 'GetFile', 'LOG'] as $type) {
            $this->assertTrue(
                BiometricDeviceCommand::isDeliberatelyUnimplementedType($type),
                "{$type} must stay on the deliberately-unimplemented list."
            );

            $command = $this->command($device, $type, ['cmd' => 'rm -rf /']);
            $this->assertSame("C:{$command->id}:UNKNOWN", $command->toAdmsString());
        }

        // Specifically: nothing a caller puts in the payload can reach the device.
        $shell = $this->command($device, 'Shell', ['cmd' => 'reboot -f']);
        $this->assertStringNotContainsString('Shell', $shell->toAdmsString());
        $this->assertStringNotContainsString('reboot -f', $shell->toAdmsString());
    }

    public function test_existing_command_strings_are_untouched(): void
    {
        $device = $this->device();

        $reboot = $this->command($device, 'REBOOT');
        $deleteUser = $this->command($device, 'DELETE_USER', ['pin' => '7']);
        $clearData = $this->command($device, 'CLEAR_DATA');
        $clearLog = $this->command($device, 'CLEAR_LOG');

        $this->assertSame("C:{$reboot->id}:REBOOT", $reboot->toAdmsString());
        $this->assertSame("C:{$deleteUser->id}:DATA DELETE USERINFO PIN=7", $deleteUser->toAdmsString());
        $this->assertSame("C:{$clearData->id}:CLEAR DATA", $clearData->toAdmsString());
        $this->assertSame("C:{$clearLog->id}:CLEAR LOG", $clearLog->toAdmsString());
    }

    // ──────────────────────────────────────────────────────────────
    //  Task 2 — restore
    // ──────────────────────────────────────────────────────────────

    public function test_restore_queues_one_command_per_template(): void
    {
        $source = $this->device(['serial_number' => 'AF6P231260266']);
        $target = $this->device(['name' => 'Replacement MB460']);

        $alice = User::factory()->create(['employee_id' => 1001]);
        $bob = User::factory()->create(['employee_id' => 1002]);

        $this->template($alice, $source, ['template_data' => 'QUFB']);
        $this->template($bob, $source, ['template_data' => 'QkJC']);

        $result = $this->service()->restoreTemplatesToDevice($target);

        $this->assertSame(2, $result['queued']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, $result['users']);
        $this->assertSame([], $result['reasons']);

        $commands = BiometricDeviceCommand::where('biometric_device_id', $target->id)->get();
        $this->assertCount(2, $commands);

        foreach ($commands as $command) {
            $this->assertSame('UPDATE_FINGERTMP', $command->command_type);
            $this->assertSame('pending', $command->status);
            $this->assertSame($source->id, $command->payload['source_device_id']);
        }

        $strings = $commands->map->toAdmsString()->all();
        $this->assertContains(
            'C:'.$commands[0]->id.":DATA UPDATE FINGERTMP PIN=1001\tFID=0\tSize=4\tValid=1\tTMP=QUFB",
            $strings
        );
        $this->assertContains(
            'C:'.$commands[1]->id.":DATA UPDATE FINGERTMP PIN=1002\tFID=0\tSize=4\tValid=1\tTMP=QkJC",
            $strings
        );

        // Nothing was queued at the device the templates came from.
        $this->assertSame(0, BiometricDeviceCommand::where('biometric_device_id', $source->id)->count());
    }

    public function test_restore_can_be_scoped_to_specific_users(): void
    {
        $source = $this->device();
        $target = $this->device();

        $alice = User::factory()->create(['employee_id' => 2001]);
        $bob = User::factory()->create(['employee_id' => 2002]);

        $this->template($alice, $source);
        $this->template($bob, $source);

        $result = $this->service()->restoreTemplatesToDevice($target, [$alice->id]);

        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['users']);
        $this->assertSame(
            '2001',
            BiometricDeviceCommand::where('biometric_device_id', $target->id)->sole()->payload['pin']
        );
    }

    public function test_templates_already_on_the_target_are_skipped_with_a_reason(): void
    {
        $source = $this->device();
        $target = $this->device();

        $alice = User::factory()->create(['employee_id' => 3001]);
        $bob = User::factory()->create(['employee_id' => 3002]);

        $this->template($alice, $source);
        $this->template($bob, $source);
        // Alice is already enrolled on the target — restoring would be a no-op.
        $this->template($alice, $target);

        $result = $this->service()->restoreTemplatesToDevice($target);

        // Alice is skipped twice: once for the source copy the target already has,
        // once for the target's own row.
        $this->assertSame(1, $result['queued']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(2, $result['reasons']['already_on_device']);

        $this->assertSame(
            '3002',
            BiometricDeviceCommand::where('biometric_device_id', $target->id)->sole()->payload['pin']
        );
    }

    public function test_the_same_finger_held_by_two_devices_queues_once(): void
    {
        $old = $this->device();
        $spare = $this->device();
        $target = $this->device();

        $alice = User::factory()->create(['employee_id' => 4001]);

        $this->template($alice, $old, ['template_data' => 'T0xE', 'updated_at' => now()->subDay()]);
        $this->template($alice, $spare, ['template_data' => 'TkVX', 'updated_at' => now()]);

        $result = $this->service()->restoreTemplatesToDevice($target);

        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['reasons']['duplicate_template']);

        // The freshest enrolment wins.
        $command = BiometricDeviceCommand::where('biometric_device_id', $target->id)->sole();
        $this->assertSame('TkVX', $command->payload['template']);
        $this->assertSame($spare->id, $command->payload['source_device_id']);
    }

    public function test_face_templates_are_never_pushed_with_a_guessed_verb(): void
    {
        $source = $this->device();
        $target = $this->device();

        $alice = User::factory()->create(['employee_id' => 5001]);

        $this->template($alice, $source, [
            'template_type' => 'face',
            'template_version' => 'facetmpv10',
        ]);

        $result = $this->service()->restoreTemplatesToDevice($target);

        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['reasons'][TemplateRoamingService::FACE_REASON]);
        $this->assertSame(0, BiometricDeviceCommand::count());
    }

    public function test_empty_and_oversized_templates_are_refused(): void
    {
        $source = $this->device();
        $target = $this->device();

        $blank = User::factory()->create(['employee_id' => 6001]);
        $huge = User::factory()->create(['employee_id' => 6002]);

        $this->template($blank, $source, ['template_data' => "   \n\t "]);
        $this->template($huge, $source, [
            'template_data' => str_repeat('A', TemplateRoamingService::MAX_TEMPLATE_BYTES + 1),
        ]);

        $result = $this->service()->restoreTemplatesToDevice($target);

        $this->assertSame(0, $result['queued']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(1, $result['reasons']['empty_template']);
        $this->assertSame(1, $result['reasons']['template_too_large']);
    }

    public function test_the_per_call_command_cap_is_enforced(): void
    {
        $source = $this->device();
        $target = $this->device();

        $user = User::factory()->create(['employee_id' => 7000]);

        $cap = TemplateRoamingService::MAX_COMMANDS_PER_RESTORE;
        $rows = [];
        for ($i = 0; $i < $cap + 5; $i++) {
            $rows[] = [
                'user_id' => $user->id,
                'biometric_device_id' => $source->id,
                'device_user_id' => (string) (8000 + $i),
                'template_type' => 'fingerprint',
                'finger_index' => TemplateRoamingService::FALLBACK_FINGER_INDEX,
                'template_data' => 'QUFB',
                'template_size' => 4,
                'template_version' => 'templatev10',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('biometric_templates')->insert($rows);

        $result = $this->service()->restoreTemplatesToDevice($target);

        $this->assertSame($cap, $result['queued']);
        $this->assertSame(5, $result['skipped']);
        $this->assertSame(5, $result['reasons']['cap_reached']);
        $this->assertSame(
            $cap,
            BiometricDeviceCommand::where('biometric_device_id', $target->id)->count()
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Target guards — same shape as initiateLogDownload()
    // ──────────────────────────────────────────────────────────────

    public function test_an_inactive_target_is_refused(): void
    {
        $target = $this->device(['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Device is inactive.');

        $this->service()->restoreTemplatesToDevice($target);
    }

    public function test_a_non_adms_target_is_refused(): void
    {
        $target = $this->device(['protocol' => 'webhook']);

        try {
            $this->service()->restoreTemplatesToDevice($target);
            $this->fail('A non-ADMS target must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Template restore is only supported for ADMS devices.', $e->getMessage());
        }

        $this->assertSame(0, BiometricDeviceCommand::count());
    }

    public function test_delete_refuses_a_non_adms_or_inactive_target_and_an_empty_pin(): void
    {
        $service = $this->service();

        try {
            $service->deleteTemplateFromDevice($this->device(['is_active' => false]), '1');
            $this->fail('An inactive target must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Device is inactive.', $e->getMessage());
        }

        try {
            $service->deleteTemplateFromDevice($this->device(['protocol' => 'webhook']), '1');
            $this->fail('A non-ADMS target must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Template delete is only supported for ADMS devices.', $e->getMessage());
        }

        try {
            $service->deleteTemplateFromDevice($this->device(), '   ');
            $this->fail('An empty PIN must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('A device PIN is required.', $e->getMessage());
        }

        $this->assertSame(0, BiometricDeviceCommand::count());
    }

    public function test_delete_queues_a_pending_command_and_keeps_our_stored_copy(): void
    {
        $device = $this->device();
        $alice = User::factory()->create(['employee_id' => 9001]);
        $this->template($alice, $device);

        $command = $this->service()->deleteTemplateFromDevice($device, '9001', 1);

        $this->assertSame('DELETE_FINGERTMP', $command->command_type);
        $this->assertSame('pending', $command->status);
        $this->assertSame($device->id, $command->biometric_device_id);
        $this->assertSame(
            "C:{$command->id}:DATA DELETE FINGERTMP PIN=9001\tFID=1",
            $command->toAdmsString()
        );

        // The verb is single-source and no device has acked one. That has to be
        // visible on the row, not just in a comment.
        $this->assertTrue($command->isHardwareUnverified());
        $this->assertTrue($command->isDestructive());

        // Our copy is the backup that makes roaming possible; deleting on the
        // device must not destroy it.
        $this->assertDatabaseHas('biometric_templates', [
            'user_id' => $alice->id,
            'biometric_device_id' => $device->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  listTemplates
    // ──────────────────────────────────────────────────────────────

    public function test_list_templates_returns_the_ui_read_model_without_template_data(): void
    {
        $source = $this->device(['name' => 'Gate A', 'serial_number' => 'AF6P231260266']);
        $alice = User::factory()->create(['employee_id' => 4242, 'name' => 'Alice Rahman']);

        $this->template($alice, $source, ['template_data' => 'U0VDUkVU', 'template_size' => 8]);

        $rows = $this->service()->listTemplates();

        $this->assertCount(1, $rows);
        $row = $rows->first();

        $this->assertSame([
            'id', 'user_id', 'user_name', 'employee_id', 'pin', 'template_type',
            'finger_index', 'template_size', 'template_version', 'source_device_id',
            'source_device_name', 'source_device_serial', 'captured_at', 'updated_at',
            'restorable', 'not_restorable_reason', 'not_restorable_code',
        ], array_keys($row));

        $this->assertSame('Alice Rahman', $row['user_name']);
        $this->assertSame('4242', $row['pin']);
        $this->assertSame('fingerprint', $row['template_type']);
        $this->assertSame(8, $row['template_size']);
        $this->assertSame('Gate A', $row['source_device_name']);
        $this->assertSame('AF6P231260266', $row['source_device_serial']);
        $this->assertTrue($row['restorable']);
        $this->assertNull($row['not_restorable_reason']);
        $this->assertNull($row['not_restorable_code']);
        // A fingerprint always reports a real slot now; this one was captured
        // without a FID, so it sits in the fallback slot rather than in NULL.
        $this->assertSame(0, $row['finger_index']);
        $this->assertNotNull($row['captured_at']);

        // The payload itself must never reach a browser.
        $this->assertArrayNotHasKey('template_data', $row);
        $this->assertStringNotContainsString('U0VDUkVU', json_encode($rows->all()));
    }

    public function test_list_templates_filters_by_user_and_device_and_flags_face_rows(): void
    {
        $deviceA = $this->device();
        $deviceB = $this->device();

        $alice = User::factory()->create(['employee_id' => 5101]);
        $bob = User::factory()->create(['employee_id' => 5102]);

        $this->template($alice, $deviceA);
        $this->template($bob, $deviceB);
        $this->template($bob, $deviceB, [
            'template_type' => 'face',
            'template_version' => 'facetmpv10',
        ]);

        $service = $this->service();

        $this->assertCount(3, $service->listTemplates());
        $this->assertCount(1, $service->listTemplates($alice->id));
        $this->assertCount(2, $service->listTemplates(null, $deviceB->id));
        $this->assertCount(0, $service->listTemplates($alice->id, $deviceB->id));

        $face = $service->listTemplates($bob->id)->firstWhere('template_type', 'face');
        $this->assertFalse($face['restorable']);
        $this->assertSame(TemplateRoamingService::FACE_REASON, $face['not_restorable_code']);
        $this->assertSame(TemplateRoamingService::FACE_REASON_MESSAGE, $face['not_restorable_reason']);
    }

    public function test_a_face_row_is_listed_but_not_restorable_with_an_actionable_reason(): void
    {
        $source = $this->device(['serial_number' => 'AF6P231260266']);
        $alice = User::factory()->create(['employee_id' => 5501]);

        $this->template($alice, $source, [
            'template_type' => 'face',
            'template_version' => 'facetmpv10',
        ]);

        $row = $this->service()->listTemplates()->sole();

        // Listed: the gap has to be visible. Somebody's face IS held here, and
        // hiding the row would let an admin decommission a unit believing the
        // enrolment was covered.
        $this->assertSame('face', $row['template_type']);
        $this->assertSame('facetmpv10', $row['template_version']);
        $this->assertFalse($row['restorable']);

        // The reason is rendered verbatim into the "Listed only" tooltip in
        // BiometricPanel.jsx, so it must be a sentence, not a snake_case key.
        $reason = $row['not_restorable_reason'];
        $this->assertStringNotContainsString('_', $reason);
        $this->assertStringContainsString('not established', $reason);
        // Actionable: it must say what the admin has to do instead.
        $this->assertStringContainsString('re-enrol', $reason);

        // The stable key stays available for anything that needs to branch.
        $this->assertSame('face_write_back_not_established', $row['not_restorable_code']);
    }

    public function test_palm_templates_get_their_own_reason_rather_than_the_face_one(): void
    {
        $source = $this->device();
        $target = $this->device();

        $alice = User::factory()->create(['employee_id' => 5601]);
        $this->template($alice, $source, ['template_type' => 'palm']);

        $row = $this->service()->listTemplates()->sole();
        $this->assertFalse($row['restorable']);
        $this->assertSame(TemplateRoamingService::OTHER_MODALITY_REASON, $row['not_restorable_code']);
        $this->assertNotSame(TemplateRoamingService::FACE_REASON_MESSAGE, $row['not_restorable_reason']);

        // And the restore skip breakdown separates them too, so "why did nothing
        // queue" answers per modality.
        $result = $this->service()->restoreTemplatesToDevice($target);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['reasons'][TemplateRoamingService::OTHER_MODALITY_REASON]);
        $this->assertArrayNotHasKey(TemplateRoamingService::FACE_REASON, $result['reasons']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Multi-finger capture — the defect these tests exist for
    // ──────────────────────────────────────────────────────────────

    public function test_two_fingers_for_one_user_are_stored_as_two_rows(): void
    {
        $device = $this->device(['serial_number' => 'AF6P231260266']);
        $alice = User::factory()->create(['employee_id' => 1001]);

        // Exactly the shape a device pushes a two-finger enrolment in: two
        // records in one body. The old parser stored ONE row — its TMP class
        // matched letters, digits, '=' and whitespace, so it swallowed the second
        // record whole — and its write key had no finger in it, so the second
        // finger would have overwritten the first anyway.
        $result = $this->capture()->processTemplateUpload(
            $this->fingerPush('1001', 0, 'QUFB')."\n".$this->fingerPush('1001', 3, 'QkJC')."\n",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertTrue($result['success']);
        $this->assertSame('saved', $result['reason']);
        $this->assertSame(2, $result['stored']);

        $rows = DB::table('biometric_templates')
            ->where('device_user_id', '1001')
            ->orderBy('finger_index')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame([0, 3], $rows->pluck('finger_index')->map('intval')->all());
        // Each slot holds its OWN template, not the concatenation of both.
        $this->assertSame(['QUFB', 'QkJC'], $rows->pluck('template_data')->all());
        $this->assertSame([(string) $alice->id, (string) $alice->id], $rows->pluck('user_id')->map('strval')->all());
    }

    public function test_two_fingers_restore_as_two_commands_with_distinct_fids(): void
    {
        $source = $this->device(['serial_number' => 'AF6P231260266']);
        $target = $this->device(['name' => 'Replacement MB460']);

        User::factory()->create(['employee_id' => 1001]);

        $this->capture()->processTemplateUpload(
            $this->fingerPush('1001', 0, 'QUFB')."\n".$this->fingerPush('1001', 3, 'QkJC')."\n",
            'templatev10',
            $source->serial_number,
            $source
        );

        $result = $this->service()->restoreTemplatesToDevice($target);

        // One command per FINGER. This is the number that used to be 1.
        $this->assertSame(2, $result['queued']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(1, $result['users']);

        $commands = BiometricDeviceCommand::where('biometric_device_id', $target->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $commands);
        $this->assertSame([0, 3], $commands->pluck('payload.fid')->all());

        // And the FID that reaches the wire is the real one, not the fallback.
        $this->assertSame(
            "C:{$commands[0]->id}:DATA UPDATE FINGERTMP PIN=1001\tFID=0\tSize=4\tValid=1\tTMP=QUFB",
            $commands[0]->toAdmsString()
        );
        $this->assertSame(
            "C:{$commands[1]->id}:DATA UPDATE FINGERTMP PIN=1001\tFID=3\tSize=4\tValid=1\tTMP=QkJC",
            $commands[1]->toAdmsString()
        );

        // Still tab-separated, per command. The separator is the one detail whose
        // failure mode is silent (Return=0 for an enrolment that never landed).
        foreach ($commands as $command) {
            $this->assertSame(4, substr_count($command->toAdmsString(), "\t"));
        }
    }

    public function test_re_pushing_the_same_finger_updates_that_slot_and_adds_nothing(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 1002]);

        $capture = $this->capture();

        $capture->processTemplateUpload(
            $this->fingerPush('1002', 1, 'T0xE'),
            'templatev10',
            $device->serial_number,
            $device
        );
        $capture->processTemplateUpload(
            $this->fingerPush('1002', 1, 'TkVX'),
            'templatev10',
            $device->serial_number,
            $device
        );

        $rows = DB::table('biometric_templates')->where('device_user_id', '1002')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('TkVX', $rows->first()->template_data);
        $this->assertSame(1, (int) $rows->first()->finger_index);
    }

    public function test_a_push_with_no_fid_still_stores_a_template(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 1003]);

        // Not every firmware sends FID. Such a device must still get its template
        // stored — the alternative is an ADMS unit that retries a rejected push
        // forever — and it lands in the slot it has always restored into.
        $result = $this->capture()->processTemplateUpload(
            $this->fingerPush('1003', null, 'QUFB'),
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['stored']);

        $row = DB::table('biometric_templates')->where('device_user_id', '1003')->sole();
        $this->assertSame(TemplateRoamingService::FALLBACK_FINGER_INDEX, (int) $row->finger_index);
        $this->assertSame('QUFB', $row->template_data);
    }

    public function test_a_face_template_never_collides_with_finger_zero(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 1004]);

        $capture = $this->capture();

        $capture->processTemplateUpload(
            $this->fingerPush('1004', 0, 'RklOR0VS'),
            'templatev10',
            $device->serial_number,
            $device
        );
        $capture->processTemplateUpload(
            "USERID=1004\tSize=8\tValid=1\tTMP=RkFDRQ==",
            'facetmpv10',
            $device->serial_number,
            $device
        );

        $rows = DB::table('biometric_templates')
            ->where('device_user_id', '1004')
            ->orderBy('template_type')
            ->get();

        // Two rows, and the face carries the sentinel rather than slot 0 — the
        // device addresses a template by PIN + FID with no modality in the
        // address, so a face sitting in slot 0 is a face sitting on a thumb.
        $this->assertCount(2, $rows);
        $this->assertSame(['face', 'fingerprint'], $rows->pluck('template_type')->all());
        $this->assertSame(
            [TemplateRoamingService::NO_FINGER_INDEX, 0],
            $rows->pluck('finger_index')->map('intval')->all()
        );
        $this->assertSame('RkFDRQ==', $rows->firstWhere('template_type', 'face')->template_data);

        // And the sentinel stays out of the UI: the read model says "no finger".
        $face = $this->service()->listTemplates()->firstWhere('template_type', 'face');
        $this->assertNull($face['finger_index']);
    }

    public function test_capture_does_not_assume_field_order(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 1005]);

        // FID before USERID. The old pattern required USERID first and would have
        // rejected this outright; real firmware is not consistent about ordering.
        $result = $this->capture()->processTemplateUpload(
            "FID=7\tUSERID=1005\tValid=1\tSize=4\tTMP=QUFB",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertTrue($result['success']);

        $row = DB::table('biometric_templates')->where('device_user_id', '1005')->sole();
        $this->assertSame(7, (int) $row->finger_index);
        $this->assertSame('QUFB', $row->template_data);
    }

    public function test_a_push_with_no_template_at_all_is_still_rejected_as_malformed(): void
    {
        $device = $this->device();
        User::factory()->create(['employee_id' => 1006]);

        $result = $this->capture()->processTemplateUpload(
            "USERID=1006\tFID=0\tSize=4\tValid=1",
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_format', $result['reason']);
        $this->assertSame(0, DB::table('biometric_templates')->count());
    }

    public function test_an_unknown_pin_is_answered_ok_and_stores_nothing(): void
    {
        $device = $this->device();

        // No user carries employee_id 9999. The device is behaving correctly, so
        // it must not be made to retry a push we will never accept.
        $result = $this->capture()->processTemplateUpload(
            $this->fingerPush('9999', 2, 'QUFB'),
            'templatev10',
            $device->serial_number,
            $device
        );

        $this->assertTrue($result['success']);
        $this->assertSame('no_user', $result['reason']);
        $this->assertSame(0, DB::table('biometric_templates')->count());
    }

    public function test_the_finger_slot_is_unique_at_the_database_level(): void
    {
        $device = $this->device();
        $alice = User::factory()->create(['employee_id' => 1007]);

        $this->template($alice, $device, ['finger_index' => 2]);

        // A different finger for the same person on the same device is fine.
        $this->template($alice, $device, ['finger_index' => 3]);
        $this->assertSame(2, DB::table('biometric_templates')->count());

        // The same finger twice is not — and it is the DATABASE that says so, not
        // an application check that a second writer could bypass.
        $this->expectException(QueryException::class);
        $this->template($alice, $device, ['finger_index' => 2]);
    }

    // ──────────────────────────────────────────────────────────────
    //  The migration, against rows that already exist
    // ──────────────────────────────────────────────────────────────

    public function test_the_migration_preserves_existing_rows_and_archives_collisions(): void
    {
        $device = $this->device();
        $alice = User::factory()->create(['employee_id' => 2101]);
        $bob = User::factory()->create(['employee_id' => 2102]);

        $migration = $this->fingerSlotMigration();

        // Back to the pre-fix schema: finger_index nullable, no slot constraint.
        $migration->down();
        $this->assertFalse(Schema::hasTable('biometric_template_duplicates'));

        // Rows exactly as capture used to write them: no finger index at all.
        $aliceRow = $this->template($alice, $device, ['finger_index' => null, 'template_data' => 'QUxJQ0U=']);
        $faceRow = $this->template($bob, $device, [
            'template_type' => 'face',
            'finger_index' => null,
            'template_data' => 'RkFDRQ==',
        ]);
        // Two rows for one person/device/modality. The old logical key was
        // enforced by an updateOrInsert with no constraint behind it, so a race
        // could always produce this — and canonicalising is what makes them
        // collide.
        $staleRow = $this->template($bob, $device, [
            'template_data' => 'T0xE',
            'updated_at' => now()->subDay(),
        ]);
        $freshRow = $this->template($bob, $device, [
            'template_data' => 'TkVX',
            'updated_at' => now(),
        ]);

        $migration->up();

        // Nothing was destroyed. Alice's template — a real captured enrolment we
        // have no finger index for — is still here, in the slot it already
        // restored into.
        $alice = DB::table('biometric_templates')->find($aliceRow);
        $this->assertNotNull($alice);
        $this->assertSame('QUxJQ0U=', $alice->template_data);
        $this->assertSame(TemplateRoamingService::FALLBACK_FINGER_INDEX, (int) $alice->finger_index);

        // The face row keeps its template and gains the sentinel, so it cannot
        // share a slot with finger 0.
        $face = DB::table('biometric_templates')->find($faceRow);
        $this->assertSame('RkFDRQ==', $face->template_data);
        $this->assertSame(TemplateRoamingService::NO_FINGER_INDEX, (int) $face->finger_index);

        // The collision was collapsed to the FRESHEST row...
        $this->assertNull(DB::table('biometric_templates')->find($staleRow));
        $this->assertSame('TkVX', DB::table('biometric_templates')->find($freshRow)->template_data);

        // ...and the loser was archived in full, not dropped. A template is the
        // only copy of somebody's enrolment we hold.
        $archived = DB::table('biometric_template_duplicates')->sole();
        $this->assertSame($staleRow, (int) $archived->source_id);
        $this->assertSame($freshRow, (int) $archived->kept_template_id);
        $this->assertSame('T0xE', json_decode($archived->payload, true)['template_data']);
    }

    public function test_the_migration_rolls_back_and_puts_archived_rows_back(): void
    {
        $device = $this->device();
        $bob = User::factory()->create(['employee_id' => 2103]);

        $migration = $this->fingerSlotMigration();
        $migration->down();

        $stale = $this->template($bob, $device, ['template_data' => 'T0xE', 'updated_at' => now()->subDay()]);
        $fresh = $this->template($bob, $device, ['template_data' => 'TkVX', 'updated_at' => now()]);
        $face = $this->template($bob, $device, [
            'template_type' => 'face',
            'finger_index' => null,
            'template_data' => 'RkFDRQ==',
        ]);

        $migration->up();
        $this->assertSame(2, DB::table('biometric_templates')->count());

        $migration->down();

        // Every archived row is back, the archive table is gone, and the slot
        // constraint no longer exists.
        $this->assertSame(3, DB::table('biometric_templates')->count());
        $this->assertSame('T0xE', DB::table('biometric_templates')->find($stale)->template_data);
        $this->assertSame('TkVX', DB::table('biometric_templates')->find($fresh)->template_data);
        $this->assertFalse(Schema::hasTable('biometric_template_duplicates'));
        $this->assertFalse(Schema::hasIndex('biometric_templates', 'biometric_templates_finger_slot_unique'));

        // The face sentinel reverts to NULL — nothing but this migration can have
        // written -1, so it is unambiguous.
        $this->assertNull(DB::table('biometric_templates')->find($face)->finger_index);

        // Put the schema back for the next test's teardown.
        $migration->up();
    }

    public function test_delete_refuses_a_finger_index_outside_the_protocol_range(): void
    {
        $service = $this->service();
        $device = $this->device();

        foreach ([TemplateRoamingService::NO_FINGER_INDEX, -1, 10, 99] as $fid) {
            try {
                $service->deleteTemplateFromDevice($device, '1', $fid);
                $this->fail("FID {$fid} must be refused.");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('A finger index must be between 0 and 9.', $e->getMessage());
            }
        }

        // A real finger, and "all fingers", both still work.
        $this->assertNotNull($service->deleteTemplateFromDevice($device, '1', 9));
        $this->assertNotNull($service->deleteTemplateFromDevice($device, '1'));
    }
}
