<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\RegularizationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
        $this->assertSame('18:00', Carbon::parse($att->punchout)->format('H:i'));
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'regularize', 'attendance_id' => $att->id]);
    }

    public function test_a_day_shift_punch_out_before_the_punch_in_is_rejected(): void
    {
        $emp = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(RegularizationService::class)->request($emp->id, [
            'date' => '2026-06-18', 'type' => 'wrong_time',
            'requested_punchin' => '2026-06-18 18:00:00', 'requested_punchout' => '2026-06-18 09:00:00', 'reason' => 'typo',
        ]);
    }

    public function test_times_outside_the_corrected_date_are_rejected(): void
    {
        $emp = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(RegularizationService::class)->request($emp->id, [
            'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-05-01 18:00:00', 'reason' => 'wrong month',
        ]);
    }

    public function test_a_night_shift_punch_out_after_midnight_is_rolled_to_the_next_day(): void
    {
        // Both clients send "<date> <time>", so 06:00 arrives as the SAME date and must roll over.
        $emp = User::factory()->create();
        $night = Shift::factory()->create(['start_time' => '22:00', 'end_time' => '06:00']);
        RosterDay::create(['user_id' => $emp->employee_id, 'date' => '2026-06-18', 'shift_id' => $night->id, 'source' => 'manual', 'locked' => true]);

        $r = app(RegularizationService::class)->request($emp->id, [
            'date' => '2026-06-18', 'type' => 'wrong_time',
            'requested_punchin' => '2026-06-18 22:05:00', 'requested_punchout' => '2026-06-18 06:00:00', 'reason' => 'night',
        ]);

        $this->assertSame('2026-06-19 06:00:00', $r->fresh()->requested_punchout->format('Y-m-d H:i:s'));
    }
}
