<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * No dead buttons: every attendance row carries the server's own `can_act` verdict (the very rule the
 * write routes enforce), so Mark present / correct never shows for oneself, for a peer or superior, or
 * outside the actor's scope - and an in-scope subordinate can really be marked present.
 */
class AttendanceRowActionsTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** @return array<string, array> user rows of the partition bucket, keyed by employee id */
    private function bucket(string $name): array
    {
        $json = $this->actingAs($this->admin)->getJson(route('attendance.dayPartition', ['date' => self::DAY]))->assertOk()->json($name);

        return collect($json)->mapWithKeys(fn ($row) => [(string) $row['user']['employee_id'] => $row['user']])->all();
    }

    public function test_absent_rows_flag_only_subordinates_as_actionable(): void
    {
        $absent = $this->bucket('absent');

        $this->assertTrue($absent[$this->e1b->employee_id]['can_act'], 'in-scope subordinate');
        $this->assertFalse($absent[$this->admin->employee_id]['can_act'] ?? false, 'never himself');
        $this->assertFalse($absent[$this->peer->employee_id]['can_act'] ?? false, 'peer of equal rank');
        $this->assertFalse($absent[$this->hrInD1->employee_id]['can_act'] ?? false, 'someone who outranks him');
        $this->assertArrayNotHasKey($this->c1->employee_id, $absent, 'out of scope users are not even listed');
    }

    public function test_absent_users_endpoint_carries_the_same_flag(): void
    {
        $rows = collect($this->actingAs($this->admin)->getJson(route('admin.getAbsentUsersForDate', ['date' => self::DAY]))->assertOk()->json('absent_users'))
            ->keyBy('employee_id');

        foreach ($rows as $employeeId => $row) {
            $this->assertArrayHasKey('can_act', $row);
            $expected = in_array((string) $employeeId, [(string) $this->e1b->employee_id], true);
            $this->assertSame($expected, $row['can_act'], "can_act for {$employeeId}");
        }
    }

    public function test_daily_timesheet_rows_flag_correctable_records(): void
    {
        Attendance::factory()->for($this->admin)->create(['date' => self::DAY, 'punchin' => self::DAY.' 09:05:00', 'punchout' => self::DAY.' 17:00:00']);

        $rows = collect($this->actingAs($this->admin)->getJson(route('admin.daily-timesheet', ['date' => self::DAY, 'perPage' => 100]))->assertOk()->json('attendances'))
            ->keyBy(fn ($row) => (string) $row['user']['employee_id']);

        $this->assertFalse($rows[$this->admin->employee_id]['can_act'], 'his own record is never his to correct');
        $this->assertTrue($rows[$this->e1->employee_id]['can_act']);
    }

    public function test_marking_present_works_for_a_subordinate_and_is_refused_everywhere_else(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('attendance.mark-as-present'), ['user_id' => $this->e1b->employee_id, 'date' => self::DAY])->assertOk();
        $this->assertTrue(Attendance::where('user_id', $this->e1b->employee_id)->whereDate('date', self::DAY)->exists());

        foreach ([$this->admin, $this->peer, $this->hrInD1, $this->c2] as $forbidden) {
            $this->postJson(route('attendance.mark-as-present'), ['user_id' => $forbidden->employee_id, 'date' => self::DAY])
                ->assertForbidden();
            $this->assertFalse(Attendance::where('user_id', $forbidden->employee_id)->whereDate('date', self::DAY)->exists(), "no record for {$forbidden->name}");
        }
    }

    public function test_global_hr_is_never_flagged_out(): void
    {
        $rows = collect($this->actingAs($this->hr)->getJson(route('attendance.dayPartition', ['date' => self::DAY]))->assertOk()->json('absent'))
            ->pluck('user.can_act');

        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn ($v) => $v === true));
    }
}
