<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A biometric/device punch must be recorded at the device's REAL punch time, not the
 * server's processing time (a device may push or be back-downloaded hours later).
 * Manual/web punches must always use server time so a user cannot back-date their punch.
 */
class BiometricPunchTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_biometric_punch_uses_device_time_not_server_time(): void
    {
        // Server processes the punch at 14:05 (late download), but the device recorded 08:00.
        Carbon::setTestNow(Carbon::parse('2026-06-19 14:05:00'));
        $user = User::factory()->create();

        $req = Request::create('/biometric/punch', 'POST', [
            'source' => 'biometric',
            'punch_time' => '2026-06-19 08:00:00',
            'check_type' => 'in',
        ]);
        $res = app(AttendancePunchService::class)->processPunch($user, $req);

        $this->assertSame('success', $res['status']);
        $att = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-06-19 08:00:00', Carbon::parse($att->punchin)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-19', Carbon::parse($att->date)->format('Y-m-d'));
    }

    public function test_web_punch_ignores_spoofed_punch_time_and_uses_server_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:30:00'));
        $user = User::factory()->create();

        // A web request carrying punch_time but NO trusted source must be ignored.
        $req = new Request(['check_type' => 'in', 'punch_time' => '2026-06-19 06:00:00']);
        $res = app(AttendancePunchService::class)->processPunch($user, $req);

        $this->assertSame('success', $res['status']);
        $att = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-06-19 09:30:00', Carbon::parse($att->punchin)->format('Y-m-d H:i:s'));
    }
}
