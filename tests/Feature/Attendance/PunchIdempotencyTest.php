<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PunchIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rejects_a_second_punch_in_within_the_dedupe_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));
        $user = User::factory()->create();
        $svc = app(AttendancePunchService::class);

        $first = $svc->processPunch($user, Request::create('/', 'POST', ['check_type' => 'in']));
        $this->assertSame('success', $first['status']);

        // Same instant, the just-created row was closed? No — it's open. A second 'in' must reject.
        $second = $svc->processPunch($user, Request::create('/', 'POST', ['check_type' => 'in']));
        $this->assertSame('error', $second['status']);
        $this->assertSame(422, $second['code']);

        $this->assertSame(1, Attendance::where('user_id', $user->id)->count());
    }

    public function test_rejects_rapid_duplicate_toggle_punch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));
        $user = User::factory()->create();
        $svc = app(AttendancePunchService::class);

        $svc->processPunch($user, Request::create('/', 'POST', [])); // punch in (toggle)
        // 5 seconds later a duplicate toggle would punch OUT immediately — guard it.
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:05'));
        $dup = $svc->processPunch($user, Request::create('/', 'POST', []));

        $this->assertSame('error', $dup['status']);
        $this->assertSame(429, $dup['code']);
    }
}
