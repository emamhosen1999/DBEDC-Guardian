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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * Preview Excel/CSV import without processing.
     * Stores the uploaded file with a token so confirmation can re-use it
     * without re-uploading. Returns detailed work data grouped by incharge.
     */
    public function previewImport(Request $request): array
    {
        $this->validationService->validateImportFile($request);

        // Persist the uploaded file under a unique token for the confirm step
        $token = (string) Str::uuid();
        $extension = $request->file('file')->getClientOriginalExtension() ?: 'xlsx';
        $relativePath = 'temp/imports/'.$token.'.'.$extension;
        $request->file('file')->storeAs('temp/imports', $token.'.'.$extension);

        $absolutePath = Storage::path($relativePath);
        $importedSheets = Excel::toArray(new DailyWorkImport, $absolutePath);

        // Validate all sheets
        foreach ($importedSheets as $sheetIndex => $importedDailyWorks) {
            if (empty($importedDailyWorks)) {
                continue;
            }

            $this->validationService->validateImportedData($importedDailyWorks, $sheetIndex);
        }

        // Check for duplicate dates so user sees the error at preview stage
        $this->validateDuplicateDates($importedSheets);

        // Build detailed grouped summary
        $sheets = [];
        foreach ($importedSheets as $sheetIndex => $importedDailyWorks) {
            if (empty($importedDailyWorks)) {
                continue;
            }

            $sheets[] = $this->buildDetailedSheetPreview($importedDailyWorks, $sheetIndex);
        }

        return [
            'token' => $token,
            'incharges' => $this->getAllInchargesList(),
            'sheets' => $sheets,
        ];
    }

    /**
     * Process Excel/CSV import.
     * Supports either a fresh file upload OR a previously-uploaded file
     * referenced by a token (from preview). Optional incharge_overrides
     * map RFI number -> incharge user id to override jurisdiction matching.
     */
    public function processImport(Request $request): array
    {
        $token = $request->input('token');
        $overrides = $this->parseOverrides($request->input('incharge_overrides'));

        if ($token) {
            $absolutePath = $this->resolveTokenPath($token);
            if (! $absolutePath) {
                throw ValidationException::withMessages([
                    'token' => 'Import session expired or file not found. Please upload the file again.',
                ]);
            }
        } else {
            $this->validationService->validateImportFile($request);
            $relativePath = $request->file('file')->store('temp');
            $absolutePath = Storage::path($relativePath);
        }

        $importedSheets = Excel::toArray(new DailyWorkImport, $absolutePath);

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
        $results = DB::transaction(function () use ($importedSheets, $overrides) {
            $results = [];
            foreach ($importedSheets as $sheetIndex => $importedDailyWorks) {
                if (empty($importedDailyWorks)) {
                    continue;
                }

                $result = $this->processSheet($importedDailyWorks, $sheetIndex, $overrides);
                $results[] = $result;
            }

            return $results;
        });

        // Cleanup token file after successful import
        if ($token) {
            $this->cleanupTokenFile($token);
        }

        return $results;
    }

    /**
     * Parse incharge_overrides input into a clean array<string, int>.
     */
    private function parseOverrides($overrides): array
    {
        if (empty($overrides)) {
            return [];
        }

        if (is_string($overrides)) {
            $decoded = json_decode($overrides, true);
            $overrides = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($overrides)) {
            return [];
        }

        $clean = [];
        foreach ($overrides as $rfi => $inchargeId) {
            $rfi = trim((string) $rfi);
            $inchargeId = (int) $inchargeId;
            if ($rfi !== '' && $inchargeId > 0) {
                $clean[$rfi] = $inchargeId;
            }
        }

        return $clean;
    }

    /**
     * Resolve a token to an absolute file path on local disk.
     */
    private function resolveTokenPath(string $token): ?string
    {
        // Sanitize: only allow uuid-like tokens
        if (! preg_match('/^[a-f0-9\-]{8,}$/i', $token)) {
            return null;
        }

        foreach (['xlsx', 'xls', 'csv'] as $ext) {
            $relative = 'temp/imports/'.$token.'.'.$ext;
            if (Storage::exists($relative)) {
                return Storage::path($relative);
            }
        }

        return null;
    }

    /**
     * Delete the temporary file associated with the token.
     */
    private function cleanupTokenFile(string $token): void
    {
        if (! preg_match('/^[a-f0-9\-]{8,}$/i', $token)) {
            return;
        }

        foreach (['xlsx', 'xls', 'csv'] as $ext) {
            $relative = 'temp/imports/'.$token.'.'.$ext;
            if (Storage::exists($relative)) {
                Storage::delete($relative);
            }
        }
    }

    /**
     * Get list of all Supervision Engineers as candidate incharges.
     */
    private function getAllInchargesList(): array
    {
        return User::with('designation')
            ->whereHas('designation', fn ($q) => $q->where('title', 'Supervision Engineer'))
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name ?? $u->user_name ?? 'Unknown',
                'designation' => $u->designation?->title ?? 'Supervision Engineer',
            ])
            ->values()
            ->toArray();
    }

    /**
     * Build detailed sheet preview with works grouped by auto-detected incharge.
     * Returns structure consumed by the kanban-style preview UI.
     */
    private function buildDetailedSheetPreview(array $importedDailyWorks, int $sheetIndex): array
    {
        $date = $this->normalizeDate($importedDailyWorks[0][0]) ?? $importedDailyWorks[0][0];

        $newWorks = 0;
        $resubmissions = 0;
        $skipped = 0;

        // Group works by detected incharge id; keep skipped + unassigned separate.
        $byIncharge = [];   // [inchargeId => [work, ...]]
        $unassigned = [];   // jurisdiction not matched
        $skippedList = [];  // completed pass/fail (read-only)

        foreach ($importedDailyWorks as $importedDailyWork) {
            $rfi = (string) ($importedDailyWork[1] ?? '');
            $location = (string) ($importedDailyWork[4] ?? '');
            $type = (string) ($importedDailyWork[2] ?? '');
            $description = (string) ($importedDailyWork[3] ?? '');

            $existingDailyWork = DailyWork::where('number', $rfi)->first();
            $isSkipped = $existingDailyWork
                && $existingDailyWork->status === DailyWork::STATUS_COMPLETED
                && in_array($existingDailyWork->inspection_result, ['pass', 'fail']);

            $status = $isSkipped ? 'skipped' : ($existingDailyWork ? 'resubmission' : 'new');

            if ($status === 'new') {
                $newWorks++;
            } elseif ($status === 'resubmission') {
                $resubmissions++;
            } else {
                $skipped++;
            }

            $jurisdiction = $this->findJurisdictionForLocation($location);
            $autoInchargeId = $jurisdiction?->incharge;

            $workEntry = [
                'rfi_number' => $rfi,
                'location' => $location,
                'type' => $type,
                'description' => $description,
                'status' => $status, // new | resubmission | skipped
                'auto_incharge' => $autoInchargeId,
            ];

            if ($isSkipped) {
                // Carry the existing/auto incharge for display only
                $workEntry['auto_incharge'] = $autoInchargeId ?? $existingDailyWork->incharge;
                $skippedList[] = $workEntry;
                continue;
            }

            if ($autoInchargeId) {
                $byIncharge[$autoInchargeId][] = $workEntry;
            } else {
                $unassigned[] = $workEntry;
            }
        }

        return [
            'sheet' => $sheetIndex + 1,
            'date' => $date,
            'stats' => [
                'total' => count($importedDailyWorks),
                'new' => $newWorks,
                'resubmissions' => $resubmissions,
                'skipped' => $skipped,
            ],
            'by_incharge' => $byIncharge, // map keyed by incharge id
            'unassigned' => $unassigned,
            'skipped_list' => $skippedList,
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
     * Process a single sheet of daily works.
     * $overrides maps RFI number -> incharge user id to bypass jurisdiction matching.
     */
    private function processSheet(array $importedDailyWorks, int $sheetIndex, array $overrides = []): array
    {
        $date = $this->normalizeDate($importedDailyWorks[0][0]) ?? $importedDailyWorks[0][0];
        $inChargeSummary = [];

        foreach ($importedDailyWorks as $importedDailyWork) {
            $result = $this->processDailyWorkRow($importedDailyWork, $date, $inChargeSummary, $overrides);

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
     * Process a single daily work row. Honors override map first, falls back
     * to jurisdiction matching by location chainage.
     */
    private function processDailyWorkRow(array $importedDailyWork, string $date, array &$inChargeSummary, array $overrides = []): array
    {
        $rfi = (string) ($importedDailyWork[1] ?? '');
        $inCharge = null;

        // 1. Manual override from preview UI takes precedence
        if ($rfi !== '' && isset($overrides[$rfi])) {
            $inCharge = (int) $overrides[$rfi];
        }

        // 2. Fall back to jurisdiction matching
        if (! $inCharge) {
            $jurisdiction = $this->findJurisdictionForLocation($importedDailyWork[4]);

            if (! $jurisdiction) {
                Log::warning('No jurisdiction found for location: '.$importedDailyWork[4]);

                return ['processed' => false, 'summary' => $inChargeSummary];
            }

            $inCharge = (int) $jurisdiction->incharge;
        }

        // Validate the resolved incharge actually exists
        $inChargeUser = User::find($inCharge);
        if (! $inChargeUser) {
            Log::warning('Resolved incharge user not found: '.$inCharge.' for RFI '.$rfi);
            return ['processed' => false, 'summary' => $inChargeSummary];
        }

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
