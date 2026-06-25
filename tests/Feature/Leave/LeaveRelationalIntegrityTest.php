<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveRelationalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function leaves_user_id_has_an_enforced_foreign_key(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $fks = collect(DB::select('PRAGMA foreign_key_list(leaves)'));
            $this->assertTrue(
                $fks->contains(fn ($fk) => $fk->from === 'user_id' && $fk->table === 'users'),
                'leaves.user_id should reference users.id'
            );
        } else {
            $rows = DB::select(
                'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_NAME = "leaves" AND COLUMN_NAME = "user_id"
                 AND REFERENCED_TABLE_NAME = "users" AND TABLE_SCHEMA = DATABASE() LIMIT 1'
            );
            $this->assertNotEmpty($rows, 'leaves.user_id should reference users.id');
        }
    }

    /** @test */
    public function leave_belongs_to_user_and_setting_via_relationships(): void
    {
        $user = User::factory()->create();
        $type = LeaveSetting::factory()->create();
        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-04-01', 'to_date' => '2026-04-01',
            'no_of_days' => 1, 'reason' => 'x', 'status' => 'pending',
        ]);

        $this->assertTrue($leave->user->is($user));
        $this->assertTrue($leave->leaveSetting->is($type));
    }
}
