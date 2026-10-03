<?php

namespace Tests\Feature\Access;

use App\Services\Access\CatalogApplier;
use App\Services\Access\CatalogPlan;
use App\Services\Access\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Access\Concerns\BuildsCatalogWorld;
use Tests\TestCase;

/**
 * The approved assignment plan (database/access-plans/catalog_v1.json) is internally consistent and says what the
 * owner decided: section 5 of docs/audit/ROLE_CATALOG_2026-10-03.md, with O-2, O-3, O-4, O-5, O-6, O-12 and Q3 applied.
 * Checked three ways: the plan file alone, recomputed from the production role sets it embeds, and against a database
 * that holds the same 35 people.
 */
class AssignmentPlanTest extends TestCase
{
    use BuildsCatalogWorld;
    use RefreshDatabase;

    private const QC_MEMBERS = ['7', '97', '120', '122', '123', '126', '127', '131', '142', '143', '145', '149', '152', '159', '169', '272', '304', '307', '356', '538', '896', '1536'];

    private const LINE_MANAGER_PERMISSIONS = ['attendance.view', 'employees.view', 'holidays.view', 'leaves.approve', 'leaves.view'];

    private CatalogPlan $loaded;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loaded = CatalogPlan::load();
    }

    /** @return array<string, array{gained: array<int, string>, lost: array<int, string>}> */
    private function effectiveDelta(): array
    {
        $before = $this->loaded->data['role_sets_before'];
        $every = array_values(array_unique(array_merge(array_keys(RoleCatalog::NEW_PERMISSIONS), ...array_values($before))));
        $delta = [];

        foreach ($this->loaded->users() as $id => $entry) {
            $was = $entry['current']['direct_permissions'];
            foreach ($entry['current']['roles'] as $role) {
                $was = array_merge($was, $before[$role]);
            }
            $will = $entry['target']['direct_permissions'];
            foreach ($entry['target']['roles'] as $role) {
                $will = array_merge($will, RoleCatalog::permissionsFor($role, $every));
            }
            $isSuperAdmin = in_array('Super Administrator', $entry['target']['roles'], true);
            $delta[(string) $id] = [
                'gained' => $isSuperAdmin ? [] : array_values(array_diff(array_unique($will), array_unique($was))),
                'lost' => $isSuperAdmin ? [] : array_values(array_diff(array_unique($was), array_unique($will))),
            ];
        }

        return $delta;
    }

    public function test_the_plan_is_internally_consistent(): void
    {
        $this->assertSame([], $this->loaded->staticErrors());
        $this->assertCount(35, $this->loaded->users());
        $this->assertSame(1, $this->loaded->data['version']);
    }

    public function test_the_hash_ignores_formatting_but_not_content(): void
    {
        $reformatted = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($reformatted, json_encode($this->loaded->data));
        $this->assertSame($this->loaded->hash(), CatalogPlan::load($reformatted)->hash());

        $edited = $this->loaded->data;
        $edited['users']['130']['target']['roles'][] = 'Administrator';
        file_put_contents($reformatted, json_encode($edited));
        $this->assertNotSame($this->loaded->hash(), CatalogPlan::load($reformatted)->hash());
        $this->assertNotSame([], CatalogPlan::load($reformatted)->staticErrors(), 'a hand edit also breaks the recomputed expected_losses');
        unlink($reformatted);
    }

    public function test_every_user_keeps_employee_and_only_catalog_roles_are_targeted(): void
    {
        foreach ($this->loaded->users() as $id => $entry) {
            $this->assertContains('Employee', $entry['target']['roles'], "{$id} holds Employee");
            foreach ($entry['target']['roles'] as $role) {
                $this->assertContains($role, RoleCatalog::roleNames(), "{$id}: {$role} is a catalog role");
                $this->assertNotContains($role, RoleCatalog::RETIRED);
            }
        }
    }

    public function test_the_owner_decisions_are_in_the_plan(): void
    {
        $roles = fn (string $id) => $this->loaded->targetRoles($id);

        // O-2: 123 gains Quality Manager + Daily Works Manager; he and 896 also get Employee
        foreach (['123', '896'] as $id) {
            $this->assertEqualsCanonicalizing(['Employee', 'Department Manager', 'Daily Works Manager', 'Quality Manager', 'Quality Contributor', 'Daily Works Contributor', ...($id === '896' ? ['O&M Director'] : [])], $roles($id));
        }
        $this->assertEqualsCanonicalizing(['Employee', 'Department Admin', 'Department Manager', 'Quality Manager', 'Daily Works Manager', 'Quality Contributor', 'Daily Works Contributor'], $roles('169'));

        // O-3 + O-4: 127 keeps his breadth (Maintenance Inspector) and is also a Line Manager
        $this->assertEqualsCanonicalizing(['Employee', 'Maintenance Inspector', 'Quality Manager', 'Daily Works Manager', 'Line Manager', 'Quality Contributor', 'Daily Works Contributor'], $roles('127'));
        foreach (['120', '126', '127', '1536', '130'] as $id) {
            $this->assertContains('Line Manager', $roles($id), "O-4: {$id}");
        }
        $this->assertSame(['Employee', 'Line Manager'], $this->sortedRoles($roles('130')), '130 is in Contract, not Quality Control');

        // O-6: Quality Contributor is a Quality Control default role, so every QC member holds it
        foreach (self::QC_MEMBERS as $id) {
            $this->assertContains('Quality Contributor', $roles($id), "O-6: {$id}");
            $this->assertContains('Daily Works Contributor', $roles($id));
        }
        $this->assertCount(22, self::QC_MEMBERS);
        $this->assertSame(['11' => ['Daily Works Contributor', 'Quality Contributor']], $this->loaded->departmentDefaultRoles());
        foreach ($this->loaded->users() as $id => $entry) {
            if (! in_array((string) $id, self::QC_MEMBERS, true)) {
                $this->assertNotContains('Quality Contributor', $roles((string) $id), "{$id} is not in Quality Control");
            }
        }

        // O-5: TMC operators keep TMC Operator (the role loses daily-works.* in the exact migration)
        foreach (['306', '308', '309', '397'] as $id) {
            $this->assertSame(['Employee', 'TMC Operator'], $this->sortedRoles($roles($id)));
        }

        // O-12: orphan role rows go, and Admin is deleted once nobody holds it
        $this->assertTrue($this->loaded->detachesRolesFromInactiveUsers());
        $this->assertSame(['Admin'], $this->loaded->rolesToDeleteWhenUnheld());

        // Not decided, so unchanged: 151 stays SA + Employee; 1537 keeps his documented exception
        $this->assertSame(['Super Administrator', 'Employee'], $this->unsortedRoles($roles('151')));
        $this->assertSame($this->loaded->users()['151']['current']['roles'], $this->unsortedRoles($roles('151')));
        $this->assertSame(['access.self-administration'], $this->loaded->targetDirectPermissions('1537'));
    }

    public function test_expected_losses_are_the_matrix_losses_and_never_tasks(): void
    {
        $losses = array_filter(array_map(fn ($entry) => $entry['expected_losses'], $this->loaded->users()));

        // 123 and 169: 5 covered + 26 dead; 896: the same + 15 compliance; the four TMC operators: daily-works.* (O-5)
        $this->assertEqualsCanonicalizing(['123', '169', '896', '306', '308', '309', '397'], array_map('strval', array_keys($losses)));
        $this->assertCount(31, $losses['123']);
        $this->assertCount(31, $losses['169']);
        $this->assertCount(46, $losses['896']);
        foreach (['306', '308', '309', '397'] as $id) {
            $this->assertSame(['daily-works.create', 'daily-works.delete', 'daily-works.export', 'daily-works.import', 'daily-works.update', 'daily-works.view'], $losses[$id]);
        }
        foreach ($losses as $id => $lost) {
            $this->assertSame([], preg_grep('/^tasks\./', $lost), "Q3: {$id} keeps every tasks.* permission");
            $recomputed = $this->effectiveDelta()[(string) $id]['lost'];
            sort($recomputed);
            $this->assertSame($lost, $recomputed, "{$id}: expected_losses equals before minus after");
        }
        // the covered ones the matrix names: 123 / 169 / 896 lose hr.timeoff.approve, projects.analytics, performance-reviews.{view,update,approve}
        foreach (['hr.timeoff.approve', 'projects.analytics', 'performance-reviews.view', 'performance-reviews.update', 'performance-reviews.approve'] as $covered) {
            $this->assertContains($covered, $losses['123']);
        }
        $this->assertContains('compliance.view', $losses['896']);
        $this->assertNotContains('compliance.view', $losses['123']);
    }

    public function test_nobody_loses_anything_in_use_and_quality_staff_lose_nothing_at_all(): void
    {
        $delta = $this->effectiveDelta();

        foreach (self::QC_MEMBERS as $id) {
            if (! in_array($id, ['123', '169', '896'], true)) {
                $this->assertSame([], $delta[$id]['lost'], "{$id} loses nothing");
            }
        }
        // O-3: Habibur's breadth is preserved exactly; he only gains the Line Manager permissions
        $this->assertSame([], $delta['127']['lost']);
        $this->assertEqualsCanonicalizing(self::LINE_MANAGER_PERMISSIONS, $delta['127']['gained']);
        // 130: a Line Manager gains exactly those five
        $this->assertEqualsCanonicalizing(self::LINE_MANAGER_PERMISSIONS, $delta['130']['gained']);
        // the gains the matrix counts: 123 +25 (QM 11 beyond his DM, Employee 14), 169 +11, 896 +14 (Employee)
        $this->assertCount(25, $delta['123']['gained']);
        $this->assertCount(11, $delta['169']['gained']);
        $this->assertCount(14, $delta['896']['gained']);
        foreach (['123', '169'] as $id) {
            $this->assertContains('quality.ncr.delete', $delta[$id]['gained']);
        }
    }

    public function test_the_eight_unchanged_people_and_the_eight_removed_direct_grants(): void
    {
        $delta = $this->effectiveDelta();
        $unchanged = array_keys(array_filter($delta, fn ($d, $id) => $d['gained'] === [] && $d['lost'] === [], ARRAY_FILTER_USE_BOTH));
        $this->assertEqualsCanonicalizing(['154', '155', '301', '302', '310', '151', '1537', '160002'], array_map('strval', $unchanged));

        $removed = [];
        foreach ($this->loaded->users() as $id => $entry) {
            foreach (array_diff($entry['current']['direct_permissions'], $entry['target']['direct_permissions']) as $permission) {
                $removed[] = "{$id}:{$permission}";
            }
        }
        $this->assertEqualsCanonicalizing([
            '120:leave.own.view', '122:leave.own.view', '123:leave.own.view', '126:leave.own.view', '127:leave.own.view', '130:leave.own.view',
            '131:leave.own.view', '169:attendance.export',
        ], $removed);
        foreach ($removed as $grant) {
            [$id, $permission] = explode(':', $grant);
            $this->assertSame([], array_intersect([$permission], $delta[$id]['lost']), "{$grant} is not a loss: a role still carries it");
        }
    }

    public function test_the_mahdi_exception_is_never_revoked(): void
    {
        $this->assertContains('access.self-administration', $this->loaded->users()['1537']['current']['direct_permissions']);
        $this->assertContains('access.self-administration', $this->loaded->targetDirectPermissions('1537'));
    }

    public function test_the_plan_matches_a_database_holding_the_same_people(): void
    {
        $this->buildCatalogWorld('current');

        $report = app(CatalogApplier::class)->plan($this->loaded);

        $this->assertSame([], $report['errors'], 'users and roles exist, Employee everywhere, no unexpected loss');
        $this->assertSame([], $report['warnings']);
        $this->assertCount(35, $report['users']);
        $this->assertSame(['11' => ['current' => ['Daily Works Contributor', 'Quality Contributor'], 'target' => ['Daily Works Contributor', 'Quality Contributor'], 'changes' => false]], $report['defaults']);
    }

    /** @param array<int, string> $roles */
    private function sortedRoles(array $roles): array
    {
        sort($roles);

        return $roles;
    }

    private function unsortedRoles(array $roles): array
    {
        return array_values($roles);
    }
}
