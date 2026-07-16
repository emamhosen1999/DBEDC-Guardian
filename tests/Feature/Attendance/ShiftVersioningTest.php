<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShiftVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Administrator']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $admin->givePermissionTo('attendance.settings');

        return $admin;
    }

    public function test_editing_times_keeps_old_schedule_for_past_dates_and_new_for_today(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '00:00', 'end_time' => '08:00', 'crosses_midnight' => false,
        ]);

        $this->actingAs($this->admin())->putJson(route('attendance.shifts.update', $shift->id), [
            'start_time' => '23:00',
            'end_time' => '07:00',
            'crosses_midnight' => true,
            'effective_from' => Carbon::today()->toDateString(),
        ])->assertOk();

        $shift->refresh();

        $yesterday = $shift->toSchedule(Carbon::yesterday());
        $this->assertSame('00:00:00', $yesterday->start->format('H:i:s'));
        $this->assertSame('08:00:00', $yesterday->end->format('H:i:s'));
        $this->assertFalse($yesterday->crossesMidnight);

        $today = $shift->toSchedule(Carbon::today());
        $this->assertSame('23:00:00', $today->start->format('H:i:s'));
        $this->assertSame(Carbon::today()->addDay()->toDateString(), $today->end->toDateString());
        $this->assertTrue($today->crossesMidnight);
    }

    public function test_rename_only_edit_creates_no_version(): void
    {
        $shift = Shift::factory()->create(['name' => 'Old Name', 'color' => '#111111']);
        $this->assertSame(0, $shift->versions()->count());

        $this->actingAs($this->admin())->putJson(route('attendance.shifts.update', $shift->id), [
            'name' => 'New Name',
            'color' => '#222222',
        ])->assertOk()
            ->assertJsonPath('versions_count', 0);

        $shift->refresh();
        $this->assertSame('New Name', $shift->name);
        $this->assertSame(0, $shift->versions()->count());
    }

    public function test_sequential_edits_produce_correctly_ordered_versions(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '09:00', 'end_time' => '17:00', 'crosses_midnight' => false,
        ]);

        $this->actingAs($this->admin())->putJson(route('attendance.shifts.update', $shift->id), [
            'start_time' => '10:00',
            'end_time' => '18:00',
            'effective_from' => '2026-01-10',
        ])->assertOk();

        $this->actingAs($this->admin())->putJson(route('attendance.shifts.update', $shift->id), [
            'start_time' => '11:00',
            'end_time' => '19:00',
            'effective_from' => '2026-02-10',
        ])->assertOk();

        $shift->refresh();

        // Before the first version's window: falls back to the original ("since forever") baseline.
        $beforeV1 = $shift->toSchedule(Carbon::parse('2026-01-09'));
        $this->assertSame('09:00:00', $beforeV1->start->format('H:i:s'));

        // Day before v2 takes effect: still v1.
        $dayBeforeV2 = $shift->toSchedule(Carbon::parse('2026-02-09'));
        $this->assertSame('10:00:00', $dayBeforeV2->start->format('H:i:s'));
        $this->assertSame('18:00:00', $dayBeforeV2->end->format('H:i:s'));

        // Day v2 takes effect: new values.
        $dayOfV2 = $shift->toSchedule(Carbon::parse('2026-02-10'));
        $this->assertSame('11:00:00', $dayOfV2->start->format('H:i:s'));
        $this->assertSame('19:00:00', $dayOfV2->end->format('H:i:s'));

        // Baseline sentinel + v1 + v2 = 3 version rows.
        $this->assertSame(3, $shift->versions()->count());
    }

    public function test_backfilled_or_missing_versions_still_resolve_without_exploding(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '06:00', 'end_time' => '14:00', 'crosses_midnight' => false,
        ]);

        // No version rows at all yet — model-level fallback to the shift's own columns.
        $this->assertSame(0, $shift->versions()->count());
        $fallback = $shift->toSchedule(Carbon::parse('1999-06-01'));
        $this->assertSame('06:00:00', $fallback->start->format('H:i:s'));

        // Simulate the migration's sentinel backfill row.
        $shift->versions()->create([
            'effective_from' => '2000-01-01',
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
            'crosses_midnight' => $shift->crosses_midnight,
            'grace_in_minutes' => $shift->grace_in_minutes,
            'grace_out_minutes' => $shift->grace_out_minutes,
            'full_day_minutes' => $shift->full_day_minutes,
            'half_day_minutes' => $shift->half_day_minutes,
            'min_present_minutes' => $shift->min_present_minutes,
            'break_minutes' => $shift->break_minutes,
        ]);

        $sentinelResolved = $shift->toSchedule(Carbon::parse('2010-05-05'));
        $this->assertSame('06:00:00', $sentinelResolved->start->format('H:i:s'));
        $this->assertSame('14:00:00', $sentinelResolved->end->format('H:i:s'));
    }

    public function test_shift_controller_update_happy_path(): void
    {
        $admin = $this->admin();
        $shift = Shift::factory()->create([
            'name' => 'Morning', 'start_time' => '09:00', 'end_time' => '17:00',
        ]);

        $response = $this->actingAs($admin)->putJson(route('attendance.shifts.update', $shift->id), [
            'start_time' => '08:30',
            'end_time' => '16:30',
            'effective_from' => Carbon::today()->toDateString(),
        ]);

        $response->assertOk()
            ->assertJsonPath('shift.start_time', '08:30')
            ->assertJsonPath('versions_count', 2)
            ->assertJsonPath('historical_days_affected', 0);

        $this->assertDatabaseHas('shift_versions', [
            'shift_id' => $shift->id,
            'effective_from' => Carbon::today()->toDateString(),
            'start_time' => '08:30',
        ]);
    }

    public function test_employee_without_permission_forbidden(): void
    {
        $shift = Shift::factory()->create();
        $employee = User::factory()->create();

        $this->actingAs($employee)->putJson(route('attendance.shifts.update', $shift->id), [
            'name' => 'Nope',
        ])->assertForbidden();
    }
}
