<?php

namespace Tests\Feature\Access;

use App\Models\UserDepartmentScope;
use App\Services\Access\DepartmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\Feature\Access\Concerns\TogglesSelfAdministration;
use Tests\TestCase;

/**
 * Delegation rule (Microsoft Entra delegated-admin model): inside his departments a Department Admin
 * manages lower-ranked staff and any peer whose elevated permissions and department grants he already
 * holds; anyone holding more than him stays protected.
 */
class PeerManagementTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;
    use TogglesSelfAdministration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->buildWorld();
    }

    public function test_an_identical_peer_admin_in_his_department_is_manageable(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->peer->employee_id), ['name' => 'Peer Renamed'])
            ->assertOk();

        $this->assertSame('Peer Renamed', $this->peer->fresh()->name);
    }

    public function test_an_hr_manager_inside_his_department_stays_protected(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->hrInD1->employee_id), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $this->hrInD1->fresh()->name);
    }

    public function test_a_peer_with_an_extra_department_grant_stays_protected(): void
    {
        UserDepartmentScope::create(['user_id' => $this->peer->employee_id, 'department_id' => $this->d2->id, 'scope_type' => 'admin']);
        app(DepartmentScope::class)->forget();

        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->peer->employee_id), ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_a_peer_with_an_extra_administrative_permission_stays_protected(): void
    {
        $this->peer->givePermissionTo(Permission::findOrCreate('hr.payroll.view', 'web'));
        app(DepartmentScope::class)->forget();

        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->peer->employee_id), ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_authority_never_runs_up_his_own_reporting_line(): void
    {
        // Two identical admins (no personal exception): only the reporting line separates them.
        $this->withoutSelfAdministration();

        // The peer is his own line manager: equal roles, yet the chain above him is not his to manage.
        $this->admin->forceFill(['report_to' => $this->peer->employee_id])->save();
        app(DepartmentScope::class)->forget();

        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->peer->employee_id), ['name' => 'Hijacked'])
            ->assertForbidden();
        $this->assertNotSame('Hijacked', $this->peer->fresh()->name);

        // Downward it still holds: the manager manages his report.
        $this->actingAs($this->peer)
            ->putJson(route('users.update', $this->admin->employee_id), ['name' => 'Report Renamed'])
            ->assertOk();
        $this->assertSame('Report Renamed', $this->admin->fresh()->name);
    }

    public function test_lower_ranked_staff_with_functional_roles_remain_manageable(): void
    {
        $lead = $this->person('Line Manager One', $this->d1, ['Line Manager', 'Employee']);

        $this->actingAs($this->admin)
            ->putJson(route('users.update', $lead->employee_id), ['name' => 'Lead Renamed'])
            ->assertOk();

        $this->assertSame('Lead Renamed', $lead->fresh()->name);
    }
}
