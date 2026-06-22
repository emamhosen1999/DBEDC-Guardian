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

class SwapStoreValidationTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Super Administrator']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
        $this->dept = Department::factory()->create();
        $this->shift = Shift::factory()->create(['code' => 'DAY']);
    }

    private function employee(?int $departmentId = null): User
    {
        $u = User::factory()->create(['department_id' => $departmentId ?? $this->dept->id]);
        $u->assignRole('Employee');
        $u->givePermissionTo('attendance.own.view');

        return $u;
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->assignRole('Super Administrator');

        return $a;
    }

    private function works(User $u, string $date): void
    {
        RosterDay::create(['user_id' => $u->id, 'date' => $date, 'shift_id' => $this->shift->id, 'source' => 'pattern']);
    }

    public function test_valid_swap_is_created_pending_consent(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');     // requester works their date
        $this->works($counterparty, '2026-07-03');  // counterparty works the return date

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => '2026-07-03',
        ])->assertCreated();

        $swap = ShiftSwapRequest::firstOrFail();
        $this->assertSame('swap', $swap->type);
        $this->assertSame('pending', $swap->counterparty_status);
    }

    public function test_valid_cover_is_created(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');      // counterparty is free that day

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertCreated();

        $this->assertSame('cover', ShiftSwapRequest::firstOrFail()->type);
    }

    public function test_counterparty_is_required(): void
    {
        $requester = $this->employee();
        $this->works($requester, '2026-07-01');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }

    public function test_counterparty_must_be_same_department(): void
    {
        $requester = $this->employee();
        $other = $this->employee(Department::factory()->create()->id);
        $this->works($requester, '2026-07-01');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }

    public function test_requester_must_be_scheduled_on_their_date(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        // requester has no roster on 2026-07-01 -> nothing to give up.

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertStatus(422)->assertJsonValidationErrors('requester_date');
    }

    public function test_counterparty_busy_on_requester_date_is_rejected(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');
        $this->works($counterparty, '2026-07-01'); // already working that day

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }

    public function test_swap_requires_counterparty_date(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_date');
    }

    public function test_swap_rejected_when_requester_already_scheduled_on_return_date(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');     // requester's shift to give up
        $this->works($counterparty, '2026-07-03');  // counterparty's return-shift
        $this->works($requester, '2026-07-03');     // BUT requester already works the return date

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => '2026-07-03',
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_date');
    }

    public function test_approve_rechecks_roster_and_rejects_if_counterparty_became_busy(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $admin = $this->admin();
        $this->works($requester, '2026-07-01');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertCreated();

        $swap = ShiftSwapRequest::firstOrFail();
        $this->actingAs($counterparty)->postJson(route('attendance.swaps.respond', $swap->id), ['decision' => 'accept'])->assertOk();

        // Roster changes between consent and approval: counterparty is now busy on the requester's date.
        $this->works($counterparty, '2026-07-01');

        $this->actingAs($admin)->postJson(route('attendance.swaps.approve', $swap->id))->assertStatus(409);
        $this->assertSame('pending', $swap->fresh()->status);
    }
}
