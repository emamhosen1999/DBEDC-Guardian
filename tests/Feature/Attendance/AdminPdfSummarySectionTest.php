<?php

namespace Tests\Feature\Attendance;

use Carbon\Carbon;
use Tests\TestCase;

class AdminPdfSummarySectionTest extends TestCase
{
    /**
     * Minimal summary array matching the shape returned by
     * AttendanceReportService::getPerEmployeeMonthlySummary().
     */
    private function buildSummary(): array
    {
        return [
            'meta' => [
                'month'          => '2026-06',
                'generatedAt'    => '2026-06-23 00:00:00',
                'departmentId'   => null,
                'departmentName' => null,
            ],
            'rows' => [
                [
                    'employee_name'      => 'Alice Tester',
                    'employee_id'        => 'EMP001',
                    'department'         => 'Engineering',
                    'present'            => 20,
                    'absent'             => 2,
                    'leave'              => 1,
                    'ot_hours'           => 3.5,
                    'late'               => 1,
                    'holidays_worked'    => 0,
                    'weekly_off_worked'  => 0,
                    'working_days'       => 23,
                    'attendance_percentage' => '86.96%',
                ],
            ],
        ];
    }

    /**
     * Build the minimal set of variables the blade needs.
     * The grid loops $users / $attendanceData / $leaveTypes but handles empty
     * collections without error (forelse / for loop up to $to->day).
     */
    private function baseViewData(): array
    {
        $from = Carbon::create(2026, 6, 1);
        $to   = $from->copy()->endOfMonth();

        return [
            'monthName'    => $from->format('F Y'),
            'from'         => $from,
            'to'           => $to,
            'users'        => collect(),
            'attendanceData' => [],
            'leaveTypes'   => collect(),
        ];
    }

    /** Renders the blade and returns HTML string. */
    private function renderBlade(array $data): string
    {
        return view('attendance_admin_pdf', $data)->render();
    }

    /** @test */
    public function summary_section_is_rendered_when_summary_is_present(): void
    {
        $data = array_merge($this->baseViewData(), ['summary' => $this->buildSummary()]);

        $html = $this->renderBlade($data);

        // Heading
        $this->assertStringContainsString('Per-Employee Summary', $html);
        $this->assertStringContainsString('June 2026', $html);

        // Column headers
        $this->assertStringContainsString('Working Days', $html);
        $this->assertStringContainsString('Attendance %', $html);

        // Caveat line
        $this->assertStringContainsString(
            'half-days are not yet split',
            $html
        );

        // Row data
        $this->assertStringContainsString('Alice Tester', $html);
        $this->assertStringContainsString('EMP001', $html);
        $this->assertStringContainsString('Engineering', $html);

        // TOTAL footer row
        $this->assertStringContainsString('TOTAL', $html);
    }

    /** @test */
    public function summary_section_is_absent_when_summary_variable_is_not_passed(): void
    {
        // No 'summary' key — simulates older call sites or direct blade rendering.
        $data = $this->baseViewData();

        // Must NOT throw
        $html = $this->renderBlade($data);

        $this->assertStringNotContainsString('Per-Employee Summary', $html);
    }
}
