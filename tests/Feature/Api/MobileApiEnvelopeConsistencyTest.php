<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Locks the {success, data, message} response envelope on the V1 controllers that
 * were normalized onto the shared ApiResponse trait (DailyWork, ManagerDashboard,
 * Profile, Roster).
 *
 * The mobile client contract (src/api/*.js) is:
 *   - success:  reads `response.success` (must be truthy) then `response.data`.
 *   - error:    client.js `hasExplicitFailurePayload` throws on `success === false`;
 *               it reads `message` and (optionally) `error_code`.
 *
 * These assertions guarantee the trait conversion stays byte-compatible with the
 * previously hand-rolled envelopes:
 *   - success bodies expose `success:true` + a `data` key (and `message` only when set);
 *   - error bodies expose `success:false` + `message`, and DO NOT introduce a `data`
 *     key nor an `error_code` key (the hand-rolled errors never emitted either).
 */
class MobileApiEnvelopeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    // ── Profile ─────────────────────────────────────────────────────────────────

    public function test_profile_show_returns_success_envelope_without_message(): void
    {
        $user = User::factory()->create(['name' => 'Envelope User']);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonStructure(['success', 'data'])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('message');
    }

    public function test_profile_update_returns_success_envelope_with_message_and_data(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', ['name' => 'New Envelope Name'])
            ->assertOk()
            ->assertJsonStructure(['success', 'data', 'message'])
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('data.name', 'New Envelope Name');
    }

    // ── Manager dashboard ────────────────────────────────────────────────────────

    public function test_manager_dashboard_success_envelope_for_manager(): void
    {
        $manager = User::factory()->create();
        $this->assignManagerRole($manager);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/manager/dashboard-summary')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['team', 'approvals', 'daily_works', 'objections']])
            ->assertJsonPath('success', true);
    }

    public function test_manager_dashboard_error_envelope_has_no_data_or_error_code_key(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/manager/dashboard-summary')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access manager dashboard summary.')
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('error_code');
    }

    public function test_manager_team_members_returns_success_envelope_with_array_data(): void
    {
        $manager = User::factory()->create();
        $this->assignManagerRole($manager);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/manager/team-members')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertIsArray($response->json('data'));
    }

    // ── Roster ───────────────────────────────────────────────────────────────────

    public function test_roster_shifts_returns_success_envelope(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/shifts')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['shifts']])
            ->assertJsonPath('success', true);
    }

    public function test_roster_index_returns_success_envelope_with_roster_and_holidays(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $today = now()->toDateString();

        $this->getJson('/api/v1/attendance/roster?from='.$today.'&to='.$today)
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['roster', 'holidays']])
            ->assertJsonPath('success', true);
    }

    public function test_roster_index_validates_required_range(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/roster')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);
    }

    // ── Daily work ───────────────────────────────────────────────────────────────

    public function test_daily_work_index_returns_success_envelope_without_message(): void
    {
        $user = User::factory()->create();
        DailyWork::factory()->forUsers($user, $user)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/daily-works')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['daily_works', 'pagination', 'assignment_options']])
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('message');
    }

    public function test_daily_work_update_status_returns_success_envelope_with_message(): void
    {
        $user = User::factory()->create();
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->newStatus()->create();

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/status', [
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ])
            ->assertOk()
            ->assertJsonStructure(['success', 'data', 'message'])
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Daily work status updated successfully.');
    }

    public function test_daily_work_not_found_error_envelope_has_no_data_or_error_code_key(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/daily-works/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Daily work not found.')
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('error_code');
    }

    public function test_daily_work_forbidden_error_envelope_has_no_data_key(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unrelatedWork = DailyWork::factory()->forUsers($otherUser, $otherUser)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/daily-works/'.$unrelatedWork->id)
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access this daily work.')
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('error_code');
    }

    private function assignManagerRole(User $user): void
    {
        Role::findOrCreate('Project Manager');
        $user->assignRole('Project Manager');
    }
}
