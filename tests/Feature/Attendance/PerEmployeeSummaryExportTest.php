<?php

namespace Tests\Feature\Attendance;

use App\Exports\AttendancePerEmployeeSummaryExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class PerEmployeeSummaryExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_export_returns_xlsx_download(): void
    {
        $response = (new AttendancePerEmployeeSummaryExport)->export(2026, 6);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
        $this->assertStringContainsString('Summary', $disposition);
    }
}
