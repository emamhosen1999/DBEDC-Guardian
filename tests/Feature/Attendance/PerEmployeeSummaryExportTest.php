<?php

namespace Tests\Feature\Attendance;

use App\Exports\AttendancePerEmployeeSummaryExport;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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

    public function test_write_sheet_sets_title_and_header_labels(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $summary = app(AttendanceReportService::class)->getPerEmployeeMonthlySummary(2026, 6);

        (new AttendancePerEmployeeSummaryExport)->writeSheet($sheet, $summary);

        // Sheet title must be 'Summary'.
        $this->assertSame('Summary', $sheet->getTitle());

        // Collect all cell values from the header row (row 5) into a flat array.
        $headerValues = [];
        foreach (range('A', 'N') as $col) {
            $headerValues[] = $sheet->getCell("{$col}5")->getValue();
        }

        $this->assertContains('Present', $headerValues);
        $this->assertContains('Attendance %', $headerValues);
        $this->assertContains('Employee', $headerValues);
        $this->assertContains('Department', $headerValues);
        $this->assertContains('Paid Leave', $headerValues);
        $this->assertContains('LWP', $headerValues);
    }
}
