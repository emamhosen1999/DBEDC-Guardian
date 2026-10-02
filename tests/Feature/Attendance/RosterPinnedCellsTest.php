<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Services\Attendance\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * A manual roster adjustment is PINNED (the generator never overwrites it) yet stays editable by the
 * roster manager who may manage that employee: set a shift on an off day, clear it back to Off, reset to
 * the pattern. Another department's cells stay untouchable.
 */
class RosterPinnedCellsTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->shift = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '16:00']);
    }

    private function cell(array $data)
    {
        return $this->actingAs($this->admin)->putJson(route('attendance.roster.cell'), $data + ['date' => self::DAY]);
    }

    public function test_department_admin_sets_a_shift_clears_it_to_off_and_resets_to_the_pattern(): void
    {
        $uid = $this->e1->employee_id;

        $this->cell(['user_id' => $uid, 'shift_ids' => [$this->shift->id]])->assertOk();
        $row = RosterDay::where('user_id', $uid)->whereDate('date', self::DAY)->first();
        $this->assertSame('manual', $row->source);
        $this->assertFalse($row->locked, 'a manual adjustment is pinned, not locked');

        // Set the day back to Off: used to be impossible (the cell was saved locked).
        $this->cell(['user_id' => $uid, 'shift_ids' => [], 'expected_updated_at' => $row->updated_at->toIso8601String()])->assertOk();
        $off = RosterDay::where('user_id', $uid)->whereDate('date', self::DAY)->get();
        $this->assertCount(1, $off);
        $this->assertNull($off->first()->shift_id);

        // Reset to pattern: the manual override is gone and the pattern (no assignment: off, source pattern) applies.
        $this->cell(['user_id' => $uid, 'reset_to_pattern' => true])->assertOk();
        $this->assertSame('pattern', RosterDay::where('user_id', $uid)->whereDate('date', self::DAY)->first()?->source);
    }

    public function test_a_legacy_locked_manual_cell_is_editable_by_the_roster_manager(): void
    {
        RosterDay::create(['user_id' => $this->e1->employee_id, 'date' => self::DAY, 'shift_id' => $this->shift->id, 'source' => 'manual', 'locked' => true]);

        $this->cell(['user_id' => $this->e1->employee_id, 'shift_ids' => []])->assertOk();
    }

    public function test_he_cannot_touch_another_departments_cells(): void
    {
        $this->cell(['user_id' => $this->c1->employee_id, 'shift_ids' => [$this->shift->id]])->assertForbidden();
        $this->cell(['user_id' => $this->c1->employee_id, 'reset_to_pattern' => true])->assertForbidden();
    }

    public function test_the_generator_never_overwrites_a_pinned_cell(): void
    {
        $uid = $this->e1->employee_id;
        $this->cell(['user_id' => $uid, 'shift_ids' => [$this->shift->id]])->assertOk();

        app(RosterService::class)->generateRoster([$uid], self::DAY, self::DAY);

        $row = RosterDay::where('user_id', $uid)->whereDate('date', self::DAY)->first();
        $this->assertSame('manual', $row->source);
        $this->assertSame($this->shift->id, $row->shift_id);
    }

    public function test_the_migration_unlocks_old_manual_cells_only(): void
    {
        $manual = RosterDay::create(['user_id' => $this->e1->employee_id, 'date' => self::DAY, 'shift_id' => $this->shift->id, 'source' => 'manual', 'locked' => true]);
        $swap = RosterDay::create(['user_id' => $this->e1b->employee_id, 'date' => self::DAY, 'shift_id' => $this->shift->id, 'source' => 'swap', 'locked' => true]);

        (require base_path('database/migrations/2026_10_02_000002_unlock_manual_roster_cells.php'))->up();

        $this->assertFalse($manual->fresh()->locked);
        $this->assertTrue($swap->fresh()->locked, 'swap-finalized cells stay locked');
    }
}
