<?php

namespace Tests\Feature\DailyWork;

class DailyWorkSummaryDataTest extends DailyWorkTestCase
{
    public function test_the_summary_is_database_aggregates_over_the_viewers_scope(): void
    {
        $p = $this->phases();
        [$a, $b] = [$p['people'][0]->employee_id, $p['people'][1]->employee_id];
        $d = now()->subDays(10)->toDateString();
        $this->work('RFI-1', 'K05+100', $d, 'completed', $a, null, 'Pavement');
        $this->work('RFI-2', 'K12+000', $d, 'new', $a, null, 'Structure');
        $this->work('RFI-3', 'K30+500', $d, 'completed', $b, null, 'Pavement');
        $this->work('RFI-4', 'Main gate', $d, 'rejected', $b, null, 'Pavement');
        \DB::table('daily_works')->where('number', 'RFI-1')->update(['inspection_result' => 'pass', 'completion_time' => now()->subDays(8), 'rfi_submission_date' => now()->subDays(10)->toDateString()]);
        \DB::table('daily_works')->where('number', 'RFI-3')->update(['inspection_result' => 'fail', 'completion_time' => now()->subDays(9), 'resubmission_count' => 1]);

        $res = $this->actingAs($this->dwAdmin())->getJson(route('dailyWorks.summaryData'))->assertOk();
        $t = $res->json('totals');
        $this->assertSame([4, 2, 1, 1, 2, 1, 1], [$t['works'], $t['completed'], $t['passed'], $t['failed'], $t['open'], $t['resubmitted'], $t['unplaced']]);
        $this->assertSame(50.0, (float) $t['completion_rate']);
        $this->assertSame(50.0, (float) $t['first_time_pass_rate']);
        $this->assertSame(['Pavement' => 3, 'Structure' => 1], collect($res->json('by_type'))->pluck('count', 'key')->all());
        $this->assertSame(4, array_sum(array_column($res->json('trend.points'), 'works')));
        $this->assertSame($a, $res->json('by_incharge.0.id') === $a || $res->json('by_incharge.1.id') === $a ? $a : null);
        $this->assertSame(2, $res->json('turnaround.samples'));
        $this->assertSame(1, $res->json('turnaround.fallback_samples'), 'one record had no RFI submission date and used the work date');
        $this->assertNull($res->json('empty'));
    }

    public function test_a_quiet_period_says_when_the_latest_record_is_dated_instead_of_showing_zeros(): void
    {
        $p = $this->phases();
        $this->work('RFI-OLD', 'K05+100', '2026-06-05', 'new', $p['people'][0]->employee_id, null, 'Pavement');
        $res = $this->actingAs($this->dwAdmin())->getJson(route('dailyWorks.summaryData', ['period' => '30d']))->assertOk();
        $this->assertSame(0, $res->json('totals.works'));
        $this->assertSame('2026-06-05', $res->json('empty.latest_work_date'));
        $this->assertSame(1, $res->json('empty.scoped_total'));
        $this->actingAs($this->dwAdmin())->getJson(route('dailyWorks.summaryData', ['period' => 'all']))->assertJsonPath('totals.works', 1);
    }
}
