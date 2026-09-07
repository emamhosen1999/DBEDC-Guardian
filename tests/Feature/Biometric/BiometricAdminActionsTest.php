<?php

namespace Tests\Feature\Biometric;

use App\Models\HRM\BiometricAttLog;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\User;
use App\Notifications\Biometric\BiometricDeviceSilentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Three administrator-facing gaps in the biometric module, none of which had
 * any server-side surface before:
 *
 *  1. Template roaming. Templates were captured and stored and could never be
 *     read back or written to a second reader. The restore is the only action
 *     in this controller that puts biometric data onto hardware, so the tests
 *     here pin the two refusals that stand in front of it — an unconfirmed
 *     request, and a device whose protocol has no command queue to write to.
 *
 *  2. Unknown-user remediation. A punch from a PIN nobody carries parks a row
 *     as punch_status='unknown_user' behind a soft-deleted placeholder, and
 *     nothing ever revisited that state. Linking the PIN is an identity
 *     re-keying, so the refusals matter more than the happy path: a target who
 *     already has a different employee_id, and a PIN that is not actually
 *     stranded.
 *
 *  3. Device-silence alerting. ADMS is device-initiated — if the terminal stops
 *     pushing, nothing noticed until attendance turned up empty. The alert has
 *     to fire on the transition into silence and then stay quiet, because
 *     production runs schedule:run every five minutes.
 */
class BiometricAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');

        return $admin;
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

    /**
     * A stranded punch: captured, attributed to the soft-deleted placeholder
     * resolveOrCreateUser() minted, and parked in unknown_user.
     */
    private function unknownUserLog(BiometricDevice $device, string $pin, string $punchTime, ?string $userId = null): BiometricAttLog
    {
        return BiometricAttLog::create([
            'biometric_device_id' => $device->id,
            'serial_number' => $device->serial_number,
            'user_pin' => $pin,
            'user_id' => $userId,
            'punch_time' => $punchTime,
            'check_type' => 'in',
            'punch_status' => 'unknown_user',
            'punch_status_reason' => 'Auto-created as inactive placeholder',
            'occurred_at' => $punchTime,
        ]);
    }

    /**
     * The exact shape BiometricProcessingService::resolveOrCreateUser() writes
     * for an unrecognised PIN: a real row, holding the PIN, then soft-deleted.
     */
    private function placeholder(string $pin): User
    {
        $user = User::create([
            'name' => 'Device User '.$pin,
            'email' => 'device_user_'.$pin.'@placeholder.local',
            'user_name' => 'device_user_'.$pin,
            'password' => bcrypt('secret-placeholder'),
            'employee_id' => $pin,
            'active' => false,
        ]);
        $user->delete();

        return $user;
    }

    // ───────────────────────────── Task 1: template roaming

    public function test_restore_templates_requires_explicit_confirmation(): void
    {
        $device = $this->device();

        $response = $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.restore-templates', $device->id), [
                'user_ids' => [1, 2],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'requires_confirmation' => true,
                'confirmation_field' => 'confirm_restore',
            ]);

        // Nothing was queued: an unconfirmed restore must not reach the device.
        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_restore_templates_refuses_a_non_adms_device(): void
    {
        // Confirmed on purpose — the protocol refusal must not depend on the
        // confirmation refusal happening to fire first.
        $device = $this->device(['protocol' => 'push_sdk']);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.restore-templates', $device->id), [
                'confirm_restore' => true,
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Template restore is only supported for ADMS protocol devices.');

        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_restore_templates_rejects_non_integer_user_ids(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.restore-templates', $device->id), [
                'confirm_restore' => true,
                'user_ids' => ['not-an-id'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids.0');
    }

    public function test_restore_templates_404s_for_an_unknown_device(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.restore-templates', 999999), ['confirm_restore' => true])
            ->assertNotFound();
    }

    public function test_template_endpoints_are_behind_the_attendance_settings_gate(): void
    {
        $outsider = User::factory()->create();
        $device = $this->device();

        $this->actingAs($outsider)
            ->postJson(route('biometric-devices.restore-templates', $device->id), ['confirm_restore' => true])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->getJson(route('biometric-devices.templates'))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson(route('biometric-devices.attlogs.link-user'), ['pin' => '5001', 'user_id' => 1])
            ->assertForbidden();
    }

    // ───────────────────────────── Task 1b: on-device template delete
    //
    // The mirror of the restore, and the destructive half. It removes
    // fingerprint(s) from the TERMINAL and leaves biometric_templates alone, so
    // the tests below pin both the refusals in front of it and the distinction
    // the wire format turns on: an omitted FID means every finger for that PIN.

    public function test_delete_template_requires_explicit_confirmation(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
                'fid' => 3,
            ])
            ->assertStatus(422)
            ->assertJson([
                'requires_confirmation' => true,
                'confirmation_field' => 'confirm_delete',
                'scope' => 'single_finger',
                'all_fingers' => false,
            ]);

        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_delete_template_confirmation_names_the_all_fingers_scope(): void
    {
        // Omitting FID is a materially bigger action than deleting one finger.
        // The refusal must not read identically for both.
        $device = $this->device();

        $response = $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('scope', 'all_fingers')
            ->assertJsonPath('all_fingers', true)
            ->assertJsonPath('fid', null);

        $this->assertStringContainsString('EVERY fingerprint', $response->json('message'));
        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_delete_template_queues_a_single_finger_and_keeps_our_stored_copy(): void
    {
        $device = $this->device();

        $response = $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
                'fid' => 3,
                'confirm_delete' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('scope', 'single_finger')
            ->assertJsonPath('all_fingers', false)
            ->assertJsonPath('fid', 3)
            ->assertJsonPath('pin', '9001')
            ->assertJsonPath('asynchronous', true)
            ->assertJsonPath('stored_copy_retained', true);

        $command = BiometricDeviceCommand::findOrFail($response->json('command_id'));

        $this->assertSame('DELETE_FINGERTMP', $command->command_type);
        $this->assertSame($device->id, $command->biometric_device_id);
        $this->assertSame(BiometricDeviceCommand::STATUS_PENDING, $command->status);
        // FID present on the wire, tab-separated: this addresses one slot.
        $this->assertSame("C:{$command->id}:DATA DELETE FINGERTMP PIN=9001\tFID=3", $command->toAdmsString());
    }

    public function test_delete_template_without_a_fid_deletes_every_finger_and_says_so(): void
    {
        $device = $this->device();

        $response = $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
                'confirm_delete' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('scope', 'all_fingers')
            ->assertJsonPath('all_fingers', true)
            ->assertJsonPath('fid', null);

        $this->assertStringContainsString('ALL fingerprints', $response->json('message'));

        $command = BiometricDeviceCommand::findOrFail($response->json('command_id'));

        // FID is OMITTED, not sent empty and not sent as 0 — `FID=` is a syntax
        // error and `FID=0` would silently delete only the first finger when the
        // caller asked for all of them.
        $this->assertSame("C:{$command->id}:DATA DELETE FINGERTMP PIN=9001", $command->toAdmsString());
        $this->assertArrayNotHasKey('fid', $command->payload);
    }

    public function test_delete_template_rejects_a_finger_index_outside_the_protocol_range(): void
    {
        $device = $this->device();
        $admin = $this->admin();

        // -1 is TemplateRoamingService::NO_FINGER_INDEX, the face/palm storage
        // sentinel. It must never reach the wire as FID=-1.
        foreach ([-1, 10, 99] as $fid) {
            $this->actingAs($admin)
                ->postJson(route('biometric-devices.delete-template', $device->id), [
                    'pin' => '9001',
                    'fid' => $fid,
                    'confirm_delete' => true,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('fid');
        }

        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_delete_template_requires_a_pin(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'confirm_delete' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_delete_template_refuses_a_non_adms_device(): void
    {
        // Confirmed on purpose — the protocol refusal must not depend on the
        // confirmation refusal happening to fire first.
        $device = $this->device(['protocol' => 'push_sdk']);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
                'confirm_delete' => true,
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Template delete is only supported for ADMS protocol devices.');

        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_delete_template_refuses_an_inactive_device(): void
    {
        $device = $this->device(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
                'confirm_delete' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Device is inactive.');

        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_delete_template_404s_for_an_unknown_device(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', 999999), [
                'pin' => '9001',
                'confirm_delete' => true,
            ])
            ->assertNotFound();
    }

    public function test_delete_template_is_behind_the_attendance_settings_gate(): void
    {
        $device = $this->device();

        $this->actingAs(User::factory()->create())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
                'confirm_delete' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('biometric_device_commands', 0);
    }

    public function test_delete_template_does_not_remove_our_stored_copy(): void
    {
        // The whole asymmetry of this feature: the device loses the template,
        // we keep ours, which is what makes a later restore possible.
        $device = $this->device();

        DB::table('biometric_templates')->insert([
            'biometric_device_id' => $device->id,
            'user_id' => User::factory()->create(['employee_id' => 9001])->id,
            'device_user_id' => '9001',
            'template_type' => 'fingerprint',
            'finger_index' => 3,
            'template_data' => 'QUJDREVGRw==',
            'template_size' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.delete-template', $device->id), [
                'pin' => '9001',
                'fid' => 3,
                'confirm_delete' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('biometric_templates', [
            'biometric_device_id' => $device->id,
            'device_user_id' => '9001',
            'finger_index' => 3,
        ]);
    }

    // ───────────────────────────── Task 2: unknown-user remediation

    public function test_link_user_relinks_stranded_rows_and_claims_the_pin(): void
    {
        $device = $this->device();
        $placeholder = $this->placeholder('7788');

        $first = $this->unknownUserLog($device, '7788', '2026-06-19 08:00:00', $placeholder->id);
        $second = $this->unknownUserLog($device, '7788', '2026-06-19 17:30:00', $placeholder->id);
        // A different PIN, equally stranded — it must not be touched.
        $other = $this->unknownUserLog($device, '9999', '2026-06-19 08:05:00');

        $employee = User::factory()->create(['employee_id' => null, 'name' => 'Rafiq Islam']);

        $response = $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.attlogs.link-user'), [
                'pin' => '7788',
                'user_id' => $employee->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('logs_relinked', 2)
            ->assertJsonPath('placeholders_released', 1)
            ->assertJsonPath('user_id', $employee->id)
            ->assertJsonPath('punch_status', 'downloaded');

        // 1. Future punches resolve: the real employee now carries the PIN.
        $this->assertSame('7788', (string) $employee->fresh()->employee_id);

        // 2. The placeholder released it, otherwise resolveOrCreateUser() would
        //    keep matching the soft-deleted row and the fix would be a no-op.
        $this->assertNull(User::withTrashed()->find($placeholder->id)->employee_id);

        // 3. The stranded rows point at the employee and are back in the state
        //    importDownloadedLogs() selects on.
        foreach ([$first, $second] as $log) {
            $log->refresh();
            $this->assertSame($employee->id, $log->user_id);
            $this->assertSame('downloaded', $log->punch_status);
        }

        // The unrelated PIN is untouched.
        $other->refresh();
        $this->assertSame('unknown_user', $other->punch_status);
        $this->assertNull($other->user_id);
    }

    public function test_link_user_refuses_a_user_who_already_has_a_different_employee_id(): void
    {
        $device = $this->device();
        $this->placeholder('7788');
        $log = $this->unknownUserLog($device, '7788', '2026-06-19 08:00:00');

        $employee = User::factory()->create(['employee_id' => 4242]);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.attlogs.link-user'), [
                'pin' => '7788',
                'user_id' => $employee->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('current_employee_id', '4242')
            ->assertJsonPath('requested_pin', '7788');

        // Nothing moved: the employee keeps their ID and the punch stays parked.
        $this->assertSame(4242, (int) $employee->fresh()->employee_id);
        $this->assertSame('unknown_user', $log->fresh()->punch_status);
    }

    public function test_link_user_refuses_a_pin_that_is_not_in_an_unresolved_state(): void
    {
        $device = $this->device();

        // Already resolved — imported into attendance under a real user.
        BiometricAttLog::create([
            'biometric_device_id' => $device->id,
            'serial_number' => $device->serial_number,
            'user_pin' => '5150',
            'user_id' => User::factory()->create(['employee_id' => 5150])->id,
            'punch_time' => '2026-06-19 08:00:00',
            'check_type' => 'in',
            'punch_status' => 'processed',
            'occurred_at' => '2026-06-19 08:00:00',
        ]);

        $target = User::factory()->create(['employee_id' => null]);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.attlogs.link-user'), [
                'pin' => '5150',
                'user_id' => $target->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('unresolved_count', 0);

        // The target must not have been given the PIN on the way to the refusal.
        $this->assertNull($target->fresh()->employee_id);
    }

    public function test_link_user_refuses_a_pin_held_by_a_live_user(): void
    {
        $device = $this->device();
        $this->unknownUserLog($device, '3131', '2026-06-19 08:00:00');

        $incumbent = User::factory()->create(['employee_id' => 3131]);
        $target = User::factory()->create(['employee_id' => null]);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.attlogs.link-user'), [
                'pin' => '3131',
                'user_id' => $target->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('conflicting_user_id', $incumbent->id);

        $this->assertNull($target->fresh()->employee_id);
        $this->assertSame(3131, (int) $incumbent->fresh()->employee_id);
    }

    public function test_link_user_refuses_a_pin_held_by_a_deleted_real_employee(): void
    {
        // Not one of our placeholders — a genuine ex-employee. Reassigning the
        // PIN would move their attendance history onto someone else.
        $device = $this->device();
        $this->unknownUserLog($device, '2020', '2026-06-19 08:00:00');

        $exEmployee = User::factory()->create(['employee_id' => 2020, 'name' => 'Former Staff']);
        $exEmployee->delete();

        $target = User::factory()->create(['employee_id' => null]);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.attlogs.link-user'), [
                'pin' => '2020',
                'user_id' => $target->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('conflicting_user_id', $exEmployee->id);

        $this->assertNull($target->fresh()->employee_id);
        $this->assertSame(2020, (int) User::withTrashed()->find($exEmployee->id)->employee_id);
    }

    public function test_link_user_is_idempotent_for_a_user_who_already_carries_the_pin(): void
    {
        // Re-running after a partial fix (employee already has the PIN, rows
        // still stranded) must complete the job, not refuse it.
        $device = $this->device();
        $log = $this->unknownUserLog($device, '6060', '2026-06-19 08:00:00');
        $employee = User::factory()->create(['employee_id' => 6060]);

        $this->actingAs($this->admin())
            ->postJson(route('biometric-devices.attlogs.link-user'), [
                'pin' => '6060',
                'user_id' => $employee->id,
            ])
            ->assertOk()
            ->assertJsonPath('logs_relinked', 1);

        $log->refresh();
        $this->assertSame('downloaded', $log->punch_status);
        $this->assertSame($employee->id, $log->user_id);
    }

    // ───────────────────────────── Task 3: device-silence alerting

    private function silentDevice(int $minutesAgo): BiometricDevice
    {
        return $this->device([
            'name' => 'Silent MB460',
            'last_heartbeat_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_health_alert_fires_for_a_device_that_has_gone_silent(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $this->silentDevice(90);

        Artisan::call('biometric:device-health-alert');

        Notification::assertSentTo($admin, BiometricDeviceSilentNotification::class);
        $this->assertStringContainsString('1 device(s) silent', Artisan::output());
    }

    public function test_health_alert_stays_quiet_for_a_device_that_is_still_pushing(): void
    {
        Notification::fake();

        $this->admin();
        $this->device([
            'name' => 'Healthy MB460',
            'last_heartbeat_at' => now()->subMinutes(2),
        ]);

        Artisan::call('biometric:device-health-alert');

        Notification::assertNothingSent();
        $this->assertStringContainsString('All active devices have checked in', Artisan::output());
    }

    public function test_health_alert_ignores_a_device_inside_the_threshold_but_outside_the_online_badge(): void
    {
        // 20 minutes is "offline" to isOnline()'s 5-minute badge and must NOT
        // page anybody — that gap is the entire reason the alert has its own,
        // longer threshold.
        Notification::fake();

        $this->admin();
        $this->silentDevice(20);

        Artisan::call('biometric:device-health-alert');

        Notification::assertNothingSent();
    }

    public function test_health_alert_ignores_inactive_devices(): void
    {
        Notification::fake();

        $this->admin();
        $this->device([
            'name' => 'Decommissioned',
            'is_active' => false,
            'last_heartbeat_at' => now()->subDays(30),
        ]);

        Artisan::call('biometric:device-health-alert');

        Notification::assertNothingSent();
    }

    public function test_health_alert_does_not_re_alert_on_consecutive_runs(): void
    {
        // Production cron fires schedule:run every five minutes. Without the
        // transition marker a night-long outage would be 96 notifications.
        Notification::fake();

        $admin = $this->admin();
        $this->silentDevice(90);

        Artisan::call('biometric:device-health-alert');
        Artisan::call('biometric:device-health-alert');
        Artisan::call('biometric:device-health-alert');

        Notification::assertSentToTimes($admin, BiometricDeviceSilentNotification::class, 1);
        $this->assertStringContainsString('1 already-alerted device(s) suppressed', Artisan::output());
    }

    public function test_health_alert_re_arms_after_the_device_recovers(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $device = $this->silentDevice(90);

        Artisan::call('biometric:device-health-alert');
        Notification::assertSentToTimes($admin, BiometricDeviceSilentNotification::class, 1);

        // Device comes back...
        $device->update(['last_heartbeat_at' => now()]);
        Artisan::call('biometric:device-health-alert');
        Notification::assertSentToTimes($admin, BiometricDeviceSilentNotification::class, 1);

        // ...then dies again. That is a new incident and must alert again.
        $device->update(['last_heartbeat_at' => now()->subMinutes(90)]);
        Artisan::call('biometric:device-health-alert');
        Notification::assertSentToTimes($admin, BiometricDeviceSilentNotification::class, 2);
    }

    public function test_health_alert_does_not_page_for_a_freshly_registered_device(): void
    {
        // Never heard from, but only just created: it has not failed, it has
        // not been plugged in yet.
        Notification::fake();

        $this->admin();
        $this->device(['name' => 'Just Registered', 'last_heartbeat_at' => null]);

        Artisan::call('biometric:device-health-alert');

        Notification::assertNothingSent();
    }

    public function test_health_alert_pages_for_a_device_that_never_checked_in(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $device = $this->device(['name' => 'Never Seen', 'last_heartbeat_at' => null]);
        $device->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        Artisan::call('biometric:device-health-alert');

        Notification::assertSentTo($admin, BiometricDeviceSilentNotification::class);
    }

    public function test_health_alert_threshold_is_configurable(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $this->silentDevice(20);

        Artisan::call('biometric:device-health-alert', ['--minutes' => 10]);

        Notification::assertSentTo($admin, BiometricDeviceSilentNotification::class);
    }

    public function test_health_alert_dry_run_sends_nothing_and_leaves_the_marker_unclaimed(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $this->silentDevice(90);

        Artisan::call('biometric:device-health-alert', ['--dry-run' => true]);
        Notification::assertNothingSent();

        // The dry run must not have consumed the transition marker.
        Artisan::call('biometric:device-health-alert');
        Notification::assertSentToTimes($admin, BiometricDeviceSilentNotification::class, 1);
    }

    public function test_health_alert_still_pages_when_the_cache_store_cannot_hold_a_marker(): void
    {
        // This deployment ships CACHE_STORE=null (.env and .env.example), which
        // resolves to NullStore: put() always returns false, so Cache::add()
        // always returns false. Read naively as "somebody already claimed this
        // marker", that suppresses EVERY alert this command exists to send —
        // permanently, and looking exactly like a healthy fleet.
        //
        // The command must therefore fail towards noise, not towards silence:
        // alert on every run and say why de-duplication is off.
        Notification::fake();
        config(['cache.default' => 'null']);

        $admin = $this->admin();
        $this->silentDevice(90);

        Artisan::call('biometric:device-health-alert');
        Artisan::call('biometric:device-health-alert');

        Notification::assertSentToTimes($admin, BiometricDeviceSilentNotification::class, 2);
        $this->assertStringContainsString('cannot persist the transition marker', Artisan::output());
    }

    // ───────────────────────────── Routing: the bulk/{id} collision

    public function test_new_routes_do_not_collide_with_the_id_routes(): void
    {
        // An unconstrained {id} also matches the literal segments "bulk",
        // "templates" and "attlogs". Every {id} route in the biometric group
        // carries ->whereNumber('id') for exactly this reason.
        $expected = [
            ['GET', 'settings/biometric-devices/templates', 'biometric-devices.templates', []],
            ['POST', 'settings/biometric-devices/7/restore-templates', 'biometric-devices.restore-templates', ['id' => '7']],
            ['POST', 'settings/biometric-devices/7/delete-template', 'biometric-devices.delete-template', ['id' => '7']],
            ['POST', 'settings/biometric-devices/attlogs/link-user', 'biometric-devices.attlogs.link-user', []],
            ['GET', 'settings/biometric-devices/attlogs', 'biometric-devices.attlogs', []],
            ['POST', 'settings/biometric-devices/bulk/ping', 'biometric-devices.bulk.ping', []],
            ['POST', 'settings/biometric-devices/bulk/delete', 'biometric-devices.bulk.delete', []],
            ['POST', 'settings/biometric-devices/bulk/download-logs', 'biometric-devices.bulk.download-logs', []],
        ];

        foreach ($expected as [$method, $uri, $name, $parameters]) {
            $route = app('router')->getRoutes()->match(Request::create('/'.$uri, $method));

            $this->assertSame($name, $route->getName(), "{$method} {$uri} resolved to the wrong route");
            $this->assertSame($parameters, $route->parameters(), "{$method} {$uri} bound the wrong parameters");
        }
    }
}
