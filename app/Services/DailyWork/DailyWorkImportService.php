<?php

namespace App\Services\DailyWork;

use App\Imports\DailyWorkImport;
use App\Models\DailyWork;
use App\Models\DailyWorkImportBatch;
use App\Models\DailyWorkSummary;
use App\Models\User;
use App\Services\Access\AccessAudit;
use App\Services\Access\DepartmentScope;
use App\Services\DailyWork\History\DailyWorkChangeContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;

class DailyWorkImportService
{
    private DailyWorkValidationService $validationService;

    public function __construct(DailyWorkValidationService $validationService, private readonly DailyWorkImportAnalyzer $analyzer)
    {
        $this->validationService = $validationService;
    }

    /**
     * Preview an Excel/CSV import without writing any work. The file is stored under a token so the commit re-uses it, a
     * batch row records it (its hash: the same file is recognised if it comes again), and every row is analysed: valid,
     * warning or error, with the reasons, and what would happen to it (create, resubmit, skip, reject).
     *
     * @return array<string, mixed>
     */
    public function previewImport(Request $request): array
    {
        $this->validationService->validateImportFile($request);

        $token = (string) Str::uuid();
        $extension = $request->file('file')->getClientOriginalExtension() ?: 'xlsx';
        $relativePath = 'temp/imports/'.$token.'.'.$extension;
        $request->file('file')->storeAs('temp/imports', $token.'.'.$extension);
        $absolutePath = Storage::path($relativePath);

        $analysis = $this->analyzer->analyze(Excel::toArray(new DailyWorkImport, $absolutePath));
        $sha = hash_file('sha256', $absolutePath);
        $previous = DailyWorkImportBatch::query()->where('file_sha256', $sha)->where('status', DailyWorkImportBatch::COMMITTED)->orderByDesc('id')->first();

        $batch = DailyWorkImportBatch::query()->create([
            'token' => $token, 'filename' => mb_substr((string) $request->file('file')->getClientOriginalName(), 0, 255), 'file_sha256' => $sha,
            'status' => DailyWorkImportBatch::PREVIEWED, 'sheets' => count($analysis['sheets']), 'rows_total' => $analysis['counts']['rows'],
            'rows_valid' => $analysis['counts']['valid'], 'rows_warning' => $analysis['counts']['warning'], 'rows_error' => $analysis['counts']['error'],
            'created_by' => $request->user()?->employee_id,
        ]);

        return [
            'token' => $token,
            'batch' => ['id' => $batch->id, 'filename' => $batch->filename],
            'already_imported' => $previous === null ? null : ['batch_id' => $previous->id, 'committed_at' => $previous->committed_at?->toIso8601String(), 'rows_created' => $previous->rows_created, 'rows_resubmitted' => $previous->rows_resubmitted],
            'incharges' => $this->getAllInchargesList(),
            'analysis' => $analysis,
            'sheets' => $this->legacySheets($analysis),
        ];
    }

