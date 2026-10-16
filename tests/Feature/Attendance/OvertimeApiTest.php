<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attachments\MakesAttachments;
use Tests\TestCase;

/** The web overtime claim: a worked rest day, a clear document, approval and the replacement credit (days). */
class OvertimeApiTest extends TestCase
{
    use MakesAttachments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Manager']);
        foreach (['attendance.own.view', 'attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_an_approved_rest_day_claim_with_replacement_credits_a_day(): void
    {
        $this->fakeAttachments();
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $manager->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');

        $shift = Shift::factory()->create();
        ShiftAssignment::factory()->create(['scope_type' => 'user', 'scope_id' => $emp->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-01']);
        RosterDay::create(['user_id' => $emp->id, 'date' => '2026-06-17', 'shift_id' => null, 'source' => 'manual', 'locked' => true]);
        Attendance::create(['user_id' => $emp->id, 'date' => '2026-06-17', 'punchin' => '2026-06-17 09:00:00', 'punchout' => '2026-06-17 17:00:00']);

        $this->actingAs($emp)->postJson(route('attendance.overtime.store'), [
            'date' => '2026-06-17', 'compensation' => 'replacement', 'reason' => 'release', 'attachment_tokens' => [$this->clearToken($emp)],
        ])->assertCreated();

        $ot = OvertimeRequest::first();
        $this->actingAs($manager)->postJson(route('attendance.overtime.approve', $ot->id), ['comments' => 'ok'])->assertOk();

        $this->assertSame('approved', $ot->fresh()->status);
        $this->assertTrue($ot->fresh()->comp_off_granted);
        $this->assertSame(1.0, app(CompOffService::class)->balance($emp->id));
        $this->assertSame('2026-08-16', \App\Models\HRM\CompOffLedger::first()->expires_at->toDateString());
    }
}
