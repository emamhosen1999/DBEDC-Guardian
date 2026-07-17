<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MultiShiftRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_update_cell_with_two_shift_ids_writes_two_rows(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();

        $day = Shift::factory()->create(['code' => 'D']);
        $night = Shift::factory()->create(['code' => 'N']);

        $response = $this->actingAs($admin)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id,
            'date' => '2026-06-19',
            'shift_ids' => [$day->id, $night->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'cells');

        $this->assertSame(2, RosterDay::where('user_id', $emp->id)->whereDate('date', '2026-06-19')->count());
        $this->assertDatabaseHas('roster_days', [
            'user_id' => $emp->id, 'date' => '2026-06-19', 'shift_id' => $day->id, 'source' => 'manual',
        ]);
        $this->assertDatabaseHas('roster_days', [
            'user_id' => $emp->id, 'date' => '2026-06-19', 'shift_id' => $night->id, 'source' => 'manual',
        ]);
    }

    public function test_update_cell_with_empty_shift_ids_writes_a_single_off_row(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();
        $shift = Shift::factory()->create();

        // Pre-existing multi-shift day that must be replaced entirely.
        RosterDay::create(['user_id' => $emp->id, 'date' => '2026-06-20', 'shift_id' => $shift->id, 'source' => 'manual', 'locked' => true]);

        $response = $this->actingAs($admin)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id,
            'date' => '2026-06-20',
            'shift_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'cells');

        $rows = RosterDay::where('user_id', $emp->id)->whereDate('date', '2026-06-20')->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->shift_id);
    }

    public function test_grid_payload_carries_shifts_array_and_legacy_primary_keys(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();

        $first = Shift::factory()->create(['code' => 'D']);
        $second = Shift::factory()->create(['code' => 'N']);

        $this->actingAs($admin)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id,
            'date' => '2026-06-21',
            'shift_ids' => [$first->id, $second->id],
        ])->assertOk();

        $res = $this->actingAs($admin)->getJson(route('attendance.roster.index', [
            'from' => '2026-06-01', 'to' => '2026-06-30',
        ]))->assertOk();

        $cell = $res->json("roster.{$emp->id}.days.2026-06-21");

        $this->assertSame('D', $cell['code']);
        $this->assertFalse($cell['off']);
        $this->assertCount(2, $cell['shifts']);
        $this->assertSame('D', $cell['shifts'][0]['code']);
        $this->assertSame('N', $cell['shifts'][1]['code']);
    }

    public function test_worked_is_true_when_attendance_with_punchin_exists(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();
        $shift = Shift::factory()->create();

        $pastDate = Carbon::today()->subDay()->toDateString();

        RosterDay::create(['user_id' => $emp->id, 'date' => $pastDate, 'shift_id' => $shift->id, 'source' => 'manual', 'locked' => true]);
        Attendance::create([
            'user_id' => $emp->id, 'date' => $pastDate,
            'punchin' => $pastDate.' 08:00:00', 'policy_status' => 'accepted',
        ]);

        $res = $this->actingAs($admin)->getJson(route('attendance.roster.index', [
            'from' => Carbon::parse($pastDate)->subDay()->toDateString(),
            'to' => Carbon::parse($pastDate)->addDay()->toDateString(),
        ]))->assertOk();

        $this->assertTrue($res->json("roster.{$emp->id}.days.{$pastDate}.worked"));
    }

    public function test_worked_is_false_for_a_past_rostered_day_with_no_punch(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();
        $shift = Shift::factory()->create();

        $pastDate = Carbon::today()->subDays(2)->toDateString();

        RosterDay::create(['user_id' => $emp->id, 'date' => $pastDate, 'shift_id' => $shift->id, 'source' => 'manual', 'locked' => true]);

        $res = $this->actingAs($admin)->getJson(route('attendance.roster.index', [
            'from' => Carbon::parse($pastDate)->subDay()->toDateString(),
            'to' => Carbon::parse($pastDate)->addDay()->toDateString(),
        ]))->assertOk();

        $this->assertFalse($res->json("roster.{$emp->id}.days.{$pastDate}.worked"));
    }

    public function test_worked_is_null_for_a_future_rostered_day(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();
        $shift = Shift::factory()->create();

        $futureDate = Carbon::today()->addDays(5)->toDateString();

        RosterDay::create(['user_id' => $emp->id, 'date' => $futureDate, 'shift_id' => $shift->id, 'source' => 'manual', 'locked' => true]);

        $res = $this->actingAs($admin)->getJson(route('attendance.roster.index', [
            'from' => Carbon::parse($futureDate)->subDay()->toDateString(),
            'to' => Carbon::parse($futureDate)->addDay()->toDateString(),
        ]))->assertOk();

        $this->assertNull($res->json("roster.{$emp->id}.days.{$futureDate}.worked"));
    }

    public function test_resolve_shift_on_a_two_row_day_returns_first_rows_shift(): void
    {
        $emp = User::factory()->create();
        $first = Shift::factory()->create(['code' => 'D']);
        $second = Shift::factory()->create(['code' => 'N']);

        $firstRow = RosterDay::create(['user_id' => $emp->id, 'date' => '2026-06-22', 'shift_id' => $first->id, 'source' => 'manual', 'locked' => true]);
        RosterDay::create(['user_id' => $emp->id, 'date' => '2026-06-22', 'shift_id' => $second->id, 'source' => 'manual', 'locked' => true]);

        $resolved = app(RosterService::class)->resolveShift($emp->id, Carbon::parse('2026-06-22'));

        $this->assertSame($first->id, $resolved->id);
        $this->assertTrue($firstRow->id < RosterDay::where('user_id', $emp->id)->whereDate('date', '2026-06-22')->max('id'));
    }
}
