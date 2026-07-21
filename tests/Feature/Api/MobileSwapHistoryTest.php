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
 * Mobile swap HISTORY surfaces:
 *  - mySwaps (swaps/mine): the user's FULL involvement — requester OR counterparty.
 *  - teamDecidedSwaps (swaps/team-decided): manager approval history (approved/rejected).
 *  - pendingSwaps (swaps/pending): unchanged manager queue — accepted-pending only.
 */
class MobileSwapHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);            // web guard
        Role::firstOrCreate(['name' => 'Super Administrator']); // web guard — isManagerUser()
        $this->dept = Department::factory()->create();
    }

    private function employee(?int $reportTo = null): User
    {
        $u = User::factory()->create([
            'department_id' => $this->dept->id,
            'report_to' => $reportTo,
        ]);
        $u->assignRole('Employee');

        return $u;
    }

    private function manager(): User
    {
        $m = User::factory()->create(['department_id' => $this->dept->id]);
        $m->assignRole('Super Administrator');

        return $m;
    }

    // -------------------------------------------------------------------------
    // 1. mySwaps — requester OR counterparty (full history), including decided
    // -------------------------------------------------------------------------

    public function test_my_swaps_returns_swaps_where_user_is_requester_or_counterparty(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $stranger = $this->employee();

        // (a) I am the requester.
        $asRequester = ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $me->id, 'requester_date' => '2026-07-01',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'pending', 'status' => 'pending',
        ]);

        // (b) I am the counterparty on a DECIDED swap — must still appear.
        $asCounterpartyDecided = ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $mate->id, 'requester_date' => '2026-07-02',
            'counterparty_id' => $me->id, 'counterparty_status' => 'accepted', 'status' => 'approved',
        ]);

        // (c) A swap I have nothing to do with — must NOT appear.
        ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $mate->id, 'requester_date' => '2026-07-03',
            'counterparty_id' => $stranger->id, 'counterparty_status' => 'pending', 'status' => 'pending',
        ]);

        Sanctum::actingAs($me);

        $res = $this->getJson('/api/v1/attendance/swaps/mine')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($asRequester->id, $ids);
        $this->assertContains($asCounterpartyDecided->id, $ids);
        $this->assertCount(2, $ids);

        // Both parties' names are eager-loaded.
        $first = collect($res->json('data'))->firstWhere('id', $asCounterpartyDecided->id);
        $this->assertSame($mate->name, $first['requester']['name']);
        $this->assertSame($me->name, $first['counterparty']['name']);
    }

    // -------------------------------------------------------------------------
    // 2. teamDecidedSwaps — manager approval history
    // -------------------------------------------------------------------------

    public function test_team_decided_returns_only_decided_team_swaps(): void
    {
        $manager = $this->manager();
        $report = $this->employee($manager->id);   // in manager's team
        $mate = $this->employee();
        $outsider = $this->employee();             // NOT in manager's team

        $approved = ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $report->id, 'requester_date' => '2026-07-01',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'accepted', 'status' => 'approved',
        ]);
        $rejected = ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $report->id, 'requester_date' => '2026-07-02',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'declined', 'status' => 'rejected',
        ]);
        // Still pending → excluded from history.
        ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $report->id, 'requester_date' => '2026-07-03',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'accepted', 'status' => 'pending',
        ]);
        // Decided but outside the manager's team → excluded.
        ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $outsider->id, 'requester_date' => '2026-07-04',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'accepted', 'status' => 'approved',
        ]);

        Sanctum::actingAs($manager);

        $res = $this->getJson('/api/v1/attendance/swaps/team-decided')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($approved->id, $ids);
        $this->assertContains($rejected->id, $ids);
        $this->assertCount(2, $ids);

        // Shift-code decoration is present.
        $this->assertArrayHasKey('requester_shift_code', $res->json('data')[0]);
        $this->assertArrayHasKey('counterparty_shift_code', $res->json('data')[0]);
    }

    // -------------------------------------------------------------------------
    // 2b. Snapshot wins over a rewritten roster — the reported bug.
    // A swap stores the give/take codes at request time; once approved,
    // applySwap rewrites the roster. The history must still show the ORIGINAL
    // codes, not the post-swap roster (which would read OFF).
    // -------------------------------------------------------------------------

    public function test_decided_swap_shows_snapshot_not_rewritten_roster(): void
    {
        $manager = $this->manager();
        $report = $this->employee($manager->id);
        $mate = $this->employee();

        // Snapshot captured at request time: report gave up NGT, took EVN.
        $swap = ShiftSwapRequest::create([
            'type' => 'swap', 'requester_id' => $report->id, 'requester_date' => '2026-07-22',
            'counterparty_id' => $mate->id, 'counterparty_date' => '2026-08-06',
            'counterparty_status' => 'accepted', 'status' => 'approved',
            'requester_shift_code' => 'NGT', 'counterparty_shift_code' => 'EVN',
        ]);

        // Post-swap roster: applySwap moved both shifts away — a live lookup
        // would now read OFF for both (exactly the bug).
        // (no roster_days rows for those cells => effectiveShiftId null => OFF)

        Sanctum::actingAs($manager);

        $res = $this->getJson('/api/v1/attendance/swaps/team-decided')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $swap->id);

        $this->assertSame('NGT', $row['requester_shift_code']);   // not OFF
        $this->assertSame('EVN', $row['counterparty_shift_code']); // not OFF
    }

    public function test_legacy_swap_without_snapshot_falls_back_to_live_roster(): void
    {
        $manager = $this->manager();
        $report = $this->employee($manager->id);
        $mate = $this->employee();
        $ngt = Shift::factory()->create(['code' => 'NGT']);
        $evn = Shift::factory()->create(['code' => 'EVN']);

        // Legacy row: no snapshot codes (sentinel null) → derive from roster.
        $swap = ShiftSwapRequest::create([
            'type' => 'swap', 'requester_id' => $report->id, 'requester_date' => '2026-07-22',
            'counterparty_id' => $mate->id, 'counterparty_date' => '2026-08-06',
            'counterparty_status' => 'accepted', 'status' => 'approved',
        ]);
        RosterDay::create(['user_id' => $report->id, 'date' => '2026-07-22', 'shift_id' => $ngt->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $mate->id, 'date' => '2026-08-06', 'shift_id' => $evn->id, 'source' => 'pattern']);

        Sanctum::actingAs($manager);

        $res = $this->getJson('/api/v1/attendance/swaps/team-decided')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $swap->id);

        $this->assertSame('NGT', $row['requester_shift_code']);
        $this->assertSame('EVN', $row['counterparty_shift_code']);
    }

    public function test_team_decided_is_forbidden_for_non_manager(): void
    {
        Sanctum::actingAs($this->employee());

        $this->getJson('/api/v1/attendance/swaps/team-decided')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    // -------------------------------------------------------------------------
    // 3. pendingSwaps — unchanged manager queue (accepted + pending only)
    // -------------------------------------------------------------------------

    public function test_pending_queue_still_returns_accepted_pending_only(): void
    {
        $manager = $this->manager();
        $report = $this->employee($manager->id);
        $mate = $this->employee();

        $acceptedPending = ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $report->id, 'requester_date' => '2026-07-01',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'accepted', 'status' => 'pending',
        ]);
        // Awaiting counterparty (not yet accepted) → NOT in the manager queue.
        ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $report->id, 'requester_date' => '2026-07-02',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'pending', 'status' => 'pending',
        ]);
        // Already decided → NOT in the pending queue.
        ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $report->id, 'requester_date' => '2026-07-03',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'accepted', 'status' => 'approved',
        ]);

        Sanctum::actingAs($manager);

        $res = $this->getJson('/api/v1/attendance/swaps/pending')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertSame([$acceptedPending->id], $ids);
    }
}
