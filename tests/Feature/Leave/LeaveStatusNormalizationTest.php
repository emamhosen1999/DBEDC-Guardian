<?php
// tests/Feature/Leave/LeaveStatusNormalizationTest.php
namespace Tests\Feature\Leave;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveStatusNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_maps_legacy_status_values_to_the_canonical_set(): void
    {
        // Insert legacy-cased rows directly, bypassing the model, to mimic prod data.
        $userId = \App\Models\User::factory()->create()->id;
        $typeId = \App\Models\HRM\LeaveSetting::factory()->create()->id;

        foreach (['Approved', 'New', 'Pending', 'Declined', 'rejected', 'CANCELLED'] as $i => $legacy) {
            DB::table('leaves')->insert([
                'user_id' => $userId,
                'leave_type' => $typeId,
                'from_date' => '2026-01-0'.($i + 1),
                'to_date' => '2026-01-0'.($i + 1),
                'no_of_days' => 1,
                'reason' => 'x',
                'status' => $legacy,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Re-run the normalization migration logic against the seeded rows.
        // First roll back so it re-executes (RefreshDatabase already ran it on an empty table).
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_25_000001_normalize_leaves_status.php', '--force' => true]);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_06_25_000001_normalize_leaves_status.php', '--force' => true]);

        $statuses = DB::table('leaves')->pluck('status')->map(fn ($s) => strtolower($s))->unique()->values()->all();
        sort($statuses);

        $this->assertSame(['approved', 'cancelled', 'pending', 'rejected'], $statuses);
        $this->assertSame(2, DB::table('leaves')->where('status', 'pending')->count()); // New + Pending
        $this->assertSame(2, DB::table('leaves')->where('status', 'rejected')->count()); // Declined + rejected
        $this->assertSame(1, DB::table('leaves')->where('status', 'approved')->count());
        $this->assertSame(1, DB::table('leaves')->where('status', 'cancelled')->count());
    }
}
