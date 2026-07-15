<?php

namespace Tests\Feature\Api;

use App\Models\HRM\BiometricDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security coverage for the ZKTeco ADMS (/iclock/*) push endpoints.
 *
 * Proves the EnsureAdmsDeviceAuthorized middleware:
 *   - always rejects unregistered / inactive serials (allowlist),
 *   - still ingests punches for a registered active device (no regression),
 *   - in strict mode rejects a bad token but passes a good token AND a device
 *     with no secret configured,
 *   - in observe mode logs-but-allows a bad token, and
 *   - locks down device-initiated user enrollment (table=USERINFO).
 */
class AdmsDeviceAuthTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $overrides = []): BiometricDevice
    {
        return BiometricDevice::create(array_merge([
            'name' => 'Gate MB460',
            'serial_number' => 'SN-'.uniqid(),
            'protocol' => 'adms',
            'is_active' => true,
        ], $overrides));
    }

    private function pushAttlog(string $serial, string $query = '', string $body = "42\t2026-07-15 09:00:00\t0")
    {
        $uri = '/iclock/cdata?SN='.rawurlencode($serial).'&table=ATTLOG'.$query;

        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_unregistered_serial_is_rejected(): void
    {
        $response = $this->pushAttlog('SN-DOES-NOT-EXIST');

        $response->assertStatus(401);
        $this->assertSame('ERROR', $response->getContent());
        $this->assertDatabaseMissing('biometric_att_logs', ['serial_number' => 'SN-DOES-NOT-EXIST']);
    }

    public function test_inactive_device_is_rejected(): void
    {
        $device = $this->device(['is_active' => false]);

        $response = $this->pushAttlog($device->serial_number);

        $response->assertStatus(401);
        $this->assertSame('ERROR', $response->getContent());
        $this->assertDatabaseMissing('biometric_att_logs', ['serial_number' => $device->serial_number]);
    }

    public function test_registered_active_device_still_ingests_a_punch(): void
    {
        // A resolvable employee so the punch is attributed rather than auto-created.
        User::factory()->create(['employee_id' => '42']);
        $device = $this->device(); // no adms_token => allowlist-only

        $response = $this->pushAttlog($device->serial_number);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertDatabaseHas('biometric_att_logs', [
            'serial_number' => $device->serial_number,
            'user_pin' => '42',
        ]);
    }

    public function test_strict_mode_rejects_bad_token_but_passes_good_token(): void
    {
        config(['attendance.adms_strict_auth' => true]);

        User::factory()->create(['employee_id' => '42']);
        $device = $this->device(['adms_token' => 'super-secret-token']);

        // Bad token -> rejected.
        $bad = $this->pushAttlog($device->serial_number, '&token=wrong');
        $bad->assertStatus(401);
        $this->assertSame('ERROR', $bad->getContent());
        $this->assertDatabaseMissing('biometric_att_logs', ['serial_number' => $device->serial_number]);

        // Correct token -> ingested.
        $good = $this->pushAttlog($device->serial_number, '&token=super-secret-token');
        $good->assertOk();
        $this->assertSame('OK', $good->getContent());
        $this->assertDatabaseHas('biometric_att_logs', [
            'serial_number' => $device->serial_number,
            'user_pin' => '42',
        ]);
    }

    public function test_strict_mode_passes_device_with_no_secret_configured(): void
    {
        // Backward-compat: a live device that has no adms_token keeps working
        // even after strict mode is switched on.
        config(['attendance.adms_strict_auth' => true]);

        User::factory()->create(['employee_id' => '77']);
        $device = $this->device(); // adms_token is null

        $response = $this->pushAttlog($device->serial_number, '', "77\t2026-07-15 10:00:00\t0");

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertDatabaseHas('biometric_att_logs', [
            'serial_number' => $device->serial_number,
            'user_pin' => '77',
        ]);
    }

    public function test_observe_mode_logs_but_allows_bad_token(): void
    {
        // Default observe mode (strict = false): a device WITH a secret that
        // presents a wrong token is logged but still processed, so the fix can
        // be deployed to live hardware before enforcement is turned on.
        config(['attendance.adms_strict_auth' => false]);

        User::factory()->create(['employee_id' => '42']);
        $device = $this->device(['adms_token' => 'super-secret-token']);

        $response = $this->pushAttlog($device->serial_number, '&token=wrong');

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertDatabaseHas('biometric_att_logs', [
            'serial_number' => $device->serial_number,
            'user_pin' => '42',
        ]);
    }

    public function test_userinfo_enrollment_is_blocked_without_a_valid_token(): void
    {
        // Even in observe mode, USERINFO enrollment requires a valid token.
        config(['attendance.adms_strict_auth' => false]);

        $device = $this->device(['adms_token' => 'super-secret-token']);

        $uri = '/iclock/cdata?SN='.rawurlencode($device->serial_number).'&table=USERINFO';
        $body = "PIN=99\tName=Injected Hacker\tCard=123456\tPrivilege=0";

        $response = $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);

        $response->assertStatus(401);
        $this->assertSame('ERROR', $response->getContent());
        $this->assertDatabaseMissing('users', ['employee_id' => '99']);
    }

    public function test_userinfo_enrollment_succeeds_with_a_valid_token(): void
    {
        $device = $this->device(['adms_token' => 'super-secret-token']);

        $uri = '/iclock/cdata?SN='.rawurlencode($device->serial_number).'&table=USERINFO&token=super-secret-token';
        $body = "PIN=99\tName=Real Employee\tCard=123456\tPrivilege=0";

        $response = $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertDatabaseHas('users', ['employee_id' => '99']);
    }
}
