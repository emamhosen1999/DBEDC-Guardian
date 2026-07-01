<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\RosterOverlayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RosterOverlayServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeaveType(string $symbol = 'AL'): LeaveSetting
    {
        return LeaveSetting::create(['type' => 'Annual Leave', 'symbol' => $symbol, 'days' => 20]);
    }

    public function test_returns_approved_full_and_half_day_leave_and_holidays(): void
    {
        $svc = app(RosterOverlayService::class);
        $user = User::factory()->create();
        $type = $this->makeLeaveType('AL');

        // Full-day approved leave across two in-range days.
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-07',
            'status' => 'approved', 'is_half_day' => false, 'no_of_days' => 2.0, 'reason' => 'Vacation',
        ]);
        // Half-day approved leave (single date, PM).
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-09', 'to_date' => '2026-07-09',
            'status' => 'approved', 'is_half_day' => true, 'half_day_session' => 'second_half', 'no_of_days' => 0.5, 'reason' => 'Doctor appointment',
        ]);
        Holiday::create([
            'title' => 'Independence Day', 'from_date' => '2026-07-10',
            'to_date' => '2026-07-10', 'type' => 'national', 'is_active' => true,
        ]);

        $out = $svc->forRange([$user->id], '2026-07-01', '2026-07-31');

        $this->assertSame('AL', $out['leave'][$user->id]['2026-07-06']['type']);
        $this->assertSame(1.0, $out['leave'][$user->id]['2026-07-06']['fraction']);
        $this->assertSame('approved', $out['leave'][$user->id]['2026-07-07']['status']);
        $this->assertSame(0.5, $out['leave'][$user->id]['2026-07-09']['fraction']);
        $this->assertSame('second_half', $out['leave'][$user->id]['2026-07-09']['session']);
        $this->assertSame('Independence Day', $out['holidays']['2026-07-10']);
    }

    public function test_excludes_rejected_and_marks_pending_separately_with_approved_priority(): void
    {
        $svc = app(RosterOverlayService::class);
        $user = User::factory()->create();
        $type = $this->makeLeaveType('SL');

        Leave::create([ // rejected — never shown
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'rejected', 'is_half_day' => false, 'no_of_days' => 1.0, 'reason' => 'Rejected',
        ]);
        Leave::create([ // pending — shown as pending
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-08', 'to_date' => '2026-07-08',
            'status' => 'pending', 'is_half_day' => false, 'no_of_days' => 1.0, 'reason' => 'Pending',
        ]);
        // approved on the same date as a pending → approved wins.
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-12', 'to_date' => '2026-07-12',
            'status' => 'pending', 'is_half_day' => false, 'no_of_days' => 1.0, 'reason' => 'Pending request',
        ]);
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-12', 'to_date' => '2026-07-12',
            'status' => 'approved', 'is_half_day' => false, 'no_of_days' => 1.0, 'reason' => 'Approved request',
        ]);

        $out = $svc->forRange([$user->id], '2026-07-01', '2026-07-31');

        $this->assertArrayNotHasKey('2026-07-06', $out['leave'][$user->id] ?? []);
        $this->assertSame('pending', $out['leave'][$user->id]['2026-07-08']['status']);
        $this->assertSame('approved', $out['leave'][$user->id]['2026-07-12']['status']);
    }

    public function test_query_count_is_bounded_regardless_of_users(): void
    {
        $svc = app(RosterOverlayService::class);
        $users = User::factory()->count(5)->create();

        DB::enableQueryLog();
        $svc->forRange($users->pluck('id')->all(), '2026-07-01', '2026-07-31');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 leaves query + HolidayService's 1 holidays query = constant (allow small slack).
        $this->assertLessThanOrEqual(3, $count);
    }
}
