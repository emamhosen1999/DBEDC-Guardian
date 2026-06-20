<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use App\Services\Attendance\RegularizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegularizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_regularization_applies_the_punch_and_audits(): void
    {
        $manager = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $svc = app(RegularizationService::class);

        // existing record with a missing punch-out
        Attendance::factory()->for($emp)->create([
            'date' => '2026-06-18', 'punchin' => '2026-06-18 09:00:00', 'punchout' => null,
        ]);

        $r = $svc->request($emp->id, [
            'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
        ]);
        $this->assertSame('pending', $r->status);

        $res = $svc->approve($r->fresh(), $manager, 'ok');
        $this->assertTrue($res['success']);

        $r = $r->fresh();
        $this->assertSame('approved', $r->status);
        $this->assertTrue($r->applied);

        $att = Attendance::where('user_id', $emp->id)->whereDate('date', '2026-06-18')->first();
        $this->assertSame('18:00', \Carbon\Carbon::parse($att->punchout)->format('H:i'));
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'regularize', 'attendance_id' => $att->id]);
    }
}
