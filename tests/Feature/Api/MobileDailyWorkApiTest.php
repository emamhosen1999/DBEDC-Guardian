<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileDailyWorkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mobile_daily_work_endpoints(): void
    {
        $this->getJson('/api/v1/daily-works')->assertUnauthorized();
        $this->getJson('/api/v1/daily-works/selectable-dates')->assertUnauthorized();
        $this->getJson('/api/v1/daily-works/1')->assertUnauthorized();
        $this->patchJson('/api/v1/daily-works/1/status', [])->assertUnauthorized();
        $this->patchJson('/api/v1/daily-works/1/incharge', [])->assertUnauthorized();
        $this->patchJson('/api/v1/daily-works/1/assigned', [])->assertUnauthorized();
        $this->getJson('/api/v1/daily-works/1/objections')->assertUnauthorized();
        $this->postJson('/api/v1/daily-works/1/objections', [])->assertUnauthorized();
        $this->postJson('/api/v1/daily-works/1/objections/1/submit', [])->assertUnauthorized();
        $this->postJson('/api/v1/daily-works/1/objections/1/review', [])->assertUnauthorized();
        $this->postJson('/api/v1/daily-works/1/objections/1/resolve', [])->assertUnauthorized();
        $this->postJson('/api/v1/daily-works/1/objections/1/reject', [])->assertUnauthorized();
        $this->getJson('/api/v1/daily-works/1/objections/1/files')->assertUnauthorized();
        $this->post('/api/v1/daily-works/1/objections/1/files', [], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->deleteJson('/api/v1/daily-works/1/objections/1/files/1')->assertUnauthorized();
        $this->get('/api/v1/daily-works/1/objections/1/files/1/download', ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_user_can_list_only_owned_or_assigned_daily_works(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $ownedWork = DailyWork::factory()->forUsers($user, $user)->create();
        $assignedWork = DailyWork::factory()->forUsers($otherUser, $user)->create();
        $unrelatedWork = DailyWork::factory()->forUsers($otherUser, $otherUser)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/daily-works');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 2);

        $dailyWorkIds = collect($response->json('data.daily_works'))->pluck('id');

        $this->assertTrue($dailyWorkIds->contains($ownedWork->id));
        $this->assertTrue($dailyWorkIds->contains($assignedWork->id));
        $this->assertFalse($dailyWorkIds->contains($unrelatedWork->id));
    }

    public function test_user_can_get_selectable_dates_for_owned_or_assigned_daily_works(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        DailyWork::factory()->forUsers($user, $user)->create([
            'date' => '2025-12-18',
            'status' => DailyWork::STATUS_NEW,
        ]);
        DailyWork::factory()->forUsers($otherUser, $user)->create([
            'date' => '2025-12-19',
            'status' => DailyWork::STATUS_COMPLETED,
        ]);
        DailyWork::factory()->forUsers($otherUser, $otherUser)->create([
            'date' => '2025-12-20',
            'status' => DailyWork::STATUS_COMPLETED,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/daily-works/selectable-dates');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_dates', 2)
            ->assertJsonPath('data.latest_date', '2025-12-19')
            ->assertJsonPath('data.dates.0', '2025-12-18')
            ->assertJsonPath('data.dates.1', '2025-12-19');
    }

    public function test_supervision_engineer_only_gets_incharge_daily_works_in_mobile_api(): void
    {
        if (! Schema::hasColumn('users', 'designation_id') || ! Schema::hasTable('designations')) {
            $this->markTestSkipped('Designation-based scoping requires users.designation_id and designations table.');
        }

        $supervisionEngineerDesignationId = $this->createDesignation('Supervision Engineer');

        $supervisionEngineer = User::factory()->create([
            'active' => true,
            'designation_id' => $supervisionEngineerDesignationId,
        ]);
        $otherUser = User::factory()->create(['active' => true]);

        $inchargeWork = DailyWork::factory()->forUsers($supervisionEngineer, $otherUser)->create();
        $assignedOnlyWork = DailyWork::factory()->forUsers($otherUser, $supervisionEngineer)->create();

        Sanctum::actingAs($supervisionEngineer);

        $response = $this->getJson('/api/v1/daily-works');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1);

        $dailyWorkIds = collect($response->json('data.daily_works'))->pluck('id');

        $this->assertTrue($dailyWorkIds->contains($inchargeWork->id));
        $this->assertFalse($dailyWorkIds->contains($assignedOnlyWork->id));
    }

    public function test_quality_control_inspector_only_gets_assigned_daily_works_in_mobile_api(): void
    {
        if (! Schema::hasColumn('users', 'designation_id') || ! Schema::hasTable('designations')) {
            $this->markTestSkipped('Designation-based scoping requires users.designation_id and designations table.');
        }

        $qualityControlInspectorDesignationId = $this->createDesignation('Quality Control Inspector');

        $qualityControlInspector = User::factory()->create([
            'active' => true,
            'designation_id' => $qualityControlInspectorDesignationId,
        ]);
        $otherUser = User::factory()->create(['active' => true]);

        $inchargeOnlyWork = DailyWork::factory()->forUsers($qualityControlInspector, $otherUser)->create();
        $assignedWork = DailyWork::factory()->forUsers($otherUser, $qualityControlInspector)->create();

        Sanctum::actingAs($qualityControlInspector);

        $response = $this->getJson('/api/v1/daily-works');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1);

        $dailyWorkIds = collect($response->json('data.daily_works'))->pluck('id');

        $this->assertTrue($dailyWorkIds->contains($assignedWork->id));
        $this->assertFalse($dailyWorkIds->contains($inchargeOnlyWork->id));
    }

    public function test_manager_can_update_incharge_and_assigned_on_mobile_api(): void
    {
        $manager = User::factory()->create(['active' => true]);
        $this->assignManagerRole($manager);

        $currentIncharge = User::factory()->create(['active' => true]);
        $nextIncharge = User::factory()->create(['active' => true]);
        $currentAssignee = User::factory()->create([
            'active' => true,
            'report_to' => $currentIncharge->id,
        ]);
        $nextAssignee = User::factory()->create([
            'active' => true,
            'report_to' => $nextIncharge->id,
        ]);

        $dailyWork = DailyWork::factory()->forUsers($currentIncharge, $currentAssignee)->create();

        Sanctum::actingAs($manager);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/incharge', [
            'incharge' => $nextIncharge->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.incharge_user.id', $nextIncharge->id)
            ->assertJsonPath('data.permissions.can_update_incharge', true);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/assigned', [
            'assigned' => $nextAssignee->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_user.id', $nextAssignee->id)
            ->assertJsonPath('data.permissions.can_update_assigned', true);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'incharge' => $nextIncharge->id,
            'assigned' => $nextAssignee->id,
        ]);
    }

    public function test_incharge_can_update_assigned_but_cannot_update_incharge_on_mobile_api(): void
    {
        $incharge = User::factory()->create(['active' => true]);
        $otherIncharge = User::factory()->create(['active' => true]);
        $currentAssignee = User::factory()->create([
            'active' => true,
            'report_to' => $incharge->id,
        ]);
        $nextAssignee = User::factory()->create([
            'active' => true,
            'report_to' => $incharge->id,
        ]);

        $dailyWork = DailyWork::factory()->forUsers($incharge, $currentAssignee)->create();

        Sanctum::actingAs($incharge);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/assigned', [
            'assigned' => $nextAssignee->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_user.id', $nextAssignee->id)
            ->assertJsonPath('data.permissions.can_update_assigned', true);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/incharge', [
            'incharge' => $otherIncharge->id,
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to update incharge for this daily work.');
    }

    public function test_assignee_cannot_view_incharge_or_assigned_columns_in_mobile_payload(): void
    {
        $incharge = User::factory()->create(['active' => true]);
        $assignee = User::factory()->create([
            'active' => true,
            'report_to' => $incharge->id,
        ]);

        DailyWork::factory()->forUsers($incharge, $assignee)->create();

        Sanctum::actingAs($assignee);

        $response = $this->getJson('/api/v1/daily-works');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.daily_works.0.permissions.can_view_incharge', false)
            ->assertJsonPath('data.daily_works.0.permissions.can_view_assigned', false)
            ->assertJsonPath('data.daily_works.0.permissions.can_update_assigned', false)
            ->assertJsonPath('data.daily_works.0.incharge_user', null)
            ->assertJsonPath('data.daily_works.0.assigned_user', null);
    }

    public function test_selectable_dates_endpoint_applies_search_and_status_filters(): void
    {
        $user = User::factory()->create(['active' => true]);

        DailyWork::factory()->forUsers($user, $user)->create([
            'date' => '2025-11-01',
            'status' => DailyWork::STATUS_NEW,
            'description' => 'Bridge deck alignment issue',
        ]);
        DailyWork::factory()->forUsers($user, $user)->create([
            'date' => '2025-11-03',
            'status' => DailyWork::STATUS_COMPLETED,
            'description' => 'Bridge deck alignment update',
        ]);
        DailyWork::factory()->forUsers($user, $user)->create([
            'date' => '2025-11-05',
            'status' => DailyWork::STATUS_COMPLETED,
            'description' => 'Slope protection review',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/daily-works/selectable-dates?status=completed&search=Bridge');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_dates', 1)
            ->assertJsonPath('data.latest_date', '2025-11-03')
            ->assertJsonPath('data.dates.0', '2025-11-03');
    }

    public function test_user_can_view_owned_daily_work_but_not_unrelated_work(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $ownedWork = DailyWork::factory()->forUsers($user, $user)->create();
        $unrelatedWork = DailyWork::factory()->forUsers($otherUser, $otherUser)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/daily-works/'.$ownedWork->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $ownedWork->id);

        $this->getJson('/api/v1/daily-works/'.$unrelatedWork->id)
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access this daily work.');
    }

    public function test_user_can_update_status_for_owned_daily_work(): void
    {
        $user = User::factory()->create(['active' => true]);
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->newStatus()->create();

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/status', [
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', DailyWork::STATUS_COMPLETED)
            ->assertJsonPath('data.inspection_result', DailyWork::INSPECTION_PASS);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ]);

        $completionTime = DB::table('daily_works')
            ->where('id', $dailyWork->id)
            ->value('completion_time');

        $this->assertNotNull($completionTime);
    }

    public function test_user_can_list_objections_for_owned_daily_work(): void
    {
        $user = User::factory()->create(['active' => true]);
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create();

        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $user->id);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/daily-works/'.$dailyWork->id.'/objections');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.objections.0.id', $objectionId);
    }

    public function test_user_can_create_objection_for_owned_daily_work(): void
    {
        $user = User::factory()->create(['active' => true]);
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections', [
            'title' => 'Unexpected utility line',
            'category' => RfiObjection::CATEGORY_SITE_MISMATCH,
            'type' => DailyWork::TYPE_STRUCTURE,
            'description' => 'Underground utility line differs from issued drawings.',
            'reason' => 'Site condition is incompatible with approved structure alignment.',
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Unexpected utility line')
            ->assertJsonPath('data.status', RfiObjection::STATUS_DRAFT);

        $objectionId = $response->json('data.id');
        $this->assertNotNull($objectionId);

        $this->assertDatabaseHas('rfi_objections', [
            'id' => $objectionId,
            'title' => 'Unexpected utility line',
            'created_by' => $user->id,
        ]);

        if (Schema::hasTable('daily_work_objection')) {
            $this->assertDatabaseHas('daily_work_objection', [
                'daily_work_id' => $dailyWork->id,
                'rfi_objection_id' => $objectionId,
            ]);
        }

        if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            $this->assertDatabaseHas('rfi_objections', [
                'id' => $objectionId,
                'daily_work_id' => $dailyWork->id,
            ]);
        }
    }

    public function test_user_cannot_manage_objections_for_unrelated_daily_work(): void
    {
        $owner = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $dailyWork = DailyWork::factory()->forUsers($owner, $owner)->create();

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/daily-works/'.$dailyWork->id.'/objections')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access objections for this daily work.');

        $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections', [
            'title' => 'Unauthorized objection',
            'description' => 'Should fail access control.',
            'reason' => 'Not assigned to this work.',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to create objections for this daily work.');
    }

    public function test_mobile_objection_metadata_endpoint_returns_reference_data(): void
    {
        $user = User::factory()->create(['active' => true]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/daily-works/objections/metadata');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'categories',
                    'statuses',
                    'types',
                ],
            ])
            ->assertJsonFragment(['value' => RfiObjection::CATEGORY_OTHER])
            ->assertJsonFragment(['value' => RfiObjection::STATUS_DRAFT]);
    }

    public function test_creator_can_submit_objection_and_manager_can_review_and_resolve(): void
    {
        $creator = User::factory()->create(['active' => true]);
        $manager = User::factory()->create(['active' => true]);
        $this->assignManagerRole($manager);

        $dailyWork = DailyWork::factory()->forUsers($creator, $creator)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $creator->id, [
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($creator);

        $submitResponse = $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/submit', []);

        $submitResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Objection submitted for review.')
            ->assertJsonPath('data.status', RfiObjection::STATUS_SUBMITTED);

        Sanctum::actingAs($manager);

        $reviewResponse = $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/review', []);

        $reviewResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Objection is now under review.')
            ->assertJsonPath('data.status', RfiObjection::STATUS_UNDER_REVIEW);

        $resolveResponse = $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/resolve', [
            'resolution_notes' => 'Issue reviewed and resolved by manager.',
        ]);

        $resolveResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Objection resolved successfully.')
            ->assertJsonPath('data.status', RfiObjection::STATUS_RESOLVED);

        $this->assertDatabaseHas('rfi_objections', [
            'id' => $objectionId,
            'status' => RfiObjection::STATUS_RESOLVED,
            'resolution_notes' => 'Issue reviewed and resolved by manager.',
        ]);
    }

    public function test_manager_can_reject_submitted_objection(): void
    {
        $creator = User::factory()->create(['active' => true]);
        $manager = User::factory()->create(['active' => true]);
        $this->assignManagerRole($manager);

        $dailyWork = DailyWork::factory()->forUsers($creator, $creator)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $creator->id, [
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($creator);
        $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/submit', [])
            ->assertOk();

        Sanctum::actingAs($manager);

        $rejectResponse = $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/reject', [
            'rejection_reason' => 'Technical scope is not acceptable at this stage.',
        ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Objection rejected.')
            ->assertJsonPath('data.status', RfiObjection::STATUS_REJECTED);

        $this->assertDatabaseHas('rfi_objections', [
            'id' => $objectionId,
            'status' => RfiObjection::STATUS_REJECTED,
            'resolution_notes' => 'Technical scope is not acceptable at this stage.',
        ]);
    }

    public function test_non_manager_cannot_review_resolve_or_reject_objection(): void
    {
        $creator = User::factory()->create(['active' => true]);
        $nonManager = User::factory()->create(['active' => true]);

        $dailyWork = DailyWork::factory()->forUsers($creator, $nonManager)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $creator->id, [
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($creator);
        $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/submit', [])
            ->assertOk();

        Sanctum::actingAs($nonManager);

        $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/review', [])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to review this objection.');

        $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/resolve', [
            'resolution_notes' => 'Attempted by non-manager.',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to review this objection.');

        $this->postJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/reject', [
            'rejection_reason' => 'Attempted by non-manager.',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to review this objection.');
    }

    public function test_user_can_upload_list_download_and_delete_objection_files(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['active' => true]);
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $user->id, [
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($user);

        $uploadResponse = $this->post('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files', [
            'files' => [
                UploadedFile::fake()->image('site-photo.png'),
                UploadedFile::fake()->image('issue.jpg'),
            ],
        ], ['Accept' => 'application/json']);

        $uploadResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_files', 2);

        $firstMediaId = (int) $uploadResponse->json('data.files.0.id');
        $this->assertGreaterThan(0, $firstMediaId);

        $listResponse = $this->getJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files');

        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2);

        $downloadResponse = $this->get('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files/'.$firstMediaId.'/download', [
            'Accept' => 'application/json',
        ]);
        $downloadResponse->assertOk();

        $deleteResponse = $this->deleteJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files/'.$firstMediaId);

        $deleteResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_files', 1);
    }

    public function test_unrelated_user_cannot_manage_or_view_objection_files(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);
        $dailyWork = DailyWork::factory()->forUsers($owner, $owner)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $owner->id, [
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($owner);
        $uploadAsOwner = $this->post('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files', [
            'files' => [UploadedFile::fake()->image('owner-file.png')],
        ], ['Accept' => 'application/json']);
        $uploadAsOwner->assertStatus(201);

        $mediaId = (int) $uploadAsOwner->json('data.files.0.id');

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to view objection files for this daily work.');

        $this->post('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files', [
            'files' => [UploadedFile::fake()->image('intruder.png')],
        ], ['Accept' => 'application/json'])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to upload objection files for this daily work.');

        $this->deleteJson('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files/'.$mediaId)
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to delete objection files for this daily work.');

        $this->get('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files/'.$mediaId.'/download', ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to download objection files for this daily work.');
    }

    public function test_objection_file_upload_validation_is_enforced(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['active' => true]);
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $user->id, [
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($user);

        $missingFilesResponse = $this->post('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files', [], ['Accept' => 'application/json']);
        $missingFilesResponse->assertStatus(422)
            ->assertJsonValidationErrors(['files']);

        $invalidMimeResponse = $this->post('/api/v1/daily-works/'.$dailyWork->id.'/objections/'.$objectionId.'/files', [
            'files' => [UploadedFile::fake()->create('unsupported.txt', 10, 'text/plain')],
        ], ['Accept' => 'application/json']);

        $invalidMimeResponse->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);
    }

    private function insertObjectionForDailyWork(DailyWork $dailyWork, int $creatorId, array $overrides = []): int
    {
        $payload = array_merge([
            'title' => 'Chainage mismatch',
            'category' => RfiObjection::CATEGORY_OTHER,
            'description' => 'Field condition differs from approved plan.',
            'reason' => 'Requires technical clarification before execution.',
            'status' => RfiObjection::STATUS_DRAFT,
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        if (Schema::hasColumn('rfi_objections', 'type') && ! array_key_exists('type', $payload)) {
            $payload['type'] = DailyWork::TYPE_STRUCTURE;
        }

        if (Schema::hasColumn('rfi_objections', 'chainage_from') && ! array_key_exists('chainage_from', $payload)) {
            $payload['chainage_from'] = null;
        }

        if (Schema::hasColumn('rfi_objections', 'chainage_to') && ! array_key_exists('chainage_to', $payload)) {
            $payload['chainage_to'] = null;
        }

        if (Schema::hasColumn('rfi_objections', 'daily_work_id') && ! array_key_exists('daily_work_id', $payload)) {
            $payload['daily_work_id'] = $dailyWork->id;
        }

        $objectionId = (int) DB::table('rfi_objections')->insertGetId($payload);

        if (Schema::hasTable('daily_work_objection')) {
            DB::table('daily_work_objection')->insert([
                'daily_work_id' => $dailyWork->id,
                'rfi_objection_id' => $objectionId,
                'attached_by' => $creatorId,
                'attached_at' => now(),
                'attachment_notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $objectionId;
    }

    private function assignManagerRole(User $user): void
    {
        Role::findOrCreate('Project Manager');
        $user->assignRole('Project Manager');
    }

    private function createDesignation(string $title): int
    {
        $department = Department::factory()->create();

        return (int) Designation::query()->create([
            'title' => $title,
            'department_id' => $department->id,
            'is_active' => true,
        ])->id;
    }
}