    /**
     * Commit. Valid and warning rows are written; error rows are rejected and kept in the batch for a downloadable report.
     * With errors present the caller must say it accepts a partial import (allow_partial), otherwise nothing is written:
     * this keeps the old all-or-nothing contract for callers that do not know about row-level results.
     *
     * @return array{results: array<int, array<string, mixed>>, batch: DailyWorkImportBatch, rejected: array<int, array<string, mixed>>}
     */
    public function commit(Request $request): array
    {
        $token = $request->input('token');
        $overrides = $this->parseOverrides($request->input('incharge_overrides'));
        $allowPartial = $request->boolean('allow_partial');

        if ($token) {
            $absolutePath = $this->resolveTokenPath((string) $token);
            if (! $absolutePath) {
                throw ValidationException::withMessages(['token' => 'Import session expired or file not found. Please upload the file again.']);
            }
        } else {
            $this->validationService->validateImportFile($request);
            $absolutePath = Storage::path($request->file('file')->store('temp'));
        }

        // The analysis is re-run on the stored file: what is written is what the file says, never what the browser sends back.
        $analysis = $this->analyzer->analyze(Excel::toArray(new DailyWorkImport, $absolutePath));
        $errors = $analysis['counts']['error'];
        if ($errors > 0 && ! $allowPartial) {
            throw ValidationException::withMessages(['file' => "{$errors} rows have errors. Fix them, or confirm the import of the valid rows only."]);
        }
        $writable = $analysis['counts']['create'] + $analysis['counts']['resubmit'];
        if ($writable === 0) {
            throw ValidationException::withMessages(['file' => $analysis['counts']['rows'] === 0 ? 'The file has no rows.' : 'There is nothing to import: every row is rejected or already recorded.']);
        }

        $batch = ($token ? DailyWorkImportBatch::query()->where('token', $token)->first() : null) ?? DailyWorkImportBatch::query()->create([
            'token' => (string) Str::uuid(), 'filename' => mb_substr((string) ($request->file('file')?->getClientOriginalName() ?? basename($absolutePath)), 0, 255),
            'file_sha256' => hash_file('sha256', $absolutePath), 'status' => DailyWorkImportBatch::PREVIEWED, 'created_by' => $request->user()?->employee_id,
        ]);
        if ($batch->status === DailyWorkImportBatch::COMMITTED) {
            throw ValidationException::withMessages(['token' => 'This preview was already imported (batch #'.$batch->id.').']);
        }

        $report = DB::transaction(fn (): array => DailyWorkChangeContext::with('import', $batch->id, fn (): array => $this->write($analysis, $overrides, $batch), 'Excel import: '.$batch->filename));

        $batch->forceFill([
            'status' => DailyWorkImportBatch::COMMITTED, 'committed_at' => now(), 'sheets' => count($analysis['sheets']), 'rows_total' => $analysis['counts']['rows'],
            'rows_valid' => $analysis['counts']['valid'], 'rows_warning' => $analysis['counts']['warning'], 'rows_error' => $errors,
            'rows_created' => $report['created'], 'rows_resubmitted' => $report['resubmitted'], 'rows_skipped' => $analysis['counts']['skip'],
            'summary' => ['rejected' => array_slice($report['rejected'], 0, 2000), 'summary_changes' => $report['summary_changes'], 'dates' => $report['dates']],
        ])->save();
        app(AccessAudit::class)->record('daily-works.import.committed', DailyWorkImportBatch::class, $batch->id, null, [
            'file' => $batch->filename, 'created' => $report['created'], 'resubmitted' => $report['resubmitted'], 'skipped' => $analysis['counts']['skip'], 'rejected' => $errors,
        ]);
        if ($token) {
            $this->cleanupTokenFile((string) $token);
        }

        return ['results' => $report['results'], 'batch' => $batch->fresh(), 'rejected' => $report['rejected']];
    }

