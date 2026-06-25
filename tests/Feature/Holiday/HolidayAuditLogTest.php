<?php

namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Models\HRM\HolidayAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class HolidayAuditLogTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function recording_writes_an_immutable_row(): void
    {
        $actor = User::factory()->create();
        Auth::login($actor);
        $holiday = Holiday::create([
            'title' => 'Victory Day', 'from_date' => '2026-12-16', 'to_date' => '2026-12-16',
            'type' => 'national', 'is_active' => true,
        ]);

        app(\App\Services\Holiday\HolidayAuditService::class)
            ->record('create', $holiday->id, null, $holiday->toArray());

        $log = HolidayAuditLog::where('holiday_id', $holiday->id)->where('action', 'create')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertNull(HolidayAuditLog::UPDATED_AT);
    }
}
