<?php

namespace Tests\Feature\Jurisdiction;

use App\Models\Jurisdiction;
use App\Models\JurisdictionMember;
use App\Models\JurisdictionVersion;

/** The write API: validation (bounds, overlap, uniqueness), versioning, soft delete, audit and per-action permissions. */
class JurisdictionCrudTest extends JurisdictionTestCase
{
    private function payload(array $over = []): array
    {
        return $over + ['code' => 'PH-4', 'name' => 'Phase Four', 'start_m' => 49000 - 1000, 'end_m' => 48999, 'incharge' => $this->employee()->employee_id];
    }

    public function test_create_makes_the_identity_a_first_version_an_in_charge_row_and_an_audit_entry(): void
    {
        $this->phases();
        // free the tail so the new phase has room: Phase Two ends at K37+999 in this scenario
        Jurisdiction::where('code', 'PH-2')->first()->update(['start_chainage' => 'K38+000', 'end_chainage' => 'K40+999']);
        $in = $this->employee();

        $res = $this->actingAs($this->admin())->postJson('/jurisdictions/add', ['code' => 'ph-4', 'name' => 'Phase Four', 'start_m' => 41000, 'end_m' => 48999, 'incharge' => $in->employee_id, 'colour_token' => 'orange'])
            ->assertCreated()->assertJsonPath('jurisdiction.code', 'PH-4')->assertJsonPath('jurisdiction.start_chainage', 'K41+000')->assertJsonPath('jurisdiction.end_chainage', 'K48+999');

        $j = Jurisdiction::where('code', 'PH-4')->firstOrFail();
        $this->assertSame(1, JurisdictionVersion::where('jurisdiction_id', $j->id)->count());
        $this->assertSame($in->employee_id, JurisdictionMember::where('jurisdiction_id', $j->id)->where('role', 'in_charge')->whereNull('effective_to')->value('employee_id'));
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'jurisdiction.created', 'subject_id' => (string) $j->id]);
        $this->assertNotEmpty($res->json('jurisdictions'), 'the old response key is kept');
    }

    public function test_validation_bounds_order_overlap_and_uniqueness(): void
    {
        $p = $this->phases();
        $a = $this->admin();
        $in = $this->employee()->employee_id;

        $this->actingAs($a)->postJson('/jurisdictions/add', $this->payload(['start_m' => 5000, 'end_m' => 5000, 'incharge' => $in]))->assertJsonValidationErrors('end_m');
        $this->actingAs($a)->postJson('/jurisdictions/add', $this->payload(['start_m' => 6000, 'end_m' => 5000, 'incharge' => $in]))->assertJsonValidationErrors('end_m');
        $this->actingAs($a)->postJson('/jurisdictions/add', $this->payload(['start_m' => 40000, 'end_m' => 49000, 'incharge' => $in]))->assertJsonValidationErrors('end_m');
        $this->actingAs($a)->postJson('/jurisdictions/add', $this->payload(['start_m' => 20000, 'end_m' => 30000, 'incharge' => $in, 'code' => 'PH-9', 'name' => 'Clash']))->assertJsonValidationErrors('start_m');
        $this->actingAs($a)->postJson('/jurisdictions/add', $this->payload(['code' => 'PH-1', 'name' => 'Other', 'start_m' => 0, 'end_m' => 1, 'incharge' => $in]))->assertJsonValidationErrors('code');
        $this->actingAs($a)->postJson('/jurisdictions/add', $this->payload(['name' => 'Phase One', 'incharge' => $in, 'code' => 'PH-8']))->assertJsonValidationErrors('name');
        $this->actingAs($a)->postJson('/jurisdictions/add', $this->payload(['incharge' => 'nobody', 'code' => 'PH-7', 'name' => 'Seven', 'start_m' => 0, 'end_m' => 1]))->assertJsonValidationErrors('incharge');
        $this->assertSame(3, Jurisdiction::count(), 'nothing was created by a refused request');
    }

    public function test_the_old_string_payload_still_works(): void
    {
        $this->phases();
        Jurisdiction::where('code', 'PH-2')->first()->update(['start_chainage' => 'K38+000', 'end_chainage' => 'K40+999']);
        $this->actingAs($this->admin())->postJson('/jurisdictions/add', ['location' => 'Phase Four', 'code' => 'PH-4', 'start_chainage' => 'K41+000', 'end_chainage' => 'K48+999', 'incharge' => $this->employee()->employee_id])
            ->assertCreated()->assertJsonPath('jurisdiction.start_m', 41000);
    }

    public function test_a_boundary_edit_keeps_the_old_definition_and_a_plain_edit_does_not_version(): void
    {
        $p = $this->phases();
        $a = $this->admin();
        $this->actingAs($a)->postJson('/jurisdictions/update', ['id' => $p['one']->id, 'code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 27999, 'description' => 'Km 0 to 28', 'colour_token' => 'green'])
            ->assertOk()->assertJsonPath('jurisdiction.description', 'Km 0 to 28');
        $this->assertSame(1, JurisdictionVersion::where('jurisdiction_id', $p['one']->id)->count(), 'description and colour are not historical');

        $this->actingAs($a)->postJson('/jurisdictions/update', ['id' => $p['one']->id, 'code' => 'PH-1', 'name' => 'Phase One Renamed', 'start_m' => 0, 'end_m' => 27999, 'effective_date' => date('Y-m-d', strtotime('+3 day'))])->assertOk();
        $versions = JurisdictionVersion::where('jurisdiction_id', $p['one']->id)->orderBy('id')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('Phase One', $versions[0]->name, 'the previous name stays in the history');
        $this->assertSame('Phase One', $p['one']->refresh()->location, 'the rename takes effect on its date');
    }

    public function test_delete_is_for_mistakes_only_and_is_refused_once_history_exists(): void
    {
        $p = $this->phases();
        $this->work('RFI-9', 'K05+100', date('Y-m-d', strtotime('-30 day')), 'new', $p['people'][0]->employee_id);
        $admin = $this->admin();

        // attributed works: refused, retire instead
        $this->actingAs($admin)->postJson('/jurisdictions/delete', ['id' => $p['one']->id])->assertJsonValidationErrors('id');
        $this->assertNotSoftDeleted('jurisdictions', ['id' => $p['one']->id]);

        // a section added by mistake (no works, no handovers, no changes): soft delete works and leaves no trace in resolution
        $this->actingAs($admin)->postJson('/jurisdictions/add', ['code' => 'OOPS', 'name' => 'Mistake', 'start_m' => 0, 'end_m' => 1, 'incharge' => $this->employee()->employee_id, 'kind' => 'facility', 'subtype' => 'depot'])->assertCreated();
        $oops = Jurisdiction::where('code', 'OOPS')->firstOrFail();
        $this->actingAs($admin)->postJson('/jurisdictions/delete', ['id' => $oops->id])->assertOk();
        $this->assertSoftDeleted('jurisdictions', ['id' => $oops->id]);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'jurisdiction.deleted', 'subject_id' => (string) $oops->id]);
        $this->actingAs($admin)->postJson('/jurisdictions/delete', ['id' => $oops->id])->assertJsonValidationErrors('id');
    }

    public function test_each_action_has_its_own_permission(): void
    {
        $p = $this->phases();
        $json = fn ($u) => $this->actingAs($u);
        $none = $this->employee();
        $json($none)->getJson('/jurisdictions/json')->assertForbidden();
        $json($none)->getJson('/jurisdictions/overview')->assertForbidden();
        $json($none)->get('/jurisdictions/map/print')->assertForbidden();

        $viewer = $this->employee(['jurisdiction.view']);
        $json($viewer)->getJson('/jurisdictions/json')->assertOk();
        $json($viewer)->postJson('/jurisdictions/add', $this->payload())->assertForbidden();
        $json($viewer)->postJson('/jurisdictions/update', ['id' => $p['one']->id] + $this->payload())->assertForbidden();
        $json($viewer)->postJson('/jurisdictions/delete', ['id' => $p['one']->id])->assertForbidden();

        $updater = $this->employee(['jurisdiction.view', 'jurisdiction.update']);
        // changing the in-charge is a team action
        $json($updater)->postJson('/jurisdictions/update', ['id' => $p['one']->id, 'code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 27999, 'incharge' => $this->employee()->employee_id])
            ->assertJsonValidationErrors('incharge');
        $json($updater)->postJson('/jurisdictions/delete', ['id' => $p['one']->id])->assertForbidden();
        $creator = $this->employee(['jurisdiction.create']);
        $json($creator)->postJson('/jurisdictions/update', ['id' => $p['one']->id])->assertForbidden();
        $deleter = $this->employee(['jurisdiction.delete']);
        $json($deleter)->postJson('/jurisdictions/delete', ['id' => $p['three']->id])->assertOk();
    }

    public function test_the_json_endpoint_keeps_its_old_fields_and_adds_the_new_ones(): void
    {
        $this->phases();
        $res = $this->actingAs($this->employee(['jurisdiction.view']))->getJson('/jurisdictions/json')->assertOk();
        $first = $res->json('jurisdictions.0');
        foreach (['id', 'location', 'start_chainage', 'end_chainage', 'incharge', 'incharge_user', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        foreach (['code', 'name', 'start_m', 'end_m', 'length_m'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
    }
}
