<?php

namespace Tests\Feature\Quality;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * The NCR assignee picker listed every employee in the company to any NCR viewer; the role matrix caught it as a leak
 * once the page stopped crashing. Assignees are now the viewer's DepartmentScope, enforced on save as well.
 */
class NcrAssigneeScopeTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildWorld();
        foreach (['quality.ncr.view', 'quality.ncr.create'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->admin->givePermissionTo(['quality.ncr.view', 'quality.ncr.create']); // Department Admin of D1
    }

    private function ncr(string $assignee): array
    {
        return ['title' => 'Joint sealant failure', 'description' => 'Sealant missing at expansion joint.', 'severity' => 'minor',
            'status' => 'open', 'detected_date' => '2026-10-01', 'assigned_to' => $assignee];
    }

    public function test_the_assignee_picker_lists_only_employees_in_the_viewers_scope(): void
    {
        $this->actingAs($this->admin)->get(route('quality.ncr.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Quality/NcrRegister')
                ->where('options.users', fn ($users) => collect($users)->pluck('id')->contains((string) $this->e1->employee_id)
                    && ! collect($users)->pluck('id')->contains((string) $this->c1->employee_id)
                    && ! str_contains(json_encode($users), self::MARKER)));
    }

    public function test_an_ncr_cannot_be_assigned_outside_the_viewers_scope(): void
    {
        $this->actingAs($this->admin)->postJson(route('quality.ncr.store'), $this->ncr((string) $this->c1->employee_id))
            ->assertStatus(422)->assertJsonValidationErrors('assigned_to');

        $this->actingAs($this->admin)->postJson(route('quality.ncr.store'), $this->ncr((string) $this->e1->employee_id))
            ->assertOk();
    }
}
