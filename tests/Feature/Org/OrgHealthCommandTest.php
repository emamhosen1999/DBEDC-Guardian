<?php

namespace Tests\Feature\Org;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

class OrgHealthCommandTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_reports_without_failing_when_there_are_no_loops(): void
    {
        $this->d1->forceFill(['manager_id' => null])->save();
        $this->e1b->forceFill(['report_to' => $this->e1->employee_id])->save();
        $this->e1->forceFill(['is_active' => false])->save();

        $this->artisan('org:health')
            ->expectsOutputToContain('Employees without a manager')
            ->expectsOutputToContain('Departments without an active head')
            ->expectsOutputToContain((string) $this->d1->name)
            ->expectsOutputToContain('Managers who are inactive or offboarded')
            ->expectsOutputToContain($this->e1b->employee_id.' '.$this->e1b->name)
            ->expectsOutputToContain('Escalation approver: present')
            ->assertExitCode(0);
    }

    public function test_warns_when_no_escalation_approver_exists(): void
    {
        $this->hr->syncRoles(['Employee']);
        $this->hrInD1->syncRoles(['Employee']);

        $this->artisan('org:health')->expectsOutputToContain('Escalation approver: NONE')->assertExitCode(0);
    }

    public function test_fails_on_a_reporting_loop(): void
    {
        $this->e1->forceFill(['report_to' => $this->e1b->employee_id])->save();
        $this->e1b->forceFill(['report_to' => $this->e1->employee_id])->save();

        $this->artisan('org:health')->expectsOutputToContain('Reporting loops: 1')->assertExitCode(1);
    }

    public function test_fails_on_a_self_reference(): void
    {
        $this->e1->forceFill(['report_to' => $this->e1->employee_id])->save();

        $this->artisan('org:health')->expectsOutputToContain('Self-references')->assertExitCode(1);
    }
}
