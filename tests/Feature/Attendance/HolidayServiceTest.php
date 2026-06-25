<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_holiday_is_excluded(): void
    {
        Holiday::create(['title'=>'Inactive','from_date'=>'2026-06-10','to_date'=>'2026-06-10','type'=>'company','is_active'=>false,'is_recurring'=>false]);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,6,1), Carbon::create(2026,6,30));
        $this->assertCount(0, $out);
    }

    public function test_one_off_active_holiday_in_range_is_included(): void
    {
        Holiday::create(['title'=>'Company Day','from_date'=>'2026-06-10','to_date'=>'2026-06-10','type'=>'company','is_active'=>true,'is_recurring'=>false]);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,6,1), Carbon::create(2026,6,30));
        $this->assertCount(1, $out);
        $this->assertSame('2026-06-10', Carbon::parse($out->first()->from_date)->toDateString());
    }

    public function test_annual_fixed_recurring_holiday_recurs_in_a_later_year(): void
    {
        // Declared in 2024; must appear on the same month-day in 2026.
        Holiday::create(['title'=>'Independence Day','from_date'=>'2024-03-26','to_date'=>'2024-03-26','type'=>'national','is_active'=>true,'is_recurring'=>true,'recurrence_pattern'=>'annual_fixed']);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,3,1), Carbon::create(2026,3,31));
        $this->assertCount(1, $out);
        $this->assertSame('2026-03-26', Carbon::parse($out->first()->from_date)->toDateString());
    }

    public function test_non_recurring_past_holiday_does_not_recur(): void
    {
        Holiday::create(['title'=>'Eid (lunar, one-off)','from_date'=>'2024-04-10','to_date'=>'2024-04-10','type'=>'religious','is_active'=>true,'is_recurring'=>false]);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,4,1), Carbon::create(2026,4,30));
        $this->assertCount(0, $out);
    }
}
