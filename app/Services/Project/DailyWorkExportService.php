<?php

namespace App\Services\Project;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use App\Traits\DailyWorkFilterable;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DailyWorkExportService
{
    use DailyWorkFilterable;

    /**
     * Prepare export data for DailyWorks based on filters and selected columns.
     */
    public function prepareExportData(User $user, array $filters, array $selectedColumns): array
    {
        $userRoles = $user->roles->pluck('name')->toArray();
        $isAdmin = in_array('Super Administrator', $userRoles) || in_array('Administrator', $userRoles);
        $userDesignationTitle = $user->designation?->title;

        $query = DailyWork::with(['inchargeUser', 'assignedUser', 'reports'])
            ->withCount(['objections as active_objections_count' => function ($q) {
                $q->whereIn('status', ['draft', 'submitted', 'under_review']);
            }]);

        // Apply user role filter
        if (! $isAdmin) {
            if ($userDesignationTitle === 'Supervision Engineer') {
                $query->where('incharge', $user->id);
            } elseif (in_array('Employee', $userRoles)) {
                // Employee can only export works where they are incharge or assigned
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id);
                });
            }
        }

        // Apply filters from request
        if (! empty($filters['startDate']) && ! empty($filters['endDate'])) {
            $query->whereBetween('date', [$filters['startDate'], $filters['endDate']]);
        }

        $inchargeFilter = $this->normalizeIdFilter($filters['incharge'] ?? null);
        $jurisdictionFilter = $this->normalizeIdFilter($filters['jurisdiction'] ?? null);

        $this->applyInchargeJurisdictionFilters($query, $inchargeFilter, $jurisdictionFilter);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            // Split search into words (handle multiple spaces)
            $words = array_filter(preg_split('/\s+/', trim($search)));

            if (! empty($words)) {
                $query->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        // Each word must match at least one column (AND between words)
                        $q->where(function ($wordQuery) use ($word) {
                            // Word can match any column (OR within each word)
                            $wordQuery->where('number', 'like', "%{$word}%")
                                ->orWhere('description', 'like', "%{$word}%")
                                ->orWhere('location', 'like', "%{$word}%")
                                ->orWhere('type', 'like', "%{$word}%")
                                ->orWhere('side', 'like', "%{$word}%")
                                ->orWhere('inspection_details', 'like', "%{$word}%");
                        });
                    }
                });
            }
        }

        // Filter to only RFIs with active objections if requested
        if (! empty($filters['only_with_objections']) && $filters['only_with_objections'] === true) {
            $query->has('objections', '>=', 1, 'and', function ($q) {
                $q->whereIn('status', ['draft', 'submitted', 'under_review']);
            });
        }

        $dailyWorks = $query->get();

        // Load objection details if needed
        if (! empty($filters['include_objection_details']) && $filters['include_objection_details'] === true) {
            $dailyWorks->load(['objections' => function ($q) {
                $q->whereIn('status', ['draft', 'submitted', 'under_review'])
                    ->select('rfi_objections.id', 'title', 'category', 'status', 'chainage_from', 'chainage_to');
            }]);
        }

        $exportData = $dailyWorks->map(function ($work) use ($selectedColumns) {
            $row = [];

            foreach ($selectedColumns as $column) {
                switch ($column) {
                    case 'date':
                        $row['Date'] = $work->date->format('Y-m-d');
                        break;
                    case 'number':
                        $row['RFI Number'] = $work->number;
                        break;
                    case 'type':
                        $row['Type'] = $work->type;
                        break;
                    case 'description':
                        $row['Description'] = $work->description;
                        break;
                    case 'location':
                        $row['Location'] = $work->location;
                        break;
                    case 'status':
                        $row['Status'] = ucfirst($work->status);
                        break;
                    case 'incharge':
                        $row['In Charge'] = $work->inchargeUser?->name ?? 'N/A';
                        break;
                    case 'assigned':
                        $row['Assigned To'] = $work->assignedUser?->name ?? 'N/A';
                        break;
                    case 'completion_time':
                        $row['Completion Time'] = $work->completion_time?->format('Y-m-d H:i') ?? 'N/A';
                        break;
                    case 'rfi_submission_date':
                        $row['RFI Submission Date'] = $work->rfi_submission_date?->format('Y-m-d') ?? 'N/A';
                        break;
                    case 'side':
                        $row['Side'] = $work->side ?? 'N/A';
                        break;
                    case 'qty_layer':
                        $row['Qty/Layer'] = $work->qty_layer ?? 'N/A';
                        break;
                    case 'planned_time':
                        $row['Planned Time'] = $work->planned_time ?? 'N/A';
                        break;
                    case 'resubmission_count':
                        $row['Resubmission Count'] = $work->resubmission_count ?? 0;
                        break;
                    case 'active_objections_count':
                        $row['Active Objections'] = $work->active_objections_count ?? 0;
                        break;
                    case 'has_objections':
                        $row['Has Objections'] = ($work->active_objections_count ?? 0) > 0 ? 'Yes' : 'No';
                        break;
                    case 'objection_titles':
                        $row['Objection Titles'] = $work->objections?->pluck('title')->join('; ') ?? 'N/A';
                        break;
                    case 'objection_categories':
                        $row['Objection Categories'] = $work->objections?->pluck('category')->unique()->join('; ') ?? 'N/A';
                        break;
                }
            }

            return $row;
        });

        return $exportData->toArray();
    }

    /**
     * Export only RFIs with active objections along with objection details.
     */
    public function prepareObjectedRfisExportData(User $user, array $filters): array
    {
        $userDesignationTitle = $user->designation?->title;

        $query = DailyWork::with(['inchargeUser', 'assignedUser'])
            ->with(['objections' => function ($q) {
                $q->whereIn('status', ['draft', 'submitted', 'under_review'])
                    ->with('createdBy:id,name')
                    ->select('rfi_objections.id', 'title', 'category', 'status', 'chainage_from', 'chainage_to', 'description', 'created_by', 'created_at');
            }])
            ->withCount(['objections as active_objections_count' => function ($q) {
                $q->whereIn('status', ['draft', 'submitted', 'under_review']);
            }])
            ->having('active_objections_count', '>', 0);

        // Apply user role filter
        if ($userDesignationTitle === 'Supervision Engineer') {
            $query->where('incharge', $user->id);
        }

        // Apply filters
        if (! empty($filters['startDate']) && ! empty($filters['endDate'])) {
            $query->whereBetween('date', [$filters['startDate'], $filters['endDate']]);
        }

        $inchargeFilter = $this->normalizeIdFilter($filters['incharge'] ?? null);
        $jurisdictionFilter = $this->normalizeIdFilter($filters['jurisdiction'] ?? null);
        $this->applyInchargeJurisdictionFilters($query, $inchargeFilter, $jurisdictionFilter);

        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        $dailyWorks = $query->orderBy('location')->get();

        // Build export data - one row per RFI with all objection info combined
        $exportData = $dailyWorks->map(function ($work) {
            $objectionSummary = $work->objections->map(function ($obj) {
                $categoryLabel = RfiObjection::$categoryLabels[$obj->category] ?? $obj->category;
                $statusLabel = RfiObjection::$statusLabels[$obj->status] ?? $obj->status;

                return "{$obj->title} [{$categoryLabel}] - {$statusLabel}";
            })->join(' | ');

            return [
                'RFI Number' => $work->number,
                'Date' => $work->date->format('Y-m-d'),
                'Type' => $work->type,
                'Location' => $work->location,
                'Description' => $work->description,
                'Status' => ucfirst($work->status),
                'In Charge' => $work->inchargeUser?->name ?? 'N/A',
                'RFI Submission Date' => $work->rfi_submission_date?->format('Y-m-d') ?? 'Not Submitted',
                'Active Objections Count' => $work->active_objections_count,
                'Objection Details' => $objectionSummary ?: 'N/A',
            ];
        });

        return $exportData->toArray();
    }

    /**
     * Download template for bulk import submission.
     */
    public function generateBulkImportTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $sheet->setCellValue('A1', 'RFI Number');
        $sheet->setCellValue('B1', 'Submission Date');

        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ];
        $sheet->getStyle('A1:B1')->applyFromArray($headerStyle);

        // Add example rows
        $sheet->setCellValue('A2', 'RFI-001');
        $sheet->setCellValue('B2', date('Y-m-d'));

        $sheet->setCellValue('A3', 'RFI-002');
        $sheet->setCellValue('B3', date('Y-m-d', strtotime('+1 day')));

        // Auto-size columns
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        // Create temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'rfi_import_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Download template for bulk import response status.
     */
    public function generateResponseStatusTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $sheet->setCellValue('A1', 'RFI Number');
        $sheet->setCellValue('B1', 'Response Status');
        $sheet->setCellValue('C1', 'Response Date');

        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ];
        $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);

        // Add example rows with valid status values
        $sheet->setCellValue('A2', 'RFI-001');
        $sheet->setCellValue('B2', 'approved');
        $sheet->setCellValue('C2', date('Y-m-d'));

        $sheet->setCellValue('A3', 'RFI-002');
        $sheet->setCellValue('B3', 'rejected');
        $sheet->setCellValue('C3', date('Y-m-d'));

        $sheet->setCellValue('A4', 'RFI-003');
        $sheet->setCellValue('B4', 'returned');
        $sheet->setCellValue('C4', date('Y-m-d'));

        $sheet->setCellValue('A5', 'RFI-004');
        $sheet->setCellValue('B5', 'concurred');
        $sheet->setCellValue('C5', date('Y-m-d'));

        $sheet->setCellValue('A6', 'RFI-005');
        $sheet->setCellValue('B6', 'not_concurred');
        $sheet->setCellValue('C6', date('Y-m-d'));

        // Add instructions sheet
        $instructionSheet = $spreadsheet->createSheet();
        $instructionSheet->setTitle('Instructions');
        $instructionSheet->setCellValue('A1', 'Valid Response Status Values:');
        $instructionSheet->setCellValue('A2', '- approved');
        $instructionSheet->setCellValue('A3', '- rejected');
        $instructionSheet->setCellValue('A4', '- returned');
        $instructionSheet->setCellValue('A5', '- concurred');
        $instructionSheet->setCellValue('A6', '- not_concurred');
        $instructionSheet->setCellValue('A8', 'Date Format: YYYY-MM-DD (e.g., 2024-12-20)');

        // Auto-size columns
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        $sheet->getColumnDimension('C')->setAutoSize(true);

        // Create temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'rfi_response_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Excel date parsing logic.
     */
    public function parseExcelDate(mixed $dateValue): ?string
    {
        if (empty($dateValue)) {
            return null;
        }

        // Handle Excel numeric date format
        if (is_numeric($dateValue)) {
            try {
                // Excel dates: use timezone-safe parsing
                $dateObj = Date::excelToDateTimeObject($dateValue);

                return Carbon::instance($dateObj)->startOfDay()->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        // Try to parse string date with explicit format to avoid timezone shifts
        try {
            $dateStr = trim($dateValue);
            // Try common formats explicitly
            $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'Y/m/d'];
            $parsed_date = null;
            foreach ($formats as $format) {
                try {
                    $parsed_date = Carbon::createFromFormat($format, $dateStr);
                    if ($parsed_date && $parsed_date->format($format) === $dateStr) {
                        break;
                    }
                    $parsed_date = null;
                } catch (\Exception $e) {
                    continue;
                }
            }
            if (! $parsed_date) {
                // Fallback to Carbon::parse but with startOfDay to avoid timezone shifts
                $parsed_date = Carbon::parse($dateStr)->startOfDay();
            }

            return $parsed_date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
