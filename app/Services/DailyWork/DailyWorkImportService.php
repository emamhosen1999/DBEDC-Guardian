<?php

namespace App\Services\DailyWork;

use App\Imports\DailyWorkImport;
use App\Models\DailyWork;
use App\Models\DailyWorkSummary;
use App\Models\User;
use App\Traits\JurisdictionMatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class DailyWorkImportService
{
    use JurisdictionMatcher;

    private DailyWorkValidationService $validationService;

    public function __construct(DailyWorkValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Preview Excel/CSV import without processing
     */
    public function previewImport(Request $request): array
    {
        $this->validationService->validateImportFile($request);

        $path = $request->file('file')->store('temp');
        $importedSheets = Excel::toArray(new DailyWorkImport, $path);

        // Validate all sheets
        foreach ($importedSheets as $sheetIndex => $importedDailyWorks) {
            if (empty($importedDailyWorks)) {
                continue;
            }

            $this->validationService->validateImportedData($importedDailyWorks, $sheetIndex);
        }

        // Check for duplicate dates so user sees the error at preview stage
        $this->validateDuplicateDates($importedSheets);

        // Generate preview summary without processing
        $summary = [];
        foreach ($importedSheets as $sheetIndex => $importedDailyWorks) {
            if (empty($importedDailyWorks)) {
                continue;
            }

            $summary[] = $this->generateSheetSummary($importedDailyWorks, $sheetIndex);
        }

        return $summary;
    }

    /**
     * Process Excel/CSV import
     */
    public function processImport(Request $request): array
    {
        $this->validationService->validateImportFile($request);

        $path = $request->file('file')->store('temp');
        $importedSheets = Excel::toArray(new DailyWorkImport, $path);

        // First pass: Validate all sheets
        foreach ($importedSheets as $sheetIndex => $importedDailyWorks) {
            if (empty($importedDailyWorks)) {
                continue;
            }

            $this->validationService->validateImportedData($importedDailyWorks, $sheetIndex);
        }

        // Check for duplicate dates before processing
        $this->validateDuplicateDates($importedSheets);

        // Second pass: Process the data within a transaction
        return DB::transaction(function () use ($importedSheets) {
            $results = [];
            foreach ($importedSheets as $sheetIndex => $importedDailyWorks) {
                if (empty($importedDailyWorks)) {
                    continue;
                }

                $result = $this->processSheet($importedDailyWorks, $sheetIndex);
                $results[] = $result;
            }

            return $results;
        });
    }

    /**
     * Generate summary for a single sheet (preview mode)
     */
    private function generateSheetSummary(array $importedDailyWorks, int $sheetIndex): array
    {
        $date = $this->normalizeDate($importedDailyWorks[0][0]) ?? $importedDailyWorks[0][0];
        $newWorks = 0;
        $resubmissions = 0;
        $skipped = 0;

        foreach ($importedDailyWorks as $importedDailyWork) {
            $existingDailyWork = DailyWork::where('number', $importedDailyWork[1])->first();

            if ($existingDailyWork) {
                // Check if completed with pass/fail
                if ($existingDailyWork->status === DailyWork::STATUS_COMPLETED &&
                    in_array($existingDailyWork->inspection_result, ['pass', 'fail'])) {
                    $skipped++;
                } else {
                    $resubmissions++;
                }
            } else {
                $newWorks++;
            }
        }

        return [
            'sheet' => $sheetIndex + 1,
            'date' => $date,
            'total_rows' => count($importedDailyWorks),
            'new_works' => $newWorks,
            'resubmissions' => $resubmissions,
            'skipped' => $skipped,
        ];
    }

    /**
     * Validate that import dates don't already exist in daily_work_summaries
     */
    private function validateDuplicateDates(array $importedSheets): void
    {
        $dates = [];
        foreach ($importedSheets as $importedDailyWorks) {
            if (empty($importedDailyWorks)) {
                continue;
            }
            $normalized = $this->normalizeDate($importedDailyWorks[0][0]);
            if ($normalized !== null) {
                $dates[] = $normalized;
            }
        }

        if (empty($dates)) {
            return;
        }

        $dates = array_unique($dates);

        $existingDates = DailyWorkSummary::whereIn('date', $dates)
            ->pluck('date')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->unique()
            ->values()
            ->toArray();

        if (! empty($existingDates)) {
            throw ValidationException::withMessages([
                'date' => 'Import contains data for dates that already exist in the system: '.implode(', ', $existingDates).'. Please remove these dates from the import file or delete existing data first.',
            ]);
        }
    }

    /**
     * Normalize a date value from an import file into Y-m-d format.
     * Handles Excel serial numbers, common date strings, and DateTime instances.
     */
    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        // Excel serial date number (numeric)
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m-d');
            } catch (\Throwable $e) {
                // fall through to string parsing
            }
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Process a single sheet of daily works
     */
    private function processSheet(array $importedDailyWorks, int $sheetIndex): array
    {
        $date = $this->normalizeDate($importedDailyWorks[0][0]) ?? $importedDailyWorks[0][0];
        $inChargeSummary = [];

        foreach ($importedDailyWorks as $importedDailyWork) {
            $result = $this->processDailyWorkRow($importedDailyWork, $date, $inChargeSummary);

            if ($result['processed']) {
                $inChargeSummary = $result['summary'];
            }
        }

        // Create or update daily summaries
        $this->createDailySummaries($inChargeSummary, $date);

        return [
            'sheet' => $sheetIndex + 1,
            'date' => $date,
            'summaries' => $inChargeSummary,
            'processed_count' => count($importedDailyWorks),
        ];
    }

    /**
     * Process a single daily work row
     */
    private function processDailyWorkRow(array $importedDailyWork, string $date, array &$inChargeSummary): array
    {
        // Extract chainages and find jurisdiction
        $jurisdiction = $this->findJurisdictionForLocation($importedDailyWork[4]);

        if (! $jurisdiction) {
            Log::warning('No jurisdiction found for location: '.$importedDailyWork[4]);

            return ['processed' => false, 'summary' => $inChargeSummary];
        }

        $inCharge = $jurisdiction->incharge;
        $inChargeUser = User::find($inCharge);
        $inChargeName = $inChargeUser ? $inChargeUser->user_name : 'unknown';

        // Initialize incharge summary if not exists
        if (! isset($inChargeSummary[$inCharge])) {
            $inChargeSummary[$inCharge] = [
                'totalDailyWorks' => 0,
                'resubmissions' => 0,
                'embankment' => 0,
                'structure' => 0,
                'pavement' => 0,
            ];
        }

        // Update summary counters
        $inChargeSummary[$inCharge]['totalDailyWorks']++;
        $this->updateTypeCounter($inChargeSummary[$inCharge], $importedDailyWork[2]);

        // Handle existing or new daily work
        $existingDailyWork = DailyWork::where('number', $importedDailyWork[1])->first();

        if ($existingDailyWork) {
            $this->handleResubmission($existingDailyWork, $importedDailyWork, $inChargeSummary[$inCharge], $inCharge);
        } else {
            $this->createNewDailyWork($importedDailyWork, $inCharge);
        }

        return ['processed' => true, 'summary' => $inChargeSummary];
    }

    /**
     * Update type counter in summary
     */
    private function updateTypeCounter(array &$summary, string $type): void
    {
        switch ($type) {
            case DailyWork::TYPE_EMBANKMENT:
                $summary['embankment']++;
                break;
            case DailyWork::TYPE_STRUCTURE:
                $summary['structure']++;
                break;
            case DailyWork::TYPE_PAVEMENT:
                $summary['pavement']++;
                break;
        }
    }

    /**
     * Handle resubmission of existing daily work
     */
    private function handleResubmission(DailyWork $existingDailyWork, array $importedDailyWork, array &$summary, int $inChargeId): void
    {
        // Check if work is completed with pass/fail - skip update if so
        if ($existingDailyWork->status === DailyWork::STATUS_COMPLETED &&
            in_array($existingDailyWork->inspection_result, ['pass', 'fail'])) {
            Log::info('Skipping update for completed work with pass/fail: '.$existingDailyWork->number);
            return;
        }

        $summary['resubmissions']++;
        $resubmissionCount = ($existingDailyWork->resubmission_count ?? 0) + 1;
        $resubmissionDate = $this->getResubmissionDate($existingDailyWork, $resubmissionCount);

        // Update existing record in-place instead of creating new one
        $existingDailyWork->update([
            'date' => $importedDailyWork[0], // Always use date from import file
            'number' => $importedDailyWork[1],
            'type' => $importedDailyWork[2],
            'description' => $importedDailyWork[3],
            'location' => $importedDailyWork[4],
            'side' => $importedDailyWork[5] ?? null,
            'qty_layer' => $importedDailyWork[6] ?? null,
            'planned_time' => $importedDailyWork[7] ?? null,
            'incharge' => $inChargeId,
            'resubmission_count' => $resubmissionCount,
            'resubmission_date' => $resubmissionDate,
        ]);
    }

    /**
     * Create new daily work
     */
    private function createNewDailyWork(array $importedDailyWork, int $inChargeId): void
    {
        DailyWork::create([
            'date' => $importedDailyWork[0],
            'number' => $importedDailyWork[1],
            'status' => DailyWork::STATUS_NEW,
            'type' => $importedDailyWork[2],
            'description' => $importedDailyWork[3],
            'location' => $importedDailyWork[4],
            'side' => $importedDailyWork[5] ?? null,
            'qty_layer' => $importedDailyWork[6] ?? null,
            'planned_time' => $importedDailyWork[7] ?? null,
            'incharge' => $inChargeId,
            'assigned' => null,
        ]);
    }

    /**
     * Get resubmission date
     */
    private function getResubmissionDate(DailyWork $existingDailyWork, int $resubmissionCount): string
    {
        if ($resubmissionCount === 1) {
            return $existingDailyWork->resubmission_date ?? $this->getOrdinalNumber($resubmissionCount).' Resubmission on '.Carbon::now()->format('jS F Y');
        }

        return $this->getOrdinalNumber($resubmissionCount).' Resubmission on '.Carbon::now()->format('jS F Y');
    }

    /**
     * Get ordinal number (1st, 2nd, 3rd, etc.)
     */
    private function getOrdinalNumber(int $number): string
    {
        if (! in_array(($number % 100), [11, 12, 13])) {
            switch ($number % 10) {
                case 1: return $number.'st';
                case 2: return $number.'nd';
                case 3: return $number.'rd';
            }
        }

        return $number.'th';
    }

    /**
     * Create daily summaries (immutable - use create instead of updateOrCreate)
     */
    private function createDailySummaries(array $inChargeSummary, string $date): void
    {
        foreach ($inChargeSummary as $inChargeId => $summary) {
            DailyWorkSummary::create([
                'date' => $date,
                'incharge' => $inChargeId,
                'totalDailyWorks' => $summary['totalDailyWorks'],
                'resubmissions' => $summary['resubmissions'],
                'embankment' => $summary['embankment'],
                'structure' => $summary['structure'],
                'pavement' => $summary['pavement'],
            ]);
        }
    }

    /**
     * Download Excel template for daily works import
     */
    public function downloadTemplate()
    {
        // Create sample data for the template
        $templateData = [
            ['Date', 'RFI Number', 'Work Type', 'Description', 'Location/Chainage', 'Road Side', 'Layer/Quantity', 'Time'],
            ['2025-11-26', 'S2025-0527-10207', 'Structure', 'Retaining wall module: RE wall Block Installation Check', 'K38+060-K38+110', 'TR-R', '2:30 PM', '2:30 PM'],
            ['2025-11-26', 'DSW-060', 'Structure', 'Dismantling of Shoulder Wall and Cantilever retaining wall: RE-wall Dismantling Work Check', 'K24+395-K24+418', 'TR-L', '11:00 AM', '11:00 AM'],
            ['2025-11-26', 'E2025-1126-23676', 'Embankment', 'Embankment Stacking on site: Roadway Excavation in Suitable Soil (Re-Work) Before Level Check', 'K24+395-K24+418', 'TR-L', '1.4m1', '5:00 PM'],
            ['2025-11-26', 'E2025-1119-23562', 'Embankment', 'Roadway excavation in suitable soil including stocking on site: Roadway Excavation in Suitable Soil After Level', 'SCK0+220-SCK0+345.060', 'SR-L', '', '3:00 PM'],
            ['2025-11-26', 'E2025-1126-23677', 'Embankment', 'Embankment Fill from the source approved by Engineer: Embankment Sand Filling Level Check & Compaction Test (RE Wall Section)', 'SCK0+440-SCK0+450', 'SR-L', '17th', '10:00 AM'],
        ];

        // Create a temporary file
        $filename = 'daily_works_import_template_'.date('Y-m-d_H-i-s').'.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);

        // Ensure temp directory exists
        if (! file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        // Create Excel file with template data
        Excel::store(new class($templateData) implements \Maatwebsite\Excel\Concerns\FromArray
        {
            private $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function array(): array
            {
                return $this->data;
            }
        }, 'temp/'.$filename);

        // Return download response
        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
