<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Services\Attendance\CompOffService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The replacement-leave ledger counts DAYS (the old minute model is retired; its columns stay for history). */
class CompOffServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_credits_track_the_balance_in_days_with_a_60_day_expiry(): void
    {
        $u = User::factory()->create();
        $svc = app(CompOffService::class);

        $a = $svc->credit($u->id, 1.0, 'overtime', 7, null, Carbon::parse('2026-10-01'));
        $svc->credit($u->id, 0.5, 'overtime', 8, null, Carbon::parse('2026-10-05'));

        $this->assertSame(1.5, $svc->balance($u->id));
        $this->assertSame('2026-11-30', $a->expires_at->toDateString());
    }
}
