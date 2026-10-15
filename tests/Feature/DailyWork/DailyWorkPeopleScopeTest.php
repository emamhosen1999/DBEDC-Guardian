<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;

/** The people pickers and every in-charge / assignee save path obey DepartmentScope. */
class DailyWorkPeopleScopeTest extends DailyWorkTestCase
{
    public function test_a_scoped_viewer_neither_sees_nor_can_assign_an_employee_of_another_department(): void
    {
        $p = $this->phases();
        $d1 = \DB::table('departments')->insertGetId(['name' => 'Dept One', 'created_at' => now(), 'updated_at' => now()]);
        $d2 = \DB::table('departments')->insertGetId(['name' => 'Dept Two', 'created_at' => now(), 'updated_at' => now()]);
        $me = $this->viewer(['view', 'assign', 'incharge.update', 'bulk'], ['department_id' => $d1]);
        $near = $this->employee([], ['department_id' => $d1, 'name' => 'Near Person']);
        $far = $this->employee([], ['department_id' => $d2, 'name' => 'CANARY-D2-7f3a']);

        $ids = collect($this->actingAs($me)->getJson(route('dailyWorks.people'))->assertOk()->json('people'))->pluck('employee_id');
        $this->assertTrue($ids->contains($me->employee_id), 'the viewer sees themselves');
        $this->assertFalse($ids->contains($far->employee_id), 'the other department never appears');

        $this->work('RFI-S1', 'K05+100', '2026-05-04', 'new', $p['people'][0]->employee_id, $p['people'][0]->employee_id, 'Pavement');
        $w = DailyWork::where('number', 'RFI-S1')->first();
        $this->actingAs($me)->postJson(route('dailyWorks.assign'), ['id' => $w->id, 'assigned' => $far->employee_id, 'lock_version' => $w->lock_version])->assertStatus(422);
        $this->actingAs($me)->postJson(route('dailyWorks.updateIncharge'), ['id' => $w->id, 'incharge' => $far->employee_id, 'lock_version' => $w->lock_version])->assertStatus(422);
    }
}
