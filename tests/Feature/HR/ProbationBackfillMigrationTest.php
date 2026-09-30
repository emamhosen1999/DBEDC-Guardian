<?php

namespace Tests\Feature\HR;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProbationBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_confirms_old_staff_and_sets_probation_end_for_recent_joiners(): void
    {
        $old = User::factory()->create();
        $recent = User::factory()->create();
        $confirmed = User::factory()->create();

        DB::table('users')->where('employee_id', $old->employee_id)->update([
            'employment_status' => 'probationary', 'confirmation_date' => null,
            'date_of_joining' => now()->subYears(2)->toDateString(), 'probation_end_date' => null,
        ]);
        DB::table('users')->where('employee_id', $recent->employee_id)->update([
            'employment_status' => 'probationary', 'confirmation_date' => null,
            'date_of_joining' => now()->subMonth()->toDateString(), 'probation_end_date' => null,
        ]);
        DB::table('users')->where('employee_id', $confirmed->employee_id)->update([
            'employment_status' => 'confirmed', 'confirmation_date' => now()->subYear()->toDateString(),
            'date_of_joining' => now()->subYears(3)->toDateString(),
        ]);

        $migration = require database_path('migrations/2026_09_30_000003_backfill_probation_and_harden_final_settlements.php');
        $migration->up();
        $migration->up(); // idempotent

        $oldRow = DB::table('users')->where('employee_id', $old->employee_id)->first();
        $this->assertSame('confirmed', $oldRow->employment_status);
        $this->assertStringContainsString('Backfilled', $oldRow->probation_notes);

        $recentRow = DB::table('users')->where('employee_id', $recent->employee_id)->first();
        $this->assertSame('probationary', $recentRow->employment_status);
        $this->assertSame(now()->subMonth()->addMonths(6)->toDateString(), substr($recentRow->probation_end_date, 0, 10));

        $this->assertNull(DB::table('users')->where('employee_id', $confirmed->employee_id)->value('probation_notes'));
    }
}
