<?php

namespace Tests\Feature\Aeon;

use App\Services\Aeon\Tools\AssetMaintenanceTool;
use App\Services\Aeon\Tools\ExecutiveBriefingTool;
use App\Services\Aeon\Tools\ExpresswayIntelligenceTool;
use App\Services\Aeon\Tools\PettyCashTool;
use App\Services\Aeon\Tools\QualityAssuranceTool;
use App\Services\Aeon\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * Owner rule (2026-10-08): no mock-up, demo or illustrative data anywhere, AI tools included. The specialised Aeon
 * tools answer from Guardian's own rows, inside the asker's scope, and say so honestly when a register is empty.
 */
class AeonToolsRealDataTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_petty_cash_reports_the_real_ledger_within_the_approvers_scope(): void
    {
        // Global approver (HR Manager is company-wide): sees D2's active advance of 5,000 with its 100 fuel expense.
        $this->hr->givePermissionTo('petty-cash.approve');
        $summary = app(PettyCashTool::class)->run(['action' => 'summary'], $this->hr->employee_id);
        $this->assertSame(1, $summary['data']['active_count']);
        $this->assertEquals(5000, $summary['data']['outstanding_bdt']);
        $this->assertEquals(100, $summary['data']['spent_this_month_bdt']);

        $categories = app(PettyCashTool::class)->run(['action' => 'category_breakdown'], $this->hr->employee_id);
        $this->assertSame([['label' => 'Fuel', 'value' => 100.0]], $categories['data']['categories']);

        // A D1 department admin approves only inside D1: D2's ledger never reaches them, not even its text.
        $this->admin->givePermissionTo('petty-cash.approve');
        $scoped = app(PettyCashTool::class)->run(['action' => 'summary'], $this->admin->employee_id);
        $this->assertTrue($scoped['data']['empty']);
        $recent = app(PettyCashTool::class)->run(['action' => 'recent_transactions'], $this->admin->employee_id);
        $this->assertStringNotContainsString(self::MARKER, json_encode($recent));
    }

    public function test_asset_tool_shows_the_register_and_says_so_when_it_is_empty(): void
    {
        $this->hr->givePermissionTo(['om.equipment.view', 'om.maintenance.view']);
        $tool = app(AssetMaintenanceTool::class);

        $this->assertSame('No assets or equipment are registered yet.', $tool->run(['action' => 'equipment_health'], $this->hr->employee_id)['text']);
        $this->assertSame('There are no open maintenance work orders.', $tool->run(['action' => 'work_orders'], $this->hr->employee_id)['text']);
        $this->assertSame('No message is active on any variable message sign.', $tool->run(['action' => 'vms_signs'], $this->hr->employee_id)['text']);

        DB::table('om_assets')->insert(['asset_code' => 'AS-T1', 'name' => 'Gantry sign', 'category' => 'signage_marking', 'start_chainage' => 'K12+500', 'operational_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $health = $tool->run(['action' => 'equipment_health'], $this->hr->employee_id);
        $this->assertSame(1, $health['data']['assets']);
        $this->assertStringContainsString('AS-T1 · Gantry sign', json_encode($health['blocks'], JSON_UNESCAPED_UNICODE));
    }

    public function test_expressway_tool_resolves_chainage_against_recorded_jurisdictions(): void
    {
        $this->assertSame(12500, ExpresswayIntelligenceTool::chainageMeters('K12+500'));
        $this->assertSame(14200, ExpresswayIntelligenceTool::chainageMeters('Ch 14+200'));
        $this->assertSame(14200, ExpresswayIntelligenceTool::chainageMeters('14.2'));
        $this->assertNull(ExpresswayIntelligenceTool::chainageMeters('somewhere'));

        $this->hr->givePermissionTo('om.dashboard.view');
        DB::table('jurisdictions')->insert(['location' => 'Phase One', 'start_chainage' => 'K0+000', 'end_chainage' => 'K27+999', 'incharge' => $this->e1->employee_id, 'created_at' => now(), 'updated_at' => now()]);
        $tool = app(ExpresswayIntelligenceTool::class);

        $hit = $tool->run(['action' => 'chainage_lookup', 'chainage' => 'K12+500'], $this->hr->employee_id);
        $this->assertSame('Phase One', $hit['data']['jurisdiction']);
        $this->assertSame($this->e1->name, $hit['data']['incharge']);
        $this->assertStringContainsString('outside every recorded jurisdiction', $tool->run(['action' => 'chainage_lookup', 'chainage' => 'K40+000'], $this->hr->employee_id)['text']);
        $this->assertSame('There are no open incidents.', $tool->run(['action' => 'active_incidents'], $this->hr->givePermissionTo('om.incidents.view')->employee_id)['text']);
        $this->assertSame('No toll transactions have been recorded today.', $tool->run(['action' => 'toll_summary'], $this->hr->employee_id)['text']);
    }

    public function test_quality_tool_counts_real_rows(): void
    {
        $this->hr->givePermissionTo('daily-works.view');
        $result = app(QualityAssuranceTool::class)->run(['action' => 'site_instructions'], $this->hr->employee_id);
        $this->assertSame('There are no open site instructions.', $result['text']);

        DB::table('site_instructions')->insert(['si_number' => 'SI-T1', 'category' => 'road', 'location' => 'K3', 'description' => 'Repair the kerb', 'status' => 'open', 'issued_date' => self::DAY, 'created_at' => now(), 'updated_at' => now()]);
        $result = app(QualityAssuranceTool::class)->run(['action' => 'site_instructions'], $this->hr->employee_id);
        $this->assertStringContainsString('SI-T1', json_encode($result['blocks']));
    }

    public function test_briefing_is_built_only_from_widgets_the_viewer_may_see(): void
    {
        $this->assertFalse(app(ExecutiveBriefingTool::class)->availableTo($this->e1));
        $this->assertNotContains('executive_briefing', array_column(app(ToolRegistry::class)->declarationsFor($this->e1->employee_id), 'name'));

        $briefing = app(ExecutiveBriefingTool::class)->run([], $this->hr->employee_id);
        $this->assertArrayNotHasKey('error', $briefing['data']);
        $this->assertNotEmpty($briefing['data']['pillars']);
        $this->assertArrayHasKey('team.today', $briefing['data']['pillars']);
        $this->assertStringNotContainsString('98.2', json_encode($briefing));
    }

    public function test_no_tool_ships_the_old_illustrative_figures(): void
    {
        $fabricated = ['PV-2026-1', 'WO-2026-04', 'INC-2026-00', 'NCR-2026-089', '68.4%', '107,350', '142,650', 'DG Set', '64/64', 'Joydebpur Roundabout', '93.0% First-Pass', 'Health Index'];
        foreach (glob(app_path('Services/Aeon/Tools/*.php')) as $file) {
            $source = file_get_contents($file);
            foreach ($fabricated as $value) {
                $this->assertStringNotContainsString($value, $source, basename($file)." still contains the illustrative value {$value}");
            }
        }
    }
}
