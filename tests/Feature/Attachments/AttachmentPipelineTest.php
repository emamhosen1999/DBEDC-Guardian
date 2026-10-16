<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachments\StagedAttachment;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attachments\AttachmentAccess;
use App\Services\Attachments\AttachmentService;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attachments\FakePdfRasterizer;
use Tests\Support\Attachments\MakesAttachments;
use Tests\Support\Attachments\SyntheticDocuments as Docs;
use Tests\TestCase;

/** The shared pipeline: accepted types, limits, EXIF, private storage, the rules on submit, and who may open a document. */
class AttachmentPipelineTest extends TestCase
{
    use MakesAttachments, RefreshDatabase;

    private User $user;

    private FakePdfRasterizer $pdfFake;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->pdfFake = $this->fakeAttachments();
        // Every calendar day is a working day: these tests are about attachments, not the roster.
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver
        {
            public function resolve(int|string $userId, CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule($date->copy()->setTime(9, 0), $date->copy()->setTime(17, 0), false, 0, 0, 480, 240, 0, 0, true, true);
            }
        });
        $this->user = User::factory()->create();
        Permission::findOrCreate('leave.own.view');
        $this->user->givePermissionTo('leave.own.view');
    }

    private function leaveType(string $rule, ?float $days = null): LeaveSetting
    {
        return LeaveSetting::create([
            'type' => 'T-'.$rule, 'days' => 30, 'eligibility' => 'All', 'carry_forward' => false, 'earned_leave' => false,
            'allow_negative' => true, 'attachment_rule' => $rule, 'requires_attachment_days' => $days,
        ]);
    }

    private function leavePayload(LeaveSetting $type, int $days, array $extra = [], int $start = 10): array
    {
        return [
            'user_id' => $this->user->id, 'leaveType' => $type->type,
            'fromDate' => now()->addDays($start)->format('Y-m-d'), 'toDate' => now()->addDays($start - 1 + $days)->format('Y-m-d'),
            'daysCount' => $days, 'leaveReason' => 'Medical certificate attached.',
        ] + $extra;
    }

    /** A JPEG that carries GPS coordinates in an Exif APP1 segment (GD cannot write one, so it is built by hand). */
    private function jpegWithGps(string $jpeg): string
    {
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1).pack('vvVV', 0x8825, 4, 1, 26).pack('V', 0)       // IFD0: GPSInfo -> offset 26
            .pack('v', 2).pack('vvV', 1, 2, 2).'N'."\0\0\0"                  // GPS IFD: LatitudeRef 'N'
            .pack('vvVV', 2, 5, 3, 56).pack('V', 0)                           // Latitude -> 3 rationals at 56
            .pack('VVVVVV', 23, 1, 48, 1, 0, 1);                              // 23 deg 48 min 0 sec
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    public function test_a_clear_image_is_staged_on_the_private_disk_re_encoded_without_exif_gps(): void
    {
        $withGps = $this->jpegWithGps($this->sharpJpegBytes());
        $tmp = tempnam(sys_get_temp_dir(), 'gps');
        file_put_contents($tmp, $withGps);
        $before = @exif_read_data($tmp);
        $this->assertArrayHasKey('GPSLatitudeRef', $before ?: [], 'the fixture really carries GPS');

        $publicBefore = Storage::disk('public')->allFiles();
        $this->actingAs($this->user);
        $res = $this->postJson(route('attachments.stage'), ['file' => $this->upload($withGps, 'Medical Report (ahmed).jpg')])->assertCreated();
        $res->assertJsonPath('data.passed', true)->assertJsonPath('data.mime_type', 'image/jpeg');

        $row = StagedAttachment::first();
        Storage::disk('attachments')->assertExists($row->path);
        $this->assertSame($publicBefore, Storage::disk('public')->allFiles(), 'nothing is ever written to the public disk');
        $out = tempnam(sys_get_temp_dir(), 'out');
        file_put_contents($out, Storage::disk('attachments')->get($row->path));
        $after = @exif_read_data($out);
        $this->assertArrayNotHasKey('GPSLatitudeRef', $after ?: []);
        $this->assertStringNotContainsString('Exif', substr(Storage::disk('attachments')->get($row->path), 0, 64));
        $this->assertSame('Medical Report (ahmed).jpg', $row->original_name, 'the original name is metadata only');
        $this->assertStringNotContainsString('ahmed', $row->path, 'and never part of the stored path');
    }

    public function test_the_type_is_detected_from_the_content_and_limits_are_enforced(): void
    {
        $this->actingAs($this->user);
        // A script renamed to .jpg
        $this->postJson(route('attachments.stage'), ['file' => $this->upload('<?php echo 1;', 'x.jpg')])->assertStatus(422)->assertJsonPath('code', 'unsupported_type');
        // Over 10 MB
        $this->postJson(route('attachments.stage'), ['file' => $this->upload($this->sharpJpegBytes().str_repeat('0', 10 * 1024 * 1024), 'big.jpg')])->assertStatus(422)->assertJsonPath('code', 'too_large');
        // A PDF with more than 10 pages, and one with active content
        $pdf = $this->pdfFake;
        $pdf->pageCount = 11;
        $pdf->text = true;
        $this->postJson(route('attachments.stage'), ['file' => $this->upload("%PDF-1.4\n1 0 obj", 'a.pdf')])->assertStatus(422)->assertJsonPath('code', 'too_many_pages');
        $pdf->pageCount = 1;
        $this->postJson(route('attachments.stage'), ['file' => $this->upload("%PDF-1.4\n/JavaScript (app.alert(1))", 'a.pdf')])->assertStatus(422)->assertJsonPath('code', 'active_content');
        // A text PDF is accepted
        $this->postJson(route('attachments.stage'), ['file' => $this->upload("%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj", 'a.pdf')])->assertCreated()->assertJsonPath('data.mime_type', 'application/pdf');
    }

    public function test_an_unclear_upload_is_rejected_with_the_specific_message(): void
    {
        $this->actingAs($this->user);
        $blurry = Docs::jpeg(Docs::blur(Docs::page(), 40), 88);
        $res = $this->postJson(route('attachments.stage'), ['file' => $this->upload($blurry)])->assertStatus(422);
        $res->assertJsonPath('code', 'blurry');
        $this->assertStringContainsString('too blurry to read', $res->json('message'));
        $this->assertSame(0, StagedAttachment::count(), 'nothing unclear is kept');
    }

    public function test_a_user_cannot_delete_or_preview_another_users_staged_upload(): void
    {
        $owner = User::factory()->create();
        $token = $this->clearToken($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->deleteJson(route('attachments.staged.discard', ['token' => $token]))->assertOk();
        $this->assertNotNull(StagedAttachment::where('token', $token)->first(), "B's delete never removes A's upload");
        $this->actingAs($stranger)->getJson(route('attachments.staged.image', ['token' => $token, 'which' => 'processed']))->assertStatus(422);

        $this->actingAs($owner)->deleteJson(route('attachments.staged.discard', ['token' => $token]))->assertOk();
        $this->assertNull(StagedAttachment::where('token', $token)->first(), 'the uploader can delete it');
    }

    public function test_at_most_three_files_per_application(): void
    {
        $tokens = [$this->clearToken($this->user), $this->clearToken($this->user), $this->clearToken($this->user), $this->clearToken($this->user)];
        $this->expectExceptionMessage('at most 3 files');
        app(AttachmentService::class)->resolve($tokens, (string) $this->user->employee_id);
    }

    public function test_leave_follows_each_types_rule_always_beyond_days_optional(): void
    {
        $this->actingAs($this->user);
        $always = $this->leaveType('always');
        $beyond = $this->leaveType('beyond_days', 2);
        $optional = $this->leaveType('optional');

        // always: needed even for one day
        $this->postJson(route('leave-add'), $this->leavePayload($always, 1))->assertStatus(422);
        $token = $this->clearToken($this->user);
        $this->postJson(route('leave-add'), $this->leavePayload($always, 1, ['attachment_tokens' => [$token]]))->assertCreated();
        $leave = Leave::latest('id')->first();
        $this->assertCount(1, $leave->currentAttachments());
        $this->assertSame('attachments', $leave->getFirstMedia('attachments')->disk, 'stored on the private disk');

        // beyond 2 days: 2 days free, 3 days needs one
        $this->postJson(route('leave-add'), $this->leavePayload($beyond, 2, [], 20))->assertCreated();
        $this->postJson(route('leave-add'), $this->leavePayload($beyond, 3, [], 30))->assertStatus(422);

        // optional: never needed
        $this->postJson(route('leave-add'), $this->leavePayload($optional, 5, [], 40))->assertCreated();
    }

    public function test_an_unclear_staged_file_cannot_be_attached_to_a_leave(): void
    {
        $this->actingAs($this->user);
        $always = $this->leaveType('always');
        StagedAttachment::create([
            'token' => 'bad-token', 'user_id' => (string) $this->user->employee_id, 'kind' => 'scan_pdf', 'path' => 'staging/x.pdf', 'mime' => 'application/pdf',
            'size' => 1, 'clarity' => ['passed' => false, 'message' => 'blurry'], 'clarity_passed' => false, 'expires_at' => now()->addHour(),
        ]);
        $this->postJson(route('leave-add'), $this->leavePayload($always, 1, ['attachment_tokens' => ['bad-token']]))->assertStatus(422);
        // someone else's token is never usable
        $other = User::factory()->create();
        $this->postJson(route('leave-add'), $this->leavePayload($always, 1, ['attachment_tokens' => [$this->clearToken($other)]]))->assertStatus(422);
    }

    public function test_mobile_api_applies_the_same_rule_with_tokens(): void
    {
        Sanctum::actingAs($this->user);
        $always = $this->leaveType('always');
        $payload = ['leave_type_id' => $always->id, 'from_date' => now()->addDays(10)->format('Y-m-d'), 'to_date' => now()->addDays(10)->format('Y-m-d'), 'reason' => 'Medical leave please'];
        $this->postJson('/api/v1/leaves', $payload)->assertStatus(422)->assertJsonPath('code', 'required');
        $stage = $this->postJson('/api/v1/attachments/stage', ['file' => $this->upload($this->sharpJpegBytes())])->assertCreated();
        $this->postJson('/api/v1/leaves', $payload + ['attachment_tokens' => [$stage->json('data.token')]])->assertCreated();
        $this->assertSame(1, Leave::first()->currentAttachments()->count());
    }

    public function test_only_the_applicant_the_chain_and_permission_holders_in_scope_can_open_a_document(): void
    {
        $leave = Leave::create(['user_id' => $this->user->id, 'leave_type' => $this->leaveType('always')->id, 'from_date' => now()->addDay(), 'to_date' => now()->addDay(), 'no_of_days' => 1, 'reason' => 'x', 'status' => 'pending']);
        $approver = User::factory()->create();
        $leave->update(['approval_chain' => [['level' => 1, 'approver_id' => $approver->employee_id, 'status' => 'pending']], 'current_approval_level' => 1]);
        app(AttachmentService::class)->attach($leave, app(AttachmentService::class)->resolve([$this->clearToken($this->user)], (string) $this->user->employee_id), (string) $this->user->employee_id);
        $media = $leave->getFirstMedia('attachments');
        $access = app(AttachmentAccess::class);

        $stranger = User::factory()->create();
        $holderOutOfScope = User::factory()->create();
        Permission::findOrCreate('leaves.attachments.view');
        $holderOutOfScope->givePermissionTo('leaves.attachments.view');
        Role::firstOrCreate(['name' => 'Super Administrator']);
        $admin = User::factory()->create();
        $admin->assignRole('Super Administrator');
        $admin->givePermissionTo('leaves.attachments.view');

        $this->assertTrue($access->canView($this->user, $leave), 'the applicant');
        $this->assertTrue($access->canView($approver, $leave), 'an approver in the chain');
        $this->assertTrue($access->canView($admin, $leave), 'a permission holder with global scope');
        $this->assertFalse($access->canView($stranger, $leave));
        $this->assertFalse($access->canView($holderOutOfScope, $leave), 'the permission alone is not enough outside the DepartmentScope');

        $url = $access->link($media, false);
        $this->actingAs($this->user)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($stranger)->get($url)->assertForbidden();
        $this->actingAs($this->user)->get(route('attachments.show', ['media' => $media->id]))->assertForbidden(); // unsigned
    }
}
