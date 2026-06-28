<?php

namespace Tests\Feature\Realtime;

use App\Models\User;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'attendance.manage', 'guard_name' => 'web']);
    }

    public function test_mark_as_present_publishes_attendance_signal_for_date(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.manage');
        $target = User::factory()->create();

        // Isolate the heavy mark-present dependencies so the test focuses on the signal.
        $resolver = Mockery::mock(\App\Services\Attendance\Contracts\ScheduleResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(
            \App\Services\Attendance\DTO\ShiftSchedule::nonWorking(\Carbon\Carbon::parse('2026-06-20'))
        );
        $this->app->instance(\App\Services\Attendance\Contracts\ScheduleResolver::class, $resolver);

        $audit = Mockery::mock(\App\Services\Attendance\AttendanceAuditService::class);
        $audit->shouldReceive('record')->andReturnNull();
        $this->app->instance(\App\Services\Attendance\AttendanceAuditService::class, $audit);

        $signals = Mockery::mock(RealtimeSignal::class);
        $signals->shouldReceive('touch')->once()->with('attendance', '2026-06-20', $admin->id, 'mark_present');
        $this->app->instance(RealtimeSignal::class, $signals);

        $this->actingAs($admin)->postJson('/attendance/mark-as-present', [
            'user_id' => $target->id,
            'date' => '2026-06-20',
        ])->assertOk();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
