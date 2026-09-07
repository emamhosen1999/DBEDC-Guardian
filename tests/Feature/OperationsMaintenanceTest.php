<?php

namespace Tests\Feature;

use App\Models\OmDefect;
use App\Models\OmIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OperationsMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        $permissions = [
            'om.dashboard.view',
            'om.defects.view',
            'om.defects.manage',
            'om.incidents.view',
            'om.incidents.manage',
            'om.maintenance.view',
            'om.maintenance.manage',
            'om.toll.view',
            'om.toll.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user->givePermissionTo($permissions);
    }

    public function test_om_dashboard_returns_successful_data(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/om/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'stats' => [
                    'today_toll_revenue',
                    'etc_vehicle_ratio',
                    'active_incidents_count',
                    'open_work_orders_count',
                    'open_defects_count',
                ],
            ]);
    }

    public function test_can_create_defect_with_sla_target(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/om/defects', [
                'title' => 'Test Pothole on Ch 14+250',
                'distress_type' => 'pothole',
                'chainage' => 'Ch 14+250',
                'direction' => 'northbound',
                'severity' => 'critical',
                'description' => 'Test defect description for SLA verification',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('om_defects', [
            'title' => 'Test Pothole on Ch 14+250',
            'distress_type' => 'pothole',
            'sla_hours' => 4,
        ]);
    }

    public function test_can_convert_defect_to_work_order(): void
    {
        $defect = OmDefect::create([
            'defect_number' => 'DEF-TEST-'.rand(1000, 9999),
            'title' => 'Guardrail Collision Test',
            'distress_type' => 'guardrail_crash_damage',
            'chainage' => 'Ch 22+800',
            'direction' => 'southbound',
            'severity' => 'high',
            'sla_hours' => 24,
            'sla_due_at' => now()->addHours(24),
            'status' => 'reported',
            'reported_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/om/defects/{$defect->id}/convert-to-wo", [
                'title' => 'Emergency Repair for Guardrail Test',
                'category' => 'guardrail',
                'assigned_to' => 'Roadside Crew Alpha',
                'estimated_cost' => 35000,
                'lock_version' => $defect->lock_version,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('om_work_orders', [
            'defect_id' => $defect->id,
            'category' => 'guardrail',
            'estimated_cost' => 35000,
        ]);

        $this->assertEquals('work_order_created', $defect->fresh()->status);
    }

    public function test_can_create_incident_and_update_timeline(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/om/incidents', [
                'title' => 'Vehicle Fire on Shoulder Test',
                'incident_type' => 'vehicle_fire',
                'chainage' => 'Ch 31+400',
                'direction' => 'northbound',
                'severity' => 'critical',
                'dispatched_unit' => 'Fire Unit 1',
                'description' => 'Test incident creation',
            ]);

        $response->assertStatus(200);
        $incidentId = $response->json('incident.id');

        $this->actingAs($this->user)
            ->postJson("/om/incidents/{$incidentId}/status", [
                'status' => 'dispatched',
                'lock_version' => $response->json('incident.lock_version'),
            ])
            ->assertStatus(200);

        // Update to on_scene after dispatch.
        $statusRes = $this->actingAs($this->user)
            ->postJson("/om/incidents/{$incidentId}/status", [
                'status' => 'on_scene',
                'lock_version' => 0,
            ]);

        $statusRes->assertStatus(200);
        $this->assertDatabaseHas('om_incidents', [
            'id' => $incidentId,
            'status' => 'on_scene',
        ]);
    }

    public function test_can_submit_toll_shift_reconciliation_audit(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/om/toll-operations/audit', [
                'plaza_name' => 'Main Toll Plaza (Ch 0+000)',
                'shift_date' => now()->toDateString(),
                'shift_type' => 'morning',
                'system_calculated_total' => 100000.00,
                'cash_declared_by_collectors' => 20000.00,
                'etc_automatic_revenue' => 80000.00,
                'bank_deposit_reference' => 'TEST-DEP-001',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('om_toll_shift_audits', [
            'plaza_name' => 'Main Toll Plaza (Ch 0+000)',
            'variance_amount' => 0.00,
            'audit_status' => 'verified_matched',
        ]);
    }

    public function test_mobile_field_endpoints(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/om/field/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'active_incidents',
                    'assigned_work_orders',
                    'open_defects',
                ],
            ]);
    }

    public function test_mobile_rejects_a_stale_incident_mutation_with_current_version(): void
    {
        $incident = OmIncident::create([
            'incident_number' => 'INC-CONFLICT-001',
            'title' => 'Concurrent incident',
            'incident_type' => 'vehicle_breakdown',
            'chainage' => 'Ch 11+200',
            'direction' => 'northbound',
            'severity' => 'major',
            'status' => 'detected',
            'reported_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/om/field/incidents/{$incident->id}", [
            'status' => 'dispatched',
            'lock_version' => 0,
        ])->assertOk()->assertJsonPath('incident.lock_version', 1);

        $this->postJson("/api/v1/om/field/incidents/{$incident->id}", [
            'status' => 'on_scene',
            'lock_version' => 0,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'STALE_WRITE')
            ->assertJsonPath('current_version', 1);

        $this->assertDatabaseHas('om_incidents', [
            'id' => $incident->id,
            'status' => 'dispatched',
            'lock_version' => 1,
        ]);
    }

    public function test_inertia_om_mutation_redirects_instead_of_returning_raw_json(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeader('X-Inertia', 'true')
            ->from('/om/incidents')
            ->post('/om/incidents', [
                'title' => 'Inertia response contract',
                'incident_type' => 'vehicle_breakdown',
                'chainage' => 'Ch 12+300',
                'direction' => 'southbound',
                'severity' => 'minor',
            ]);

        $response->assertRedirect('/om/incidents')
            ->assertSessionHas('success');
    }

    public function test_inertia_stale_write_refreshes_page_with_a_conflict_error(): void
    {
        $incident = OmIncident::create([
            'incident_number' => 'INC-INERTIA-CONFLICT',
            'title' => 'Inertia concurrency test',
            'incident_type' => 'vehicle_breakdown',
            'chainage' => 'Ch 13+400',
            'direction' => 'northbound',
            'severity' => 'major',
            'status' => 'detected',
            'reported_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/om/incidents/{$incident->id}/status", [
                'status' => 'dispatched',
                'lock_version' => 0,
            ])->assertOk();

        $this->actingAs($this->user)
            ->withHeader('X-Inertia', 'true')
            ->from('/om/incidents')
            ->post("/om/incidents/{$incident->id}/status", [
                'status' => 'on_scene',
                'lock_version' => 0,
            ])->assertRedirect('/om/incidents')
            ->assertSessionHasErrors('conflict');

        $this->assertSame('dispatched', $incident->fresh()->status);
    }

    public function test_mobile_field_overview_only_returns_authorized_domains(): void
    {
        OmIncident::create([
            'incident_number' => 'INC-ACCESS-001',
            'title' => 'Restricted incident',
            'incident_type' => 'vehicle_breakdown',
            'chainage' => 'Ch 8+200',
            'direction' => 'northbound',
            'severity' => 'minor',
            'status' => 'detected',
            'reported_at' => now(),
        ]);
        OmDefect::create([
            'defect_number' => 'DEF-ACCESS-001',
            'title' => 'Visible maintenance defect',
            'distress_type' => 'pothole',
            'chainage' => 'Ch 9+100',
            'direction' => 'southbound',
            'severity' => 'medium',
            'status' => 'reported',
        ]);

        $maintenanceViewer = User::factory()->create();
        $maintenanceViewer->givePermissionTo('om.maintenance.manage');
        Sanctum::actingAs($maintenanceViewer);

        $this->getJson('/api/v1/om/field/overview')
            ->assertOk()
            ->assertJsonCount(0, 'data.active_incidents')
            ->assertJsonCount(1, 'data.open_defects')
            ->assertJsonPath('data.active_patrol_shift', null);
    }
}
