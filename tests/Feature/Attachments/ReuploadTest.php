<?php

namespace Tests\Feature\Attachments;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Notifications\Attachments\ReturnedForReuploadNotification;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attachments\AttachmentReuploadService;
use App\Services\Attachments\AttachmentService;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Leave\LeaveApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attachments\MakesAttachments;
use Tests\TestCase;

/** "Request re-upload": reason, notification, the SAME approval step, history kept, audit trail. */
class ReuploadTest extends TestCase
{
    use MakesAttachments, RefreshDatabase;

    private User $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->fakeAttachments();
        Notification::fake();
        $this->approver = User::factory()->create();
        $this->employee = User::factory()->create(['report_to' => $this->approver->id]);
        foreach (['leaves.request_reupload', 'overtime.request_reupload'] as $p) {
            Permission::findOrCreate($p);
            $this->approver->givePermissionTo($p);
        }
    }

    private function leave(): Leave
    {
        $type = LeaveSetting::create(['type' => 'Sick', 'days' => 10, 'attachment_rule' => 'always']);
        $leave = Leave::create(['user_id' => $this->employee->id, 'leave_type' => $type->id, 'from_date' => now()->addDays(3), 'to_date' => now()->addDays(3), 'no_of_days' => 1, 'reason' => 'ill', 'status' => 'pending']);
        $leave->update(['approval_chain' => [['level' => 1, 'approver_id' => $this->approver->employee_id, 'approver_name' => 'A', 'status' => 'pending', 'approved_at' => null, 'comments' => null]], 'current_approval_level' => 1]);
        $svc = app(AttachmentService::class);
        $svc->attach($leave, $svc->resolve([$this->clearToken($this->employee)], (string) $this->employee->employee_id), (string) $this->employee->employee_id);

        return $leave->fresh();
    }

    public function test_the_approver_returns_a_leave_with_a_reason_the_employee_is_notified_and_re_uploads_to_the_same_step(): void
    {
        $leave = $this->leave();
        $svc = app(AttachmentReuploadService::class);
        $old = $leave->getFirstMedia('attachments');

        $svc->request($leave, $this->approver, 'The medical certificate is cut off at the bottom.');
        $leave->refresh();
        $this->assertTrue($leave->isReturnedForReupload());
        $this->assertSame('pending', $leave->status, 'still pending');
        $this->assertSame(1, $leave->current_approval_level, 'the same approval step');
        Notification::assertSentTo($this->employee, ReturnedForReuploadNotification::class, fn ($n) => str_contains($n->toArray($this->employee)['body'], 'cut off') && str_contains($n->url(), (string) $leave->id));

        // Not decidable while it waits for the employee.
        $this->assertFalse(app(LeaveApprovalService::class)->approve($leave, $this->approver)['success']);

        $svc->reupload($leave, $this->employee, [$this->clearToken($this->employee)]);
        $leave->refresh();
        $this->assertFalse($leave->isReturnedForReupload());
        $this->assertSame('pending', $leave->status);
        $this->assertSame(1, $leave->current_approval_level);
        $this->assertCount(1, $leave->currentAttachments(), 'one current document');
        $this->assertCount(2, $leave->getMedia('attachments'), 'the old file is kept in history, never deleted');
        $this->assertTrue((bool) $old->fresh()->getCustomProperty('superseded_at'));
        $this->assertTrue(file_exists($old->fresh()->getPath()));

        $events = $leave->attachmentEvents()->orderBy('id')->get();
        $this->assertSame(['reupload_requested', 'reuploaded'], $events->pluck('action')->all());
        $this->assertSame((string) $this->approver->employee_id, $events[0]->actor_id);
        $this->assertSame('The medical certificate is cut off at the bottom.', $events[0]->reason);
    }

    public function test_overtime_can_be_returned_too_and_a_reason_and_the_permission_are_required(): void
    {
        $ot = OvertimeRequest::create([
            'user_id' => $this->employee->id, 'date' => '2026-10-07', 'requested_minutes' => 480, 'reason' => 'x', 'status' => 'pending',
            'claim_type' => 'rest_day', 'compensation' => 'replacement', 'day_credit' => 1.0,
            'approval_chain' => [['level' => 1, 'approver_id' => $this->approver->employee_id, 'status' => 'pending']], 'current_approval_level' => 1,
        ]);
        $svc = app(AttachmentReuploadService::class);

        try {
            $svc->request($ot, $this->approver, '  ');
            $this->fail('A reason is mandatory.');
        } catch (AttachmentRejected $e) {
            $this->assertSame('reason_required', $e->reason);
        }
        $this->expectException(AttachmentRejected::class);
        $svc->request($ot, User::factory()->create(), 'unclear'); // no permission, not an approver
    }

    public function test_the_overtime_approval_is_blocked_until_the_new_document_arrives(): void
    {
        $ot = OvertimeRequest::create([
            'user_id' => $this->employee->id, 'date' => '2026-10-07', 'requested_minutes' => 480, 'reason' => 'x', 'status' => 'pending',
            'claim_type' => 'rest_day', 'compensation' => 'payment', 'day_credit' => 1.0,
            'approval_chain' => [['level' => 1, 'approver_id' => $this->approver->employee_id, 'status' => 'pending']], 'current_approval_level' => 1,
        ]);
        app(AttachmentReuploadService::class)->request($ot, $this->approver, 'Photo is dark');
        $res = app(AttendanceApprovalService::class)->approve($ot->fresh(), $this->approver);
        $this->assertFalse($res['success']);
        $this->assertSame('pending', $ot->fresh()->status);
    }

    public function test_only_the_applicant_can_re_upload_and_only_when_returned(): void
    {
        $leave = $this->leave();
        $svc = app(AttachmentReuploadService::class);
        try {
            $svc->reupload($leave, $this->employee, [$this->clearToken($this->employee)]);
            $this->fail('Not returned yet.');
        } catch (AttachmentRejected $e) {
            $this->assertSame('not_returned', $e->reason);
        }
        $svc->request($leave, $this->approver, 'Blurry');
        $this->expectException(AttachmentRejected::class);
        $svc->reupload($leave->fresh(), $this->approver, [$this->clearToken($this->approver)]);
    }

    public function test_it_works_through_the_mobile_api_endpoints(): void
    {
        $leave = $this->leave();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/attachments/leave/{$leave->id}/request-reupload", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/v1/attachments/leave/{$leave->id}/request-reupload", ['reason' => 'Too dark to read'])
            ->assertOk()->assertJsonPath('data.returned_for_reupload', true)->assertJsonPath('data.reupload.reason', 'Too dark to read');

        Sanctum::actingAs($this->employee);
        $token = $this->postJson('/api/v1/attachments/stage', ['file' => $this->upload($this->sharpJpegBytes())])->assertCreated()->json('data.token');
        $this->postJson("/api/v1/attachments/leave/{$leave->id}/reupload", ['attachment_tokens' => [$token]])
            ->assertOk()->assertJsonPath('data.returned_for_reupload', false);
    }
}
