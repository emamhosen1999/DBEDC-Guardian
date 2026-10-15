<?php

namespace App\Exports;

use Carbon\Carbon;

/**
 * Kept for callers of the old monthly export: a calendar month is now just a period of the
 * attendance register (see AttendanceRegisterExport), so this delegates to it.
 */
class AttendanceAdminExport
{
    /** @param array<int, string>|null $employeeIds actor scope (DepartmentScope::visibleEmployeeIds); null = unrestricted */
    public function __construct(protected ?int $departmentId = null, protected ?array $employeeIds = null) {}

    public function saveToDisk($month, $filePath, $disk = 'public'): void
    {
        $from = Carbon::parse($month.'-01')->startOfDay();
        (new AttendanceRegisterExport($this->employeeIds, $this->departmentId))
            ->saveToDisk($from, $from->copy()->endOfMonth()->startOfDay(), $filePath, $disk);
    }
}
