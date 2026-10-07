<?php

namespace Tests\Feature\Org;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/** The "Reports To" picker: a Department Admin sees their scope plus every department head, directory fields only. */
class ReportingManagerCandidatesTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->buildWorld();
        // c2 heads the other department: an org-chart head is visible to everyone.
        $this->d2->forceFill(['manager_id' => $this->c2->employee_id])->save();
    }

    private function ids(array $candidates): array
    {
        return array_column($candidates, 'id');
    }

    public function test_a_department_admin_sees_own_scope_plus_department_heads_and_directory_fields_only(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('employees.reporting-manager-candidates', ['employee_id' => $this->e1->employee_id]))
            ->assertOk();

        $candidates = $response->json('candidates');
        $ids = $this->ids($candidates);

        $this->assertContains((string) $this->e1b->employee_id, $ids);
        $this->assertContains((string) $this->peer->employee_id, $ids);
        $this->assertContains((string) $this->c2->employee_id, $ids, 'the head of another department is directory information');
        $this->assertNotContains((string) $this->c1->employee_id, $ids, 'a non-head of another department stays hidden');
        $this->assertNotContains((string) $this->e1->employee_id, $ids, 'never the employee themself');

        foreach ($candidates as $candidate) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'designation', 'department_id', 'department'], array_keys($candidate));
        }
        $this->assertStringNotContainsString(self::MARKER.'-NID', $response->getContent());
        $this->assertStringNotContainsString('salary', $response->getContent());
    }

    public function test_a_global_actor_sees_everyone(): void
    {
        $ids = $this->ids($this->actingAs($this->hr)
            ->getJson(route('employees.reporting-manager-candidates', ['employee_id' => $this->e1->employee_id]))
            ->assertOk()->json('candidates'));

        $this->assertContains((string) $this->c1->employee_id, $ids);
    }

    public function test_the_employee_and_everyone_below_are_not_candidates(): void
    {
        $this->e1b->forceFill(['report_to' => $this->e1->employee_id])->save();

        $ids = $this->ids($this->actingAs($this->hr)
            ->getJson(route('employees.reporting-manager-candidates', ['employee_id' => $this->e1->employee_id]))
            ->json('candidates'));

        $this->assertNotContains((string) $this->e1b->employee_id, $ids);
    }

    public function test_validation_rejects_a_candidate_outside_the_actors_scope_and_accepts_a_head(): void
    {
        $post = fn (string $reportTo) => $this->actingAs($this->admin)
            ->postJson(route('users.updateReportTo', $this->e1->employee_id), ['report_to' => $reportTo]);

        $post((string) $this->c1->employee_id)->assertStatus(422)->assertJsonValidationErrors('report_to');
        $this->assertNull($this->e1->fresh()->report_to);

        $post((string) $this->c2->employee_id)->assertOk();
        $this->assertSame((string) $this->c2->employee_id, (string) $this->e1->fresh()->report_to);
    }
}
