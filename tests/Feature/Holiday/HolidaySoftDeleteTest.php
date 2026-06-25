<?php

namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidaySoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_soft_deleted_holiday_is_recoverable_and_drops_out_of_forRange(): void
    {
        $h = Holiday::create([
            'title' => 'Test', 'from_date' => '2026-05-01', 'to_date' => '2026-05-01',
            'type' => 'public', 'is_active' => true, 'recurrence_pattern' => 'none',
        ]);

        $h->delete(); // soft

        $this->assertSoftDeleted('holidays', ['id' => $h->id]);
        $occ = app(HolidayService::class)->forRange(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $this->assertCount(0, $occ);

        $h->restore();
        $occ = app(HolidayService::class)->forRange(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $this->assertCount(1, $occ);
    }
}
