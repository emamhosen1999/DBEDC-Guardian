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

        // The /attendance/mark-as-present route is guarded by
        // permission:attendance.correct|attendance.create|attendance.update.
        // This fixture used to grant only the (unrelated) attendance.manage, so
        // the request 403'd — and because that abort happens mid-mock it left an
        // open transaction that poisoned the following tests in this file.
        foreach (['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_mark_as_present_publishes_attendance_signal_for_date(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
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
