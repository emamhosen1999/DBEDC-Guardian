<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_holiday_not_counted_in_month_total(): void
    {
        Holiday::create(['title'=>'Inactive','from_date'=>'2026-06-10','to_date'=>'2026-06-10','type'=>'company','is_active'=>false,'is_recurring'=>false]);
        $svc = app(AttendanceReportService::class);
        $this->assertSame(0, $svc->getTotalHolidayDays(2026, 6));
        $this->assertCount(0, $svc->getHolidaysForMonth(2026, 6));
    }

    public function test_annual_fixed_holiday_counted_in_later_year_month(): void
    {
        Holiday::create(['title'=>'Independence Day','from_date'=>'2024-03-26','to_date'=>'2024-03-26','type'=>'national','is_active'=>true,'is_recurring'=>true,'recurrence_pattern'=>'annual_fixed']);
        $svc = app(AttendanceReportService::class);
        $this->assertSame(1, $svc->getTotalHolidayDays(2026, 3));
        $this->assertCount(1, $svc->getHolidaysForMonth(2026, 3));
    }
}
