<?php

namespace Tests\Unit\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkAudit;
use App\Models\User;
use App\Services\DailyWork\DailyWorkAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyWorkAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyWorkAuditService $auditService;
    private User $user;
    private DailyWork $dailyWork;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditService = new DailyWorkAuditService();
        $this->user = User::factory()->create();
        $this->dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
        ]);
    }

    public function test_log_creation_creates_audit_record(): void
    {
        // Act
        $audit = $this->auditService->logCreation($this->dailyWork);

        // Assert
        $this->assertInstanceOf(DailyWorkAudit::class, $audit);
        $this->assertEquals(DailyWorkAudit::ACTION_CREATED, $audit->action);
        $this->assertEquals($this->dailyWork->id, $audit->daily_work_id);
        $this->assertEquals(DailyWorkAudit::ENTITY_DAILY_WORK, $audit->entity_type);
        $this->assertEquals($this->dailyWork->id, $audit->entity_id);
        $this->assertNotNull($audit->new_values);
        $this->assertStringContains('created', $audit->description);
    }

    public function test_log_update_creates_audit_record_with_changes(): void
    {
        // Arrange
        $oldValues = [
            'status' => 'pending',
            'description' => 'Original description',
        ];
        $newValues = [
            'status' => 'completed',
            'description' => 'Updated description',
        ];

        // Act
        $audit = $this->auditService->logUpdate(
            $this->dailyWork,
            $oldValues,
            $newValues
        );

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_UPDATED, $audit->action);
        $this->assertEquals($oldValues, $audit->old_values);
        $this->assertEquals($newValues, $audit->new_values);
        $this->assertStringContains('updated', $audit->description);
        $this->assertStringContains('changed: status, description', $audit->description);
    }

    public function test_log_deletion_creates_audit_record(): void
    {
        // Act
        $audit = $this->auditService->logDeletion($this->dailyWork);

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_DELETED, $audit->action);
        $this->assertEquals($this->dailyWork->id, $audit->daily_work_id);
        $this->assertNotNull($audit->old_values);
        $this->assertStringContains('deleted', $audit->description);
    }

    public function test_log_status_change_creates_audit_record(): void
    {
        // Act
        $audit = $this->auditService->logStatusChange(
            $this->dailyWork,
            'pending',
            'completed'
        );

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_STATUS_CHANGED, $audit->action);
        $this->assertEquals(['status' => 'pending'], $audit->old_values);
        $this->assertEquals(['status' => 'completed'], $audit->new_values);
        $this->assertStringContains('Status changed from pending to completed', $audit->description);
    }

    public function test_log_assignment_change_creates_audit_record(): void
    {
        // Arrange
        $newUser = User::factory()->create();

        // Act - Assignment
        $audit = $this->auditService->logAssignmentChange(
            $this->dailyWork,
            'assigned',
            null,
            $newUser->id
        );

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_ASSIGNED, $audit->action);
        $this->assertEquals(['assigned' => null], $audit->old_values);
        $this->assertEquals(['assigned' => $newUser->id], $audit->new_values);
        $this->assertStringContains('assigned to user', $audit->description);

        // Act - Unassignment
        $audit2 = $this->auditService->logAssignmentChange(
            $this->dailyWork,
            'assigned',
            $newUser->id,
            null
        );

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_UNASSIGNED, $audit2->action);
        $this->assertStringContains('unassigned from user', $audit2->description);
    }

    public function test_log_file_upload_creates_audit_record(): void
    {
        // Arrange
        $fileName = 'test_document.pdf';

        // Act
        $audit = $this->auditService->logFileUpload($this->dailyWork, $fileName);

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_FILE_UPLOADED, $audit->action);
        $this->assertEquals(DailyWorkAudit::ENTITY_FILE, $audit->entity_type);
        $this->assertEquals(['file_name' => $fileName], $audit->new_values);
        $this->assertStringContains('uploaded to daily work', $audit->description);
    }

    public function test_log_file_deletion_creates_audit_record(): void
    {
        // Arrange
        $fileName = 'test_document.pdf';

        // Act
        $audit = $this->auditService->logFileDeletion($this->dailyWork, $fileName);

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_FILE_DELETED, $audit->action);
        $this->assertEquals(DailyWorkAudit::ENTITY_FILE, $audit->entity_type);
        $this->assertEquals(['file_name' => $fileName], $audit->old_values);
        $this->assertStringContains('deleted from daily work', $audit->description);
    }

    public function test_log_objection_creation_creates_audit_record(): void
    {
        // Arrange
        $objectionId = 123;
        $objectionTitle = 'Quality Issue';

        // Act
        $audit = $this->auditService->logObjectionCreation(
            $this->dailyWork,
            $objectionId,
            $objectionTitle
        );

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_OBJECTION_CREATED, $audit->action);
        $this->assertEquals(DailyWorkAudit::ENTITY_OBJECTION, $audit->entity_type);
        $this->assertEquals($objectionId, $audit->entity_id);
        $this->assertEquals(['objection_id' => $objectionId, 'title' => $objectionTitle], $audit->new_values);
        $this->assertStringContains('Objection', $audit->description);
    }

    public function test_log_bulk_operation_creates_audit_record(): void
    {
        // Arrange
        $details = [
            'total_records' => 100,
            'successful_records' => 95,
            'failed_records' => 5,
            'operation_details' => ['operation' => 'import'],
        ];

        // Act
        $audit = $this->auditService->logBulkOperation(
            DailyWorkAudit::ACTION_BULK_CREATED,
            $details
        );

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_BULK_CREATED, $audit->action);
        $this->assertTrue($audit->is_bulk_operation);
        $this->assertEquals($details['total_records'], $audit->bulk_operation_details['total_records']);
        $this->assertEquals($details['successful_records'], $audit->bulk_operation_details['successful_records']);
        $this->assertEquals($details['failed_records'], $audit->bulk_operation_details['failed_records']);
        $this->assertStringContains('Bulk operation', $audit->description);
    }

    public function test_log_export_creates_audit_record(): void
    {
        // Arrange
        $filters = ['status' => 'completed'];
        $recordCount = 50;

        // Act
        $audit = $this->auditService->logExport($filters, $recordCount);

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_EXPORTED, $audit->action);
        $this->assertEquals(['filters' => $filters, 'record_count' => $recordCount], $audit->new_values);
        $this->assertStringContains('exported', $audit->description);
    }

    public function test_log_import_creates_audit_record(): void
    {
        // Arrange
        $details = [
            'total_records' => 100,
            'successful_records' => 95,
            'failed_records' => 5,
            'operation_details' => ['file' => 'import.xlsx'],
        ];

        // Act
        $audit = $this->auditService->logImport($details);

        // Assert
        $this->assertEquals(DailyWorkAudit::ACTION_IMPORTED, $audit->action);
        $this->assertTrue($audit->is_bulk_operation);
        $this->assertStringContains('imported', $audit->description);
    }

    public function test_get_audit_trail_returns_paginated_audits(): void
    {
        // Arrange
        DailyWorkAudit::factory()->count(15)->create([
            'daily_work_id' => $this->dailyWork->id,
        ]);

        // Act
        $result = $this->auditService->getAuditTrail($this->dailyWork->id, [
            'per_page' => 10,
        ]);

        // Assert
        $this->assertArrayHasKey('audits', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('statistics', $result);
        $this->assertCount(10, $result['audits']);
        $this->assertEquals(1, $result['pagination']['current_page']);
        $this->assertEquals(10, $result['pagination']['per_page']);
        $this->assertEquals(15, $result['pagination']['total']);
    }

    public function test_get_audit_trail_applies_filters(): void
    {
        // Arrange
        DailyWorkAudit::factory()->create([
            'daily_work_id' => $this->dailyWork->id,
            'action' => DailyWorkAudit::ACTION_CREATED,
            'created_at' => now()->subDays(2),
        ]);
        DailyWorkAudit::factory()->create([
            'daily_work_id' => $this->dailyWork->id,
            'action' => DailyWorkAudit::ACTION_UPDATED,
            'created_at' => now()->subDay(),
        ]);

        // Act
        $result = $this->auditService->getAuditTrail($this->dailyWork->id, [
            'action' => DailyWorkAudit::ACTION_CREATED,
            'start_date' => now()->subDays(3)->format('Y-m-d'),
            'end_date' => now()->subDays(1)->format('Y-m-d'),
        ]);

        // Assert
        $this->assertCount(1, $result['audits']);
        $this->assertEquals(DailyWorkAudit::ACTION_CREATED, $result['audits'][0]['action']);
    }

    public function test_get_audit_statistics_returns_correct_statistics(): void
    {
        // Arrange
        DailyWorkAudit::factory()->count(5)->create([
            'daily_work_id' => $this->dailyWork->id,
            'action' => DailyWorkAudit::ACTION_UPDATED,
        ]);
        DailyWorkAudit::factory()->count(3)->create([
            'daily_work_id' => $this->dailyWork->id,
            'action' => DailyWorkAudit::ACTION_CREATED,
        ]);
        DailyWorkAudit::factory()->create([
            'daily_work_id' => $this->dailyWork->id,
            'action' => DailyWorkAudit::ACTION_DELETED,
            'is_bulk_operation' => true,
        ]);

        // Act
        $statistics = $this->auditService->getAuditStatistics($this->dailyWork->id);

        // Assert
        $this->assertEquals(9, $statistics['total_audits']);
        $this->assertEquals(5, $statistics['actions_by_type'][DailyWorkAudit::ACTION_UPDATED]);
        $this->assertEquals(3, $statistics['actions_by_type'][DailyWorkAudit::ACTION_CREATED]);
        $this->assertEquals(1, $statistics['bulk_operations']);
        $this->assertEquals(1, $statistics['critical_actions']);
    }

    public function test_get_system_audit_statistics_returns_correct_data(): void
    {
        // Arrange
        DailyWorkAudit::factory()->count(10)->create();
        DailyWorkAudit::factory()->count(3)->create([
            'is_bulk_operation' => true,
        ]);

        // Act
        $statistics = $this->auditService->getSystemAuditStatistics();

        // Assert
        $this->assertEquals(13, $statistics['total_audits']);
        $this->assertEquals(3, $statistics['bulk_operations']);
        $this->assertEquals(10, $statistics['individual_operations']);
    }

    public function test_get_recent_activity_returns_limited_audits(): void
    {
        // Arrange
        DailyWorkAudit::factory()->count(25)->create();

        // Act
        $activity = $this->auditService->getRecentActivity(10);

        // Assert
        $this->assertCount(10, $activity);
        $this->assertEquals('created_at', array_keys($activity[0])[0]);
    }

    public function test_search_audits_applies_search_parameters(): void
    {
        // Arrange
        DailyWorkAudit::factory()->create([
            'description' => 'Daily work RFI-001 was created',
            'action' => DailyWorkAudit::ACTION_CREATED,
        ]);
        DailyWorkAudit::factory()->create([
            'description' => 'Daily work RFI-002 was updated',
            'action' => DailyWorkAudit::ACTION_UPDATED,
        ]);

        // Act
        $result = $this->auditService->searchAudits([
            'query' => 'RFI-001',
            'action' => DailyWorkAudit::ACTION_CREATED,
        ]);

        // Assert
        $this->assertCount(1, $result['audits']);
        $this->assertStringContains('RFI-001', $result['audits'][0]['description']);
    }

    public function test_cleanup_old_audits_removes_old_records(): void
    {
        // Arrange
        DailyWorkAudit::factory()->count(5)->create([
            'created_at' => now()->subDays(100),
        ]);
        DailyWorkAudit::factory()->count(3)->create([
            'created_at' => now()->subDays(10),
        ]);

        // Act
        $deletedCount = $this->auditService->cleanupOldAudits(30);

        // Assert
        $this->assertEquals(5, $deletedCount);
        $this->assertEquals(3, DailyWorkAudit::count());
    }

    public function test_export_audits_returns_formatted_data(): void
    {
        // Arrange
        DailyWorkAudit::factory()->count(3)->create();

        // Act
        $exportData = $this->auditService->exportAudits();

        // Assert
        $this->assertArrayHasKey('audits', $exportData);
        $this->assertArrayHasKey('total_count', $exportData);
        $this->assertArrayHasKey('exported_at', $exportData);
        $this->assertEquals(3, $exportData['total_count']);
        $this->assertCount(3, $exportData['audits']);
        
        // Check structure of exported audit
        $audit = $exportData['audits'][0];
        $this->assertArrayHasKey('id', $audit);
        $this->assertArrayHasKey('action', $audit);
        $this->assertArrayHasKey('description', $audit);
        $this->assertArrayHasKey('user_name', $audit);
        $this->assertArrayHasKey('source', $audit);
        $this->assertArrayHasKey('created_at', $audit);
    }

    public function test_audit_model_relationships_work(): void
    {
        // Arrange
        $audit = DailyWorkAudit::factory()->create([
            'daily_work_id' => $this->dailyWork->id,
            'user_id' => $this->user->id,
        ]);

        // Act
        $loadedAudit = DailyWorkAudit::with(['dailyWork', 'user'])->find($audit->id);

        // Assert
        $this->assertInstanceOf(DailyWork::class, $loadedAudit->dailyWork);
        $this->assertInstanceOf(User::class, $loadedAudit->user);
        $this->assertEquals($this->dailyWork->id, $loadedAudit->dailyWork->id);
        $this->assertEquals($this->user->id, $loadedAudit->user->id);
    }

    public function test_audit_model_scopes_work(): void
    {
        // Arrange
        DailyWorkAudit::factory()->count(3)->create([
            'action' => DailyWorkAudit::ACTION_CREATED,
        ]);
        DailyWorkAudit::factory()->count(2)->create([
            'action' => DailyWorkAudit::ACTION_UPDATED,
        ]);

        // Act & Assert
        $createdAudits = DailyWorkAudit::byAction(DailyWorkAudit::ACTION_CREATED)->get();
        $this->assertCount(3, $createdAudits);

        $updatedAudits = DailyWorkAudit::byAction(DailyWorkAudit::ACTION_UPDATED)->get();
        $this->assertCount(2, $updatedAudits);

        $bulkAudits = DailyWorkAudit::bulkOperations()->get();
        $this->assertCount(0, $bulkAudits); // No bulk operations created
    }

    public function test_audit_model_constants_are_defined(): void
    {
        // Assert
        $this->assertEquals('created', DailyWorkAudit::ACTION_CREATED);
        $this->assertEquals('updated', DailyWorkAudit::ACTION_UPDATED);
        $this->assertEquals('deleted', DailyWorkAudit::ACTION_DELETED);
        $this->assertEquals('daily_work', DailyWorkAudit::ENTITY_DAILY_WORK);
        $this->assertEquals('web', DailyWorkAudit::SOURCE_WEB);
        $this->assertEquals('mobile', DailyWorkAudit::SOURCE_MOBILE);
    }
}
