<?php

namespace Tests\Feature\DailyWork;

class DailyWorkExportTest extends DailyWorkTestCase
{
    public function test_export_follows_the_filters_the_scope_and_the_permission(): void
    {
        $p = $this->phases();
        $a = $p['people'][0]->employee_id;
        $this->work('RFI-A', 'K05+100', '2026-05-04', 'new', $a, null, 'Pavement');
        $this->work('RFI-B', 'K12+000', '2026-05-05', 'new', $a, null, 'Structure');
        \DB::table('daily_works')->where('number', 'RFI-A')->update(['description' => '=HYPERLINK("x")']);
        $admin = $this->dwAdmin();

        $this->actingAs($this->viewer(['view']))->get(route('dailyWorks.exportFile'))->assertForbidden();
        $this->actingAs($admin)->getJson(route('dailyWorks.exportCount', ['date_from' => '2026-05-05']))->assertOk()->assertJsonPath('rows', 1);

        $csv = $this->actingAs($admin)->get(route('dailyWorks.exportFile', ['date_from' => '2026-05-05']))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('RFI-B', $csv);
        $this->assertStringNotContainsString('RFI-A', $csv);

        $all = $this->actingAs($admin)->get(route('dailyWorks.exportFile', ['columns' => ['number', 'description']]))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $all, 'formula-looking text is neutralised');
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'daily-works.exported']);
        $this->actingAs($admin)->get(route('dailyWorks.exportFile', ['format' => 'xls']))->assertOk()->assertHeader('X-Export-Rows', '2');
    }
}
