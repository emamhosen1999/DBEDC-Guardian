<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompOffServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_and_debit_track_balance(): void
    {
        $u = User::factory()->create();
        $svc = app(CompOffService::class);

        $svc->credit($u->id, 120, 'overtime', 7);
        $svc->credit($u->id, 60, 'holiday_work');
        $this->assertSame(180, $svc->balance($u->id));

        $svc->debit($u->id, 90, 'took half day');
        $this->assertSame(90, $svc->balance($u->id));
    }
}
