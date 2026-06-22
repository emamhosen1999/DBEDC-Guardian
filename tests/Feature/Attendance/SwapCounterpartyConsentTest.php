<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Two-stage swap: the counterparty (affected coworker) consents BEFORE a manager/admin authorizes.
 */
class SwapCounterpartyConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Super Administrator']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
    }

    private Department $dept;
    private Shift $shift;

    private function dept(): Department
    {
        return $this->dept ??= Department::factory()->create();
    }

    private function shift(): Shift
    {
        return $this->shift ??= Shift::factory()->create(['code' => 'DAY']);
    }

    private function employee(): User
    {
        $u = User::factory()->create(['department_id' => $this->dept()->id]);
        $u->givePermissionTo('attendance.own.view'); // self-service swap routes are gated on this

        return $u;
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->assignRole('Super Administrator'); // Gate::before bypass passes attendance.manage routes

        return $a;
    }

    private function works(User $u, string $date): void
    {
        RosterDay::create(['user_id' => $u->id, 'date' => $date, 'shift_id' => $this->shift()->id, 'source' => 'pattern']);
    }

    public function test_named_swap_needs_counterparty_consent_then_admin(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $admin = $this->admin();
        $this->works($requester, '2026-06-22');     // requester works their date
        $this->works($counterparty, '2026-06-23');  // counterparty works the return date

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-06-22',
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => '2026-06-23',
            'reason' => 'family',
        ])->assertCreated();

        $swap = ShiftSwapRequest::firstOrFail();
        $this->assertSame('pending', $swap->counterparty_status);
        $this->assertSame('pending', $swap->status);

        // Admin cannot approve while the counterparty hasn't responded.
        $this->actingAs($admin)->postJson(route('attendance.swaps.approve', $swap->id))->assertStatus(409);

        // Only the counterparty may respond.
        $this->actingAs($requester)->postJson(route('attendance.swaps.respond', $swap->id), ['decision' => 'accept'])->assertStatus(403);

        // Counterparty sees it in their awaiting-me inbox, then accepts.
        $this->actingAs($counterparty)->getJson(route('attendance.swaps.awaitingMe'))->assertOk()->assertJsonCount(1, 'swaps');
        $this->actingAs($counterparty)->postJson(route('attendance.swaps.respond', $swap->id), ['decision' => 'accept'])->assertOk();
        $this->assertSame('accepted', $swap->fresh()->counterparty_status);

        // No longer awaiting the counterparty; now admin-actionable.
        $this->actingAs($counterparty)->getJson(route('attendance.swaps.awaitingMe'))->assertOk()->assertJsonCount(0, 'swaps');
        $this->actingAs($admin)->postJson(route('attendance.swaps.approve', $swap->id))->assertOk();
        $this->assertSame('approved', $swap->fresh()->status);
    }

    public function test_counterparty_decline_terminates_the_swap(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-06-22');
        $this->works($counterparty, '2026-06-23');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-06-22', 'counterparty_id' => $counterparty->id, 'counterparty_date' => '2026-06-23',
        ])->assertCreated();
        $swap = ShiftSwapRequest::firstOrFail();

        $this->actingAs($counterparty)->postJson(route('attendance.swaps.respond', $swap->id), ['decision' => 'decline'])->assertOk();
        $swap->refresh();
        $this->assertSame('declined', $swap->counterparty_status);
        $this->assertSame('rejected', $swap->status);
    }

    public function test_counterparty_is_now_required(): void
    {
        $requester = $this->employee();
        $this->works($requester, '2026-06-22');

        // The open/give-away path is removed: a swap must name a counterparty.
        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-06-22',
            'reason' => 'give away',
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }
}
