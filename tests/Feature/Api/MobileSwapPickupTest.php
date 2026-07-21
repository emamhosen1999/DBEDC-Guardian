<?php

namespace Tests\Feature\Api;

use App\Models\HRM\Department;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mobile shift-swap "pickup" surface: the requester TAKES a counterparty's shift
 * (mirror of cover). Covers the enriched eligibility block, the pickup discovery
 * endpoint, pickup storage, and pickup-on-approval roster mutation.
 */
class MobileSwapPickupTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);            // web guard
        Role::firstOrCreate(['name' => 'Super Administrator']); // web guard
        $this->dept = Department::factory()->create();
        $this->shift = Shift::factory()->create(['code' => 'DAY']);
    }

    private function employee(?int $departmentId = null): User
    {
        $u = User::factory()->create(['department_id' => $departmentId ?? $this->dept->id]);
        $u->assignRole('Employee');

        return $u;
    }

    private function manager(): User
    {
        $m = User::factory()->create();
        $m->assignRole('Super Administrator');

        return $m;
    }

    private function works(User $u, string $date, ?Shift $shift = null): void
    {
        RosterDay::create([
            'user_id' => $u->id,
            'date' => $date,
            'shift_id' => ($shift ?? $this->shift)->id,
            'source' => 'pattern',
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. swapEligible eligibility block
    // -------------------------------------------------------------------------

    public function test_swap_eligible_returns_eligibility_counts(): void
    {
        $me = $this->employee();
        $free1 = $this->employee();
        $free2 = $this->employee();
        $busy = $this->employee();

        $this->works($busy, '2026-07-01'); // busy that date → excluded from employees, counted busy

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/attendance/swaps/eligible?date=2026-07-01');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.eligibility.department_eligible_total', 3)
            ->assertJsonPath('data.eligibility.available_count', 2)
            ->assertJsonPath('data.eligibility.busy_count', 1)
            ->assertJsonPath('data.eligibility.date', '2026-07-01');

        $ids = collect($response->json('data.employees'))->pluck('id')->all();
        $this->assertContains($free1->id, $ids);
        $this->assertContains($free2->id, $ids);
        $this->assertNotContains($busy->id, $ids);
        $this->assertCount(2, $ids);
    }

    // -------------------------------------------------------------------------
    // 2. pickup discovery
    // -------------------------------------------------------------------------

    public function test_pickup_returns_only_shifts_on_requester_free_dates(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $otherDept = $this->employee(Department::factory()->create()->id);

        // Requester is busy on 07-03 → any shift that date is NOT pickable.
        $this->works($me, '2026-07-03');

        // Teammate works both dates; only 07-02 (requester free) should surface.
        $this->works($mate, '2026-07-02');
        $this->works($mate, '2026-07-03');

        // Different-department teammate must never surface.
        $this->works($otherDept, '2026-07-02');

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/attendance/swaps/pickup?from=2026-07-01&to=2026-07-10');

        $response->assertOk()->assertJsonPath('success', true);

        $shifts = collect($response->json('data.shifts'));
        $this->assertCount(1, $shifts);
        $this->assertSame('2026-07-02', $shifts[0]['date']);
        $this->assertSame($mate->id, $shifts[0]['counterparty_id']);
        $this->assertSame('DAY', $shifts[0]['shift_code']);
    }

    public function test_pickup_rejects_range_over_31_days(): void
    {
        Sanctum::actingAs($this->employee());

        $this->getJson('/api/v1/attendance/swaps/pickup?from=2026-07-01&to=2026-08-15')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    // -------------------------------------------------------------------------
    // 3. storeSwap accepts / rejects pickup
    // -------------------------------------------------------------------------

    public function test_store_accepts_valid_pickup(): void
    {
        $me = $this->employee();
        $mate = $this->employee();

        // Requester free on 07-05; counterparty works it → pickable.
        $this->works($mate, '2026-07-05');

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/attendance/swaps', [
            'type' => 'pickup',
            'requester_date' => null,
            'counterparty_id' => $mate->id,
            'counterparty_date' => '2026-07-05',
        ])->assertCreated()->assertJsonPath('success', true);

        $swap = ShiftSwapRequest::firstOrFail();
        $this->assertSame('pickup', $swap->type);
        $this->assertSame('2026-07-05', $swap->counterparty_date->toDateString());
        // requester_date normalized to the picked-up date (NOT NULL column).
        $this->assertSame('2026-07-05', $swap->requester_date->toDateString());
        $this->assertSame('pending', $swap->counterparty_status);
    }

    public function test_store_rejects_pickup_when_requester_already_rostered_on_that_date(): void
    {
        $me = $this->employee();
        $mate = $this->employee();

        $this->works($mate, '2026-07-05'); // counterparty works it
        $this->works($me, '2026-07-05');   // BUT requester already works it → double-booking

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/attendance/swaps', [
            'type' => 'pickup',
            'requester_date' => null,
            'counterparty_id' => $mate->id,
            'counterparty_date' => '2026-07-05',
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_date');
    }

    public function test_store_rejects_pickup_when_counterparty_not_scheduled(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        // Neither works 07-05 → nothing to pick up.

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/attendance/swaps', [
            'type' => 'pickup',
            'requester_date' => null,
            'counterparty_id' => $mate->id,
            'counterparty_date' => '2026-07-05',
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_date');
    }

    public function test_store_requires_counterparty_date_for_pickup(): void
    {
        $me = $this->employee();
        $mate = $this->employee();

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/attendance/swaps', [
            'type' => 'pickup',
            'counterparty_id' => $mate->id,
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_date');
    }

    // -------------------------------------------------------------------------
    // 4. pickup applies on approval (end-to-end via the mobile approval path)
    // -------------------------------------------------------------------------

    public function test_pickup_applies_on_approval_end_to_end(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $manager = $this->manager();

        $this->works($mate, '2026-07-05'); // requester free that date

        // 1. Requester stores the pickup.
        Sanctum::actingAs($me);
        $this->postJson('/api/v1/attendance/swaps', [
            'type' => 'pickup',
            'requester_date' => null,
            'counterparty_id' => $mate->id,
            'counterparty_date' => '2026-07-05',
        ])->assertCreated();

        $swap = ShiftSwapRequest::firstOrFail();

        // 2. Counterparty consents.
        Sanctum::actingAs($mate);
        $this->postJson("/api/v1/attendance/swaps/{$swap->id}/respond", ['decision' => 'accept'])->assertOk();

        // 3. Manager approves → roster mutates.
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/attendance/swaps/{$swap->id}/approve")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Counterparty relinquished the shift; requester gained it.
        $mateDay = RosterDay::where('user_id', $mate->id)->whereDate('date', '2026-07-05')->first();
        $meDay = RosterDay::where('user_id', $me->id)->whereDate('date', '2026-07-05')->first();

        $this->assertNull($mateDay->shift_id);
        $this->assertSame('swap', $mateDay->source);
        $this->assertSame($this->shift->id, $meDay->shift_id);
        $this->assertSame('swap', $meDay->source);
        $this->assertTrue((bool) $meDay->locked);
    }
}
