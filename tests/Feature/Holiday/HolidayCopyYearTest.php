<?php

namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Services\Holiday\HolidayImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayCopyYearTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_clones_a_source_years_holidays_into_the_target_year(): void
    {
        Holiday::create(['title' => 'New Year', 'from_date' => '2025-01-01', 'to_date' => '2025-01-01', 'type' => 'national', 'is_active' => true]);
        Holiday::create(['title' => 'May Day', 'from_date' => '2025-05-01', 'to_date' => '2025-05-02', 'type' => 'public', 'is_active' => true]);

        $created = app(HolidayImportService::class)->copyYear(2025, 2026);

        $this->assertSame(2, $created);

        $newYear = Holiday::whereYear('from_date', 2026)->where('title', 'New Year')->first();
        $this->assertNotNull($newYear);
        $this->assertSame('2026-01-01', \Carbon\Carbon::parse($newYear->from_date)->toDateString());

        $mayDay = Holiday::whereYear('from_date', 2026)->where('title', 'May Day')->first();
        $this->assertNotNull($mayDay);
        $this->assertSame('2026-05-01', \Carbon\Carbon::parse($mayDay->from_date)->toDateString());
        $this->assertSame('2026-05-02', \Carbon\Carbon::parse($mayDay->to_date)->toDateString());
    }

    /** @test */
    public function it_skips_a_clone_that_would_overlap_an_existing_target_holiday(): void
    {
        Holiday::create(['title' => 'New Year', 'from_date' => '2025-01-01', 'to_date' => '2025-01-01', 'type' => 'national', 'is_active' => true]);
        Holiday::create(['title' => 'Already there', 'from_date' => '2026-01-01', 'to_date' => '2026-01-01', 'type' => 'company', 'is_active' => true]);

        $created = app(HolidayImportService::class)->copyYear(2025, 2026);

        $this->assertSame(0, $created);
        $this->assertSame(1, Holiday::whereYear('from_date', 2026)->count());
    }
}
