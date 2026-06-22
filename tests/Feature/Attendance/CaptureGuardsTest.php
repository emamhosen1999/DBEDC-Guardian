<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CaptureGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function punch(User $user, array $input): array
    {
        return app(AttendancePunchService::class)->processPunch($user, new Request($input));
    }

    public function test_future_dated_device_punch_falls_back_to_server_time(): void
    {
        Carbon::setTestNow('2026-06-10 09:00:00');
        $user = User::factory()->create();

        $res = $this->punch($user, [
            'source' => 'biometric',
            'check_type' => 'in',
            'punch_time' => '2026-06-12 09:00:00', // 2 days in the future
        ]);

        $this->assertSame('success', $res['status']);
        $row = Attendance::where('user_id', $user->id)->first();
        // Future timestamp rejected -> server time used, not the bogus future moment.
        $this->assertSame('2026-06-10', Carbon::parse($row->punchin)->toDateString());
        Carbon::setTestNow();
    }

    public function test_legit_old_back_download_is_still_honoured(): void
    {
        Carbon::setTestNow('2026-06-10 20:00:00');
        $user = User::factory()->create();

        $res = $this->punch($user, [
            'source' => 'biometric',
            'check_type' => 'in',
            'punch_time' => '2026-06-10 09:00:00', // real morning punch, downloaded in the evening
        ]);

        $this->assertSame('success', $res['status']);
        $row = Attendance::where('user_id', $user->id)->first();
        $this->assertSame('09:00', Carbon::parse($row->punchin)->format('H:i'));
        Carbon::setTestNow();
    }

    public function test_out_before_in_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-10 09:00:00');
        $user = User::factory()->create();
        $this->punch($user, ['check_type' => 'in']); // server-time punch-in at 09:00

        Carbon::setTestNow('2026-06-10 09:30:00');
        // Device out-punch carrying a timestamp BEFORE the punch-in.
        $res = $this->punch($user, [
            'source' => 'biometric',
            'check_type' => 'out',
            'punch_time' => '2026-06-10 08:00:00',
        ]);

        $this->assertSame('error', $res['status']);
        $this->assertSame(422, $res['code']);
        Carbon::setTestNow();
    }
}
