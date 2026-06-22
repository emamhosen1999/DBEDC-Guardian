<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Offboarding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TerminationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_offboarding_relation_returns_latest_non_cancelled_record(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();

        Offboarding::forceCreate([
            'employee_id' => $user->id,
            'initiation_date' => '2026-05-01',
            'last_working_date' => '2026-05-31',
            'reason' => Offboarding::REASON_RESIGNATION,
            'status' => Offboarding::STATUS_CANCELLED,
            'created_by' => $admin->id,
        ]);
        $active = Offboarding::forceCreate([
            'employee_id' => $user->id,
            'initiation_date' => '2026-06-01',
            'last_working_date' => '2026-06-15',
            'reason' => Offboarding::REASON_TERMINATION,
            'status' => Offboarding::STATUS_IN_PROGRESS,
            'created_by' => $admin->id,
        ]);

        $resolved = $user->fresh()->offboarding;

        $this->assertNotNull($resolved);
        $this->assertSame($active->id, $resolved->id);
        $this->assertSame('2026-06-15', $resolved->last_working_date->toDateString());
    }
}
