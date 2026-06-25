<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\LeaveAuditLog;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveCrudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LeaveAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function bindAllWorkingDays(): void
    {
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver
        {
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule(
                    start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                    crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                    fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                    breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                );
            }
        });
    }

    /** @test */
    public function creating_a_leave_writes_an_immutable_audit_row(): void
    {
        $this->bindAllWorkingDays();
        $actor = User::factory()->create();
        Auth::login($actor);
        LeaveSetting::create(['type' => 'Casual', 'days' => 100, 'requires_approval' => true]);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $actor->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-02',
            'daysCount' => 1, 'leaveReason' => 'audit me',
        ]);

        $log = LeaveAuditLog::where('leave_id', $leave->id)->where('action', 'create')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertNull(LeaveAuditLog::UPDATED_AT); // immutable
    }

    /** @test */
    public function deleting_a_leave_writes_a_delete_audit_row_with_before_state(): void
    {
        $this->bindAllWorkingDays();
        $actor = User::factory()->create();
        Auth::login($actor);
        LeaveSetting::create(['type' => 'Casual', 'days' => 100, 'requires_approval' => true]);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $actor->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-02',
            'daysCount' => 1, 'leaveReason' => 'will delete',
        ]);

        app(LeaveCrudService::class)->deleteLeave($leave->id);

        $log = LeaveAuditLog::where('leave_id', $leave->id)->where('action', 'delete')->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->before);
        $this->assertNull($log->after);
    }
}
