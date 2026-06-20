<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegularizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_and_casts(): void
    {
        $u = User::factory()->create();
        $r = AttendanceRegularization::create([
            'user_id' => $u->id, 'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
            'approval_chain' => [['level' => 1, 'approver_id' => 9]],
        ]);
        $fresh = $r->fresh();
        $this->assertSame('2026-06-18', $fresh->date->toDateString());
        $this->assertSame(9, $fresh->approval_chain[0]['approver_id']);
        $this->assertFalse($fresh->applied);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($u->id, $fresh->user->id);
    }
}