    /** The legacy entry point: the per-sheet results. */
    public function processImport(Request $request): array
    {
        return $this->commit($request)['results'];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, string>  $overrides  RFI number => employee id chosen in the preview for rows with no in-charge
     * @return array<string, mixed>
     */
    private function write(array $analysis, array $overrides, DailyWorkImportBatch $batch): array
    {
        $created = 0;
        $resubmitted = 0;
        $rejected = [];
        $results = [];
        $summaryChanges = [];
        $dates = [];

        foreach ($analysis['sheets'] as $sheet) {
            $perIncharge = [];
            foreach ($sheet['rows'] as $row) {
                if ($row['action'] === 'reject') {
                    $rejected[] = $this->rejectedRow($row);

                    continue;
                }
                if ($row['action'] === 'skip') {
                    continue;
                }
                $inchargeId = $overrides[$row['rfi_number']] ?? ($row['incharge']['id'] ?? null);
                if (isset($overrides[$row['rfi_number']]) && $this->outsideScope((string) $inchargeId)) {
                    $row['issues'][] = ['level' => 'error', 'field' => 'incharge', 'message' => 'The chosen in-charge is outside your scope.'];
                    $rejected[] = $this->rejectedRow($row);

                    continue;
                }
                if ($inchargeId === null || ! User::query()->whereKey($inchargeId)->exists()) {
                    $row['issues'][] = ['level' => 'error', 'field' => 'incharge', 'message' => 'No in-charge could be resolved for this row.'];
                    $rejected[] = $this->rejectedRow($row);

                    continue;
                }
                $inchargeId = (string) $inchargeId;
                $perIncharge[$inchargeId] ??= ['totalDailyWorks' => 0, 'resubmissions' => 0, 'embankment' => 0, 'structure' => 0, 'pavement' => 0];
                $perIncharge[$inchargeId]['totalDailyWorks']++;
                $this->updateTypeCounter($perIncharge[$inchargeId], (string) $row['type']);

                if ($row['action'] === 'resubmit') {
                    $work = DailyWork::query()->where('number', $row['rfi_number'])->first();
                    if ($work === null) { // a work created earlier in this same file
                        $row['action'] = 'create';
                    } else {
                        $this->resubmit($work, $row, $inchargeId);
                        $perIncharge[$inchargeId]['resubmissions']++;
                        $resubmitted++;

                        continue;
                    }
                }
                $this->create($row, $inchargeId, $batch);
                $created++;
            }
            $summaryChanges = array_merge($summaryChanges, $this->summaries($perIncharge, (string) $sheet['date']));
            $dates[] = $sheet['date'];
            $results[] = ['sheet' => $sheet['sheet'], 'date' => $sheet['date'], 'summaries' => $perIncharge, 'processed_count' => count($sheet['rows'])];
        }

        return compact('created', 'resubmitted', 'rejected', 'results') + ['summary_changes' => $summaryChanges, 'dates' => array_values(array_unique($dates))];
    }

    /** @param array<string, mixed> $row */
    private function create(array $row, string $inchargeId, DailyWorkImportBatch $batch): void
    {
        $work = new DailyWork([
            'date' => $row['date'], 'number' => $row['rfi_number'], 'status' => DailyWork::STATUS_NEW, 'type' => $row['type'], 'description' => $row['description'],
            'location' => $row['location'], 'side' => $row['side'], 'qty_layer' => $row['qty_layer'], 'planned_time' => $row['planned_time'], 'incharge' => $inchargeId, 'assigned' => null, // nobody assigned yet: an import never names an inspector
        ]);
        $work->setAttribute('import_batch_id', $batch->id); // provenance: the batch that created it
        $work->save();
    }

    /**
     * A resubmission: the SAME work, its next numbered revision. The date, place and description are the new submission's
     * (the revision keeps the old ones); a completed work keeps its in-charge for good.
     *
     * @param  array<string, mixed>  $row
     */
    private function resubmit(DailyWork $work, array $row, string $inchargeId): void
    {
        $count = (int) $work->resubmission_count + 1;
        $attrs = [
            'date' => $row['date'], 'type' => $row['type'], 'description' => $row['description'], 'location' => $row['location'],
            'side' => $row['side'], 'qty_layer' => $row['qty_layer'], 'planned_time' => $row['planned_time'],
            'resubmission_count' => $count, 'resubmission_date' => $this->getResubmissionDate($work, $count),
        ];
        if (DailyWorkWorkflow::normalise($work->status) !== DailyWork::STATUS_COMPLETED) {
            $attrs['incharge'] = $inchargeId;
        }
        DailyWorkWorkflow::unrestricted(fn () => $work->update($attrs));
    }

    /**
     * Daily summaries per in-charge and date: new rows, or added to the ones an earlier (partial) import left. The changes are
     * returned so a reversal can take exactly them back.
     *
     * @param  array<string, array<string, int>>  $perIncharge
     * @return array<int, array<string, mixed>>
     */
    private function summaries(array $perIncharge, string $date): array
    {
        $changes = [];
        foreach ($perIncharge as $inchargeId => $d) {
            $row = DailyWorkSummary::query()->whereDate('date', $date)->where('incharge', $inchargeId)->first();
            if ($row === null) {
                DailyWorkSummary::query()->create(['date' => $date, 'incharge' => $inchargeId] + $d);
            } else {
                foreach ($d as $k => $v) {
                    $row->{$k} = (int) $row->{$k} + $v;
                }
                $row->save();
            }
            $changes[] = ['date' => $date, 'incharge' => $inchargeId, 'delta' => $d];
        }

        return $changes;
    }

    /** @param array<string, mixed> $row */
    private function rejectedRow(array $row): array
    {
        return [
            'sheet' => $row['sheet'], 'row' => $row['row'], 'date' => $row['date'], 'rfi_number' => $row['rfi_number'], 'type' => $row['type'], 'location' => $row['location'],
            'description' => mb_strimwidth((string) $row['description'], 0, 160, '…'),
            'reasons' => array_values(array_map(fn (array $i): string => $i['message'], array_filter($row['issues'], fn (array $i): bool => $i['level'] === 'error'))),
        ];
    }

    /**
     * The grouping the first preview screen used (works by detected in-charge, unassigned, skipped), built from the analysis.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    private function legacySheets(array $analysis): array
    {
        $sheets = [];
        foreach ($analysis['sheets'] as $sheet) {
            $by = [];
            $unassigned = [];
            $skipped = [];
            $new = $res = $skip = 0;
            foreach ($sheet['rows'] as $row) {
                $entry = ['rfi_number' => $row['rfi_number'], 'location' => $row['location'], 'type' => $row['type'], 'description' => $row['description'],
                    'status' => ['create' => 'new', 'resubmit' => 'resubmission', 'skip' => 'skipped', 'reject' => 'new'][$row['action']], 'auto_incharge' => $row['incharge']['id'] ?? null];
                if ($row['action'] === 'skip') {
                    $skip++;
                    $skipped[] = $entry;
                } elseif ($row['action'] === 'resubmit') {
                    $res++;
                } elseif ($row['action'] === 'create') {
                    $new++;
                }
                if ($row['action'] === 'skip') {
                    continue;
                }
                if ($entry['auto_incharge'] !== null) {
                    $by[$entry['auto_incharge']][] = $entry;
                } else {
                    $unassigned[] = $entry;
                }
            }
            $sheets[] = ['sheet' => $sheet['sheet'], 'date' => $sheet['date'], 'stats' => ['total' => count($sheet['rows']), 'new' => $new, 'resubmissions' => $res, 'skipped' => $skip],
                'by_incharge' => $by, 'unassigned' => $unassigned, 'skipped_list' => $skipped];
        }

        return $sheets;
    }

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

    private function getResubmissionDate(DailyWork $existingDailyWork, int $resubmissionCount): string
    {
        if ($resubmissionCount === 1) {
            return $existingDailyWork->resubmission_date ?? $this->getOrdinalNumber($resubmissionCount).' Resubmission on '.Carbon::now()->format('jS F Y');
        }

        return $this->getOrdinalNumber($resubmissionCount).' Resubmission on '.Carbon::now()->format('jS F Y');
    }

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

    /** Normalize a date value from an import file into Y-m-d format (kept for callers of the old service). */
    private function normalizeDate($value): ?string
    {
        return $this->analyzer->date($value);
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
            $inchargeId = trim((string) $inchargeId);
            if ($rfi !== '' && $inchargeId !== '') {
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
    private function outsideScope(string $employeeId): bool
    {
        $visible = auth()->user() ? app(DepartmentScope::class)->visibleEmployeeIds(auth()->user()) : null;

        return $visible !== null && ! in_array($employeeId, $visible, true);
    }

    private function getAllInchargesList(): array
    {
        $scope = app(DepartmentScope::class);
        $query = auth()->user() ? $scope->applyToUsers(User::query(), auth()->user()) : User::query();

        return $query->with('designation')
            ->whereHas('designation', fn ($q) => $q->where('title', 'Supervision Engineer'))
            ->get()
            ->map(fn ($u) => [
                'id' => $u->employee_id,
                'name' => $u->name ?? $u->user_name ?? 'Unknown',
                'designation' => $u->designation?->title ?? 'Supervision Engineer',
            ])
            ->values()
            ->toArray();
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
        Excel::store(new class($templateData) implements FromArray
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
