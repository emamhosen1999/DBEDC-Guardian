<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailyWork\UpdateDailyWorkStatusRequest;
use App\Http\Requests\DailyWork\UpdateInspectionDetailsRequest;
use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\Report;
use App\Models\User;
use App\Services\DailyWork\DailyWorkCrudService;
use App\Services\DailyWork\DailyWorkFileService;
use App\Services\DailyWork\DailyWorkImportService;
use App\Services\DailyWork\DailyWorkPaginationService;
use App\Services\Project\DailyWorkService;
use App\Services\Project\DailyWorkExportService;
use App\Traits\DailyWorkFilterable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DailyWorkController extends Controller
{
    use DailyWorkFilterable;

    private DailyWorkPaginationService $paginationService;

    private DailyWorkImportService $importService;

    private DailyWorkCrudService $crudService;

    private DailyWorkFileService $fileService;

    private DailyWorkService $dailyWorkService;

    private DailyWorkExportService $dailyWorkExportService;

    public function __construct(
        DailyWorkPaginationService $paginationService,
        DailyWorkImportService $importService,
        DailyWorkCrudService $crudService,
        DailyWorkFileService $fileService,
        DailyWorkService $dailyWorkService,
        DailyWorkExportService $dailyWorkExportService
    ) {
        $this->paginationService = $paginationService;
        $this->importService = $importService;
        $this->crudService = $crudService;
        $this->fileService = $fileService;
        $this->dailyWorkService = $dailyWorkService;
        $this->dailyWorkExportService = $dailyWorkExportService;
    }

    public function index()
    {
        $user = User::with('designation')->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;

        $allData = $userDesignationTitle === 'Supervision Engineer'
            ? [
                'allInCharges' => [],
                'juniors' => User::where('report_to', $user->id)->get(),

            ]
            : (in_array($userDesignationTitle, ['Quality Control Inspector', 'Asst. Quality Control Inspector'])
                ? []
                : ($user->hasRole('Super Administrator') || $user->hasRole('Administrator')
                    ? [
                        'allInCharges' => User::whereHas('designation', function ($q) {
                            $q->where('title', 'Supervision Engineer');
                        })->get(),
                        'juniors' => [],
                    ]
                    : []
                )
            );
        $reports = Report::all();
        $reports_with_daily_works = Report::with('daily_works')->has('daily_works')->get();
        $users = User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with(['roles', 'designation', 'media'])->get();

        // Loop through each user and add role and designation_title fields
        $users->transform(function ($user) {
            $user->role = $user->roles->first()?->name;
            $user->designation_title = $user->designation?->title;

            return $user;
        });

        $overallStartDate = DailyWork::min('date'); // Earliest date from all records
        $overallEndDate = DailyWork::max('date'); // Latest date from all records

        return Inertia::render('Project/DailyWorks', [
            'allData' => $allData,
            'jurisdictions' => Jurisdiction::all(),
            'users' => $users,
            'title' => 'Daily Works',
            'reports' => $reports,
            'reports_with_daily_works' => $reports_with_daily_works,
            'overallStartDate' => $overallStartDate,
            'overallEndDate' => $overallEndDate,
        ]);
    }

    public function unified()
    {
        $user = User::with(['designation', 'roles'])->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;

        // Data for Works tab (from DailyWorkController::index)
        $allData = $userDesignationTitle === 'Supervision Engineer'
            ? [
                'allInCharges' => [],
                'juniors' => User::where('report_to', $user->id)->get(),
            ]
            : (in_array($userDesignationTitle, ['Quality Control Inspector', 'Asst. Quality Control Inspector'])
                ? []
                : ($user->hasRole('Super Administrator') || $user->hasRole('Administrator')
                    ? [
                        'allInCharges' => User::whereHas('designation', function ($q) {
                            $q->where('title', 'Supervision Engineer');
                        })->get(),
                        'juniors' => [],
                    ]
                    : []
                )
            );
        
        $reports = Report::all();
        $reports_with_daily_works = Report::with('daily_works')->has('daily_works')->get();
        $users = User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with(['roles', 'designation', 'media'])->get();

        // Loop through each user and add role and designation_title fields
        $users->transform(function ($user) {
            $user->role = $user->roles->first()?->name;
            $user->designation_title = $user->designation?->title;

            return $user;
        });

        $overallStartDate = DailyWork::min('date') ?? date('Y-m-d', strtotime('-30 days'));
        $overallEndDate = DailyWork::max('date') ?? date('Y-m-d');

        // Data for Summary tab (from DailyWorkSummaryController)
        $isAdmin = $user->hasRole('Super Administrator') || $user->hasRole('Administrator');
        $inCharges = $isAdmin
            ? User::whereHas('designation', function ($q) {
                $q->where('title', 'Supervision Engineer');
            })->get()
            : collect([]);

        // Get summary data for initial load
        $summaryQuery = DailyWork::with(['inchargeUser', 'assignedUser']);
        
        if (!$isAdmin && in_array('Employee', $user->roles->pluck('name')->toArray())) {
            $hasJurisdiction = \App\Models\Jurisdiction::where('incharge', $user->id)->exists();
            
            if ($hasJurisdiction) {
                $summaryQuery->where('incharge', $user->id);
            } elseif ($user->report_to) {
                $summaryQuery->where('incharge', $user->report_to);
            }
        }

        // Group by date and calculate summary stats
        $summary = $summaryQuery
            ->selectRaw('date, 
                COUNT(*) as totalDailyWorks,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status IN ("new", "in-progress", "pending", "resubmission") THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN inspection_result = "pass" THEN 1 ELSE 0 END) as passedInspections,
                SUM(CASE WHEN inspection_result = "fail" THEN 1 ELSE 0 END) as failedInspections')
            ->groupBy('date')
            ->orderByDesc('date')
            ->get();

        return Inertia::render('Project/DailyWorksUnified', [
            'allData' => $allData,
            'jurisdictions' => Jurisdiction::all(),
            'users' => $users,
            'title' => 'Daily Works Unified',
            'reports' => $reports,
            'reports_with_daily_works' => $reports_with_daily_works,
            'overallStartDate' => $overallStartDate,
            'overallEndDate' => $overallEndDate,
            'summary' => $summary,
            'inCharges' => $inCharges,
        ]);
    }

    public function paginate(Request $request)
    {
        try {
            $paginatedDailyWorks = $this->paginationService->getPaginatedDailyWorks($request);

            return response()->json($paginatedDailyWorks);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function all(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $result = $this->paginationService->getAllDailyWorks($request);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function previewImport(Request $request)
    {
        try {
            $summary = $this->importService->previewImport($request);

            return response()->json([
                'message' => 'Preview generated successfully',
                'summary' => $summary,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function import(Request $request)
    {
        try {
            $results = $this->importService->processImport($request);

            return response()->json([
                'message' => 'Import completed successfully',
                'results' => $results,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $result = $this->crudService->update($request);

            return response()->json($result);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function delete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $result = $this->crudService->delete($request);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function export(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $user = User::with(['designation', 'roles'])->find(Auth::id());

            // Prepare export data based on selected columns
            $selectedColumns = $request->get('columns', [
                'date', 'number', 'type', 'description', 'location', 'status',
                'incharge', 'assigned', 'completion_time', 'rfi_submission_date',
            ]);

            $exportData = $this->dailyWorkExportService->prepareExportData(
                $user,
                $request->all(),
                $selectedColumns
            );

            return response()->json([
                'data' => $exportData,
                'filename' => 'daily_works_'.now()->format('Y_m_d_H_i_s'),
                'total_records' => count($exportData),
                'message' => 'Export data prepared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Export failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Export only RFIs with active objections along with objection details.
     */
    public function exportObjectedRfis(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $user = User::with('designation')->find(Auth::id());

            $exportData = $this->dailyWorkExportService->prepareObjectedRfisExportData(
                $user,
                $request->all()
            );

            return response()->json([
                'data' => $exportData,
                'filename' => 'rfis_with_objections_'.now()->format('Y_m_d_H_i_s'),
                'total_records' => count($exportData),
                'message' => 'Export data prepared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Export failed: '.$e->getMessage()], 500);
        }
    }

    public function updateStatus(UpdateDailyWorkStatusRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            $dailyWork = DailyWork::findOrFail($request->id);

            $this->dailyWorkService->updateStatus(
                $dailyWork,
                $request->status,
                $request->input('inspection_result'),
                true // Web controller updates submission_time
            );

            return response()->json([
                'message' => 'Status updated successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateCompletionTime(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'id' => 'required|exists:daily_works,id',
                'completion_time' => 'required|date',
            ]);

            $dailyWork = DailyWork::findOrFail($request->id);

            $this->authorize('updateCompletionTime', $dailyWork);

            $this->dailyWorkService->updateCompletionTime($dailyWork, $request->completion_time);

            return response()->json([
                'message' => 'Completion time updated successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateSubmissionTime(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'id' => 'required|exists:daily_works,id',
                'rfi_submission_date' => 'required|date',
                // Override confirmation fields (required when objections exist)
                'override_confirmed' => 'sometimes|boolean',
                'override_reason' => 'nullable|required_if:override_confirmed,true|string|max:1000',
            ]);

            $dailyWork = DailyWork::findOrFail($request->id);

            $this->authorize('updateSubmissionTime', $dailyWork);

            // Check for active objections
            $activeObjectionsCount = $dailyWork->active_objections_count ?? $dailyWork->objections()
                ->whereIn('status', ['draft', 'submitted', 'under_review'])
                ->count();

            if ($activeObjectionsCount > 0) {
                // If user hasn't confirmed the override, require confirmation
                if (! $request->boolean('override_confirmed')) {
                    return response()->json([
                        'requires_confirmation' => true,
                        'active_objections_count' => $activeObjectionsCount,
                        'message' => "This RFI has {$activeObjectionsCount} active objection(s). Changing the submission date may affect approvals, records, or claims. Please confirm to proceed.",
                        'objections' => $dailyWork->activeObjections()
                            ->with('createdBy:id,name')
                            ->get(['id', 'title', 'category', 'status', 'created_by', 'created_at']),
                    ], 422);
                }

                // Validate override reason
                if (empty($request->override_reason)) {
                    return response()->json([
                        'error' => 'A reason is required when overriding an RFI with active objections.',
                    ], 422);
                }
            }

            $this->dailyWorkService->updateSubmissionTime(
                $dailyWork,
                $request->rfi_submission_date,
                auth()->id(),
                $request->override_reason
            );

            return response()->json([
                'message' => 'RFI submission date updated successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
                'override_logged' => $activeObjectionsCount > 0,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Bulk submit RFIs with objection warnings.
     */
    public function bulkSubmit(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|exists:daily_works,id',
                'rfi_submission_date' => 'required|date',
                'skip_objected' => 'sometimes|boolean',
                'override_objected' => 'sometimes|boolean',
                'override_reason' => 'nullable|required_if:override_objected,true|string|max:1000',
            ]);

            $result = $this->dailyWorkService->bulkSubmit(
                ids: $request->ids,
                submissionDate: $request->rfi_submission_date,
                userId: auth()->id(),
                skipObjected: $request->boolean('skip_objected'),
                overrideObjected: $request->boolean('override_objected'),
                overrideReason: $request->override_reason,
                authorizeCallback: fn ($work) => $this->authorize('updateSubmissionTime', $work)
            );

            if (!empty($result['requires_decision'])) {
                return response()->json([
                    'requires_decision' => true,
                    'total_count' => $result['total_count'],
                    'objected_count' => $result['objected_count'],
                    'clean_count' => $result['clean_count'],
                    'objected_works' => $result['objected_works'],
                    'message' => 'Some RFIs have active objections. Please choose to skip them or override with a reason.',
                ], 422);
            }

            return response()->json([
                'message' => $this->buildBulkSubmitMessage($result['submitted'], $result['skipped'], $result['failed']),
                'submitted' => $result['submitted'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'submitted_count' => count($result['submitted']),
                'skipped_count' => count($result['skipped']),
                'failed_count' => count($result['failed']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Build a human-readable message for bulk submit result.
     */
    private function buildBulkSubmitMessage(array $submitted, array $skipped, array $failed): string
    {
        $parts = [];

        if (count($submitted) > 0) {
            $parts[] = count($submitted).' RFI(s) submitted successfully';
        }
        if (count($skipped) > 0) {
            $parts[] = count($skipped).' RFI(s) skipped (have objections)';
        }
        if (count($failed) > 0) {
            $parts[] = count($failed).' RFI(s) failed';
        }

        return implode(', ', $parts).'.';
    }

    /**
     * Import RFI submission dates from Excel file.
     * Excel should have two columns: RFI Number and Submission Date.
     */
    public function bulkImportSubmit(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
                'skip_objected' => 'sometimes|boolean',
                'override_objected' => 'sometimes|boolean',
                'override_reason' => 'nullable|required_if:override_objected,true|string|max:1000',
            ]);

            $skipObjected = $request->boolean('skip_objected');
            $overrideObjected = $request->boolean('override_objected');
            $overrideReason = $request->override_reason;

            // Read the Excel file
            $file = $request->file('file');
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Detect column order - check if columns are swapped
            $rfiColIndex = 0;
            $dateColIndex = 1;
            $startRow = 0;

            if (count($rows) > 0) {
                $firstRow = $rows[0];

                // Check header row to determine column order
                $col0 = strtolower(trim($firstRow[0] ?? ''));
                $col1 = strtolower(trim($firstRow[1] ?? ''));

                // Check if first row is a header
                $isHeader = (stripos($col0, 'rfi') !== false || stripos($col0, 'number') !== false ||
                             stripos($col0, 'date') !== false || stripos($col0, 'submission') !== false ||
                             stripos($col1, 'rfi') !== false || stripos($col1, 'number') !== false ||
                             stripos($col1, 'date') !== false || stripos($col1, 'submission') !== false);

                if ($isHeader) {
                    $startRow = 1;

                    // Determine column order from headers
                    if (stripos($col0, 'date') !== false || stripos($col0, 'submission') !== false) {
                        // Date is in first column, swap
                        $rfiColIndex = 1;
                        $dateColIndex = 0;
                    }
                } else {
                    // Auto-detect from data: check if first column looks like a date
                    $firstVal = trim($firstRow[0] ?? '');
                    $secondVal = trim($firstRow[1] ?? '');

                    // Check if first column is numeric (Excel date) or looks like a date string
                    $firstIsDate = is_numeric($firstVal) ||
                        preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstVal) ||
                        preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $firstVal) ||
                        preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/', $firstVal);

                    // Check if second column looks like an RFI number (contains letters)
                    $secondIsRfi = ! empty($secondVal) && preg_match('/[A-Za-z]/', $secondVal);

                    if ($firstIsDate && $secondIsRfi) {
                        // Columns are swapped
                        $rfiColIndex = 1;
                        $dateColIndex = 0;
                    }
                }
            }

            $parsed = [];
            $notFound = [];
            $invalid = [];

            for ($i = $startRow; $i < count($rows); $i++) {
                $row = $rows[$i];
                $rfiNumber = trim($row[$rfiColIndex] ?? '');
                $dateValue = $row[$dateColIndex] ?? null;

                if (empty($rfiNumber)) {
                    continue;
                }

                // Parse the date using export service helper
                $submissionDate = $this->dailyWorkExportService->parseExcelDate($dateValue);

                if (empty($submissionDate)) {
                    $invalid[] = [
                        'row' => $i + 1,
                        'rfi_number' => $rfiNumber,
                        'error' => empty($dateValue) ? 'Missing submission date' : 'Invalid date format: '.$dateValue,
                    ];

                    continue;
                }

                // Find the daily work by RFI number
                // Priority: 1) RFI with status 'completed', 2) Latest dated RFI with same number
                $dailyWork = DailyWork::where('number', $rfiNumber)
                    ->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END") // Completed first
                    ->orderBy('created_at', 'desc') // Then latest
                    ->first();

                if (! $dailyWork) {
                    $notFound[] = [
                        'row' => $i + 1,
                        'rfi_number' => $rfiNumber,
                    ];

                    continue;
                }

                $parsed[] = [
                    'row' => $i + 1,
                    'daily_work' => $dailyWork,
                    'submission_date' => $submissionDate,
                ];
            }

            if (count($parsed) === 0) {
                return response()->json([
                    'error' => 'No valid RFIs found in the file',
                    'not_found' => $notFound,
                    'invalid' => $invalid,
                ], 422);
            }

            // Load objection counts for found works
            $ids = collect($parsed)->pluck('daily_work.id')->toArray();
            $dailyWorks = DailyWork::whereIn('id', $ids)
                ->withCount(['objections as active_objections_count' => function ($query) {
                    $query->whereIn('status', ['draft', 'submitted', 'under_review']);
                }])
                ->get()
                ->keyBy('id');

            // Separate works with and without objections
            $worksWithObjections = [];
            $worksWithoutObjections = [];

            foreach ($parsed as $item) {
                $work = $dailyWorks[$item['daily_work']->id] ?? null;
                if ($work && $work->active_objections_count > 0) {
                    $worksWithObjections[] = [
                        'row' => $item['row'],
                        'daily_work' => $work,
                        'submission_date' => $item['submission_date'],
                    ];
                } else {
                    $worksWithoutObjections[] = [
                        'row' => $item['row'],
                        'daily_work' => $work,
                        'submission_date' => $item['submission_date'],
                    ];
                }
            }

            // If there are works with objections and user hasn't decided what to do
            if (count($worksWithObjections) > 0 && ! $skipObjected && ! $overrideObjected) {
                return response()->json([
                    'requires_decision' => true,
                    'total_count' => count($parsed),
                    'objected_count' => count($worksWithObjections),
                    'clean_count' => count($worksWithoutObjections),
                    'not_found_count' => count($notFound),
                    'invalid_count' => count($invalid),
                    'objected_works' => collect($worksWithObjections)->map(fn ($item) => [
                        'row' => $item['row'],
                        'id' => $item['daily_work']->id,
                        'number' => $item['daily_work']->number,
                        'location' => $item['daily_work']->location,
                        'submission_date' => $item['submission_date'],
                        'active_objections_count' => $item['daily_work']->active_objections_count,
                    ])->values(),
                    'not_found' => $notFound,
                    'invalid' => $invalid,
                    'message' => 'Some RFIs have active objections. Please choose to skip them or override with a reason.',
                ], 422);
            }

            $submitted = [];
            $skipped = [];
            $failed = [];

            // Process works without objections
            foreach ($worksWithoutObjections as $item) {
                $work = $item['daily_work'];

                try {
                    $this->authorize('updateSubmissionTime', $work);
                    $this->dailyWorkService->updateSubmissionTime($work, $item['submission_date'], auth()->id(), null);
                    $submitted[] = [
                        'id' => $work->id,
                        'number' => $work->number,
                        'submission_date' => $item['submission_date'],
                        'dailyWork' => $work->fresh(['inchargeUser', 'assignedUser']),
                    ];
                } catch (\Exception $e) {
                    $failed[] = [
                        'row' => $item['row'],
                        'id' => $work->id,
                        'number' => $work->number,
                        'error' => 'Permission denied',
                    ];
                }
            }

            // Process works with objections based on user decision
            foreach ($worksWithObjections as $item) {
                $work = $item['daily_work'];

                if ($skipObjected) {
                    $skipped[] = [
                        'id' => $work->id,
                        'number' => $work->number,
                        'active_objections_count' => $work->active_objections_count,
                    ];

                    continue;
                }

                if ($overrideObjected) {
                    try {
                        $this->authorize('updateSubmissionTime', $work);
                        $this->dailyWorkService->updateSubmissionTime($work, $item['submission_date'], auth()->id(), $overrideReason.' (Excel import)');

                        $submitted[] = [
                            'id' => $work->id,
                            'number' => $work->number,
                            'submission_date' => $item['submission_date'],
                            'override_logged' => true,
                            'dailyWork' => $work->fresh(['inchargeUser', 'assignedUser']),
                        ];
                    } catch (\Exception $e) {
                        $failed[] = [
                            'row' => $item['row'],
                            'id' => $work->id,
                            'number' => $work->number,
                            'error' => 'Permission denied',
                        ];
                    }
                }
            }

            return response()->json([
                'message' => $this->buildBulkSubmitMessage($submitted, $skipped, $failed),
                'submitted' => $submitted,
                'skipped' => $skipped,
                'failed' => $failed,
                'not_found' => $notFound,
                'invalid' => $invalid,
                'submitted_count' => count($submitted),
                'skipped_count' => count($skipped),
                'failed_count' => count($failed),
                'not_found_count' => count($notFound),
                'invalid_count' => count($invalid),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Bulk Import Submit Error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => 'An error occurred: '.$e->getMessage()], 500);
        }
    }

    /**
     * Download template for bulk import submission.
     */
    public function downloadBulkImportTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $tempFile = $this->dailyWorkExportService->generateBulkImportTemplate();

        return response()->download($tempFile, 'rfi_submission_import_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function updateInspectionDetails(UpdateInspectionDetailsRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            $dailyWork = DailyWork::findOrFail($request->id);

            $inspectionDetails = $request->inspection_details;
            if ($inspectionDetails === '') {
                $inspectionDetails = null;
            }

            $dailyWork->update(['inspection_details' => $inspectionDetails]);

            return response()->json([
                'message' => 'Inspection details updated successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateIncharge(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'id' => 'required|exists:daily_works,id',
                'incharge' => 'nullable|exists:users,id',
            ]);

            $dailyWork = DailyWork::findOrFail($request->id);

            $this->authorize('updateIncharge', $dailyWork);

            $this->dailyWorkService->updateIncharge($dailyWork, $request->incharge);

            return response()->json([
                'message' => 'Incharge updated successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateAssigned(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'id' => 'required|exists:daily_works,id',
                'assigned' => 'nullable|exists:users,id',
            ]);

            $dailyWork = DailyWork::findOrFail($request->id);

            $this->authorize('updateAssigned', $dailyWork);

            $this->dailyWorkService->updateAssigned($dailyWork, $request->assigned);

            return response()->json([
                'message' => 'Assigned user updated successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function assignWork(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'id' => 'required|exists:daily_works,id',
                'assigned' => 'required|exists:users,id',
            ]);

            $dailyWork = DailyWork::findOrFail($request->id);

            $this->authorize('updateAssigned', $dailyWork);

            $this->dailyWorkService->updateAssigned($dailyWork, $request->assigned);

            return response()->json([
                'message' => 'Work assigned successfully',
                'dailyWork' => $dailyWork->load(['inchargeUser', 'assignedUser']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function add(Request $request)
    {
        try {
            $this->authorize('create', DailyWork::class);

            $result = $this->crudService->create($request);

            return response()->json($result);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function uploadRFIFile(Request $request)
    {
        try {
            $result = $this->fileService->uploadRfiFile($request);

            return response()->json($result);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while uploading the RFI file.'], 500);
        }
    }

    /**
     * Upload multiple RFI files for a daily work.
     */
    public function uploadRfiFiles(Request $request, DailyWork $dailyWork)
    {
        try {
            // Debug logging - check what's coming in
            \Log::info('RFI Upload Request', [
                'daily_work_id' => $dailyWork->id,
                'has_files' => $request->hasFile('files'),
                'all_files' => $request->allFiles(),
                'content_type' => $request->header('Content-Type'),
            ]);

            $request->validate([
                'files' => 'required|array|min:1',
                'files.*' => 'required|file|mimes:jpeg,jpg,png,webp,gif,pdf|max:10240', // 10MB max per file
            ], [
                'files.required' => 'Please select at least one file to upload.',
                'files.*.mimes' => 'Only images (JPEG, PNG, WebP, GIF) and PDF files are allowed.',
                'files.*.max' => 'Each file must be less than 10MB.',
            ]);

            $files = $request->file('files');

            \Log::info('Files after validation', [
                'file_count' => is_array($files) ? count($files) : 0,
                'files_type' => gettype($files),
            ]);

            if (empty($files)) {
                return response()->json(['error' => 'No files received by server.'], 400);
            }

            $result = $this->fileService->uploadRfiFiles($dailyWork, $files);

            return response()->json([
                'message' => count($result['uploaded']).' file(s) uploaded successfully.',
                'files' => $result['uploaded'],
                'errors' => $result['errors'],
                'total_files' => $result['total_files'],
            ]);
        } catch (ValidationException $e) {
            \Log::error('RFI Upload Validation Error', ['errors' => $e->errors()]);

            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('RFI Upload Error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => 'An error occurred while uploading files: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get all RFI files for a daily work.
     */
    public function getRfiFiles(DailyWork $dailyWork)
    {
        try {
            $files = $this->fileService->getRfiFiles($dailyWork);

            return response()->json([
                'files' => $files,
                'total' => count($files),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while fetching files.'], 500);
        }
    }

    /**
     * Delete a specific RFI file.
     */
    public function deleteRfiFile(DailyWork $dailyWork, int $mediaId)
    {
        try {
            $deleted = $this->fileService->deleteRfiFile($dailyWork, $mediaId);

            if (! $deleted) {
                return response()->json(['error' => 'File not found.'], 404);
            }

            return response()->json([
                'message' => 'File deleted successfully.',
                'total_files' => $dailyWork->getMedia('rfi_files')->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while deleting the file.'], 500);
        }
    }

    /**
     * Download/view a specific RFI file.
     */
    public function downloadRfiFile(DailyWork $dailyWork, int $mediaId)
    {
        try {
            $media = $this->fileService->getRfiFile($dailyWork, $mediaId);

            if (! $media) {
                return response()->json(['error' => 'File not found.'], 404);
            }

            // For inline viewing (images and PDFs)
            return response()->file($media->getPath(), [
                'Content-Type' => $media->mime_type,
                'Content-Disposition' => 'inline; filename="'.$media->file_name.'"',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while downloading the file.'], 500);
        }
    }

    public function getOrdinalNumber($number): string
    {
        return $this->crudService->getOrdinalNumber((int) $number);
    }

    public function attachReport(Request $request)
    {
        try {
            $result = $this->fileService->attachReport($request);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function detachReport(Request $request)
    {
        try {
            $result = $this->fileService->detachReport($request);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function downloadTemplate()
    {
        try {
            return $this->importService->downloadTemplate();
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Bulk update RFI response status with objection warnings.
     */
    public function bulkResponseStatusUpdate(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|exists:daily_works,id',
                'rfi_response_status' => 'required|string|in:'.implode(',', DailyWork::$rfiResponseStatuses),
                'rfi_response_date' => 'required|date',
                'skip_objected' => 'sometimes|boolean',
                'override_objected' => 'sometimes|boolean',
                'override_reason' => 'nullable|required_if:override_objected,true|string|max:1000',
            ]);

            $result = $this->dailyWorkService->bulkResponseStatusUpdate(
                ids: $request->ids,
                responseStatus: $request->rfi_response_status,
                responseDate: $request->rfi_response_date,
                userId: auth()->id(),
                skipObjected: $request->boolean('skip_objected'),
                overrideObjected: $request->boolean('override_objected'),
                overrideReason: $request->override_reason
            );

            if (!empty($result['requires_decision'])) {
                return response()->json([
                    'requires_decision' => true,
                    'total_count' => $result['total_count'],
                    'objected_count' => $result['objected_count'],
                    'clean_count' => $result['clean_count'],
                    'objected_works' => $result['objected_works'],
                    'message' => 'Some RFIs have active objections. Please choose to skip them or override with a reason.',
                ], 422);
            }

            return response()->json([
                'message' => $this->buildBulkResponseMessage($result['updated'], $result['skipped'], $result['failed'], $request->rfi_response_status),
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'updated_count' => count($result['updated']),
                'skipped_count' => count($result['skipped']),
                'failed_count' => count($result['failed']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Build a human-readable message for bulk response status result.
     */
    private function buildBulkResponseMessage(array $updated, array $skipped, array $failed, string $status): string
    {
        $statusLabels = [
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'returned' => 'Returned',
            'concurred' => 'Concurred',
            'not_concurred' => 'Not Concurred',
        ];
        $statusLabel = $statusLabels[$status] ?? ucfirst($status);

        $parts = [];

        if (count($updated) > 0) {
            $parts[] = count($updated).' RFI(s) marked as '.$statusLabel;
        }
        if (count($skipped) > 0) {
            $parts[] = count($skipped).' RFI(s) skipped (have objections)';
        }
        if (count($failed) > 0) {
            $parts[] = count($failed).' RFI(s) failed';
        }

        return implode(', ', $parts).'.';
    }

    /**
     * Import RFI response statuses from Excel file.
     * Excel should have three columns: RFI Number, Response Status, and Response Date.
     */
    public function bulkImportResponseStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
                'skip_objected' => 'sometimes|boolean',
                'override_objected' => 'sometimes|boolean',
                'override_reason' => 'nullable|required_if:override_objected,true|string|max:1000',
            ]);

            $skipObjected = $request->boolean('skip_objected');
            $overrideObjected = $request->boolean('override_objected');
            $overrideReason = $request->override_reason;

            // Load the Excel file
            $file = $request->file('file');
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            if (count($rows) < 2) {
                return response()->json(['error' => 'File must contain at least a header row and one data row'], 422);
            }

            // Parse header to detect column order
            $header = array_map('strtolower', array_map('trim', $rows[0]));
            $rfiNumberCol = null;
            $statusCol = null;
            $dateCol = null;

            foreach ($header as $index => $colName) {
                // Check for RFI number column
                if ($rfiNumberCol === null && preg_match('/rfi.*number|number.*rfi|rfi/i', $colName)) {
                    $rfiNumberCol = $index;
                }
                // Check for status column (must contain 'status' but not 'date')
                elseif ($statusCol === null && preg_match('/status/i', $colName) && ! preg_match('/date/i', $colName)) {
                    $statusCol = $index;
                }
                // Check for date column
                elseif ($dateCol === null && preg_match('/date/i', $colName)) {
                    $dateCol = $index;
                }
            }

            // If headers not detected, assume order: RFI Number, Status, Date
            if ($rfiNumberCol === null) {
                $rfiNumberCol = 0;
            }
            if ($statusCol === null) {
                $statusCol = 1;
            }
            if ($dateCol === null) {
                $dateCol = 2;
            }

            $dataRows = array_slice($rows, 1);
            $parsedData = [];
            $validationErrors = [];

            foreach ($dataRows as $rowIndex => $row) {
                $rowNum = $rowIndex + 2;
                $rfiNumber = trim($row[$rfiNumberCol] ?? '');
                $status = strtolower(trim($row[$statusCol] ?? ''));
                $dateValue = $row[$dateCol] ?? null;

                if (empty($rfiNumber) && empty($status)) {
                    continue;
                }

                if (empty($rfiNumber)) {
                    $validationErrors[] = "Row {$rowNum}: RFI Number is required";

                    continue;
                }

                // Normalize status values
                $statusMap = [
                    'approved' => 'approved',
                    'approve' => 'approved',
                    'rejected' => 'rejected',
                    'reject' => 'rejected',
                    'returned' => 'returned',
                    'return' => 'returned',
                    'concurred' => 'concurred',
                    'concur' => 'concurred',
                    'not_concurred' => 'not_concurred',
                    'not concurred' => 'not_concurred',
                    'notconcurred' => 'not_concurred',
                    'not-concurred' => 'not_concurred',
                ];

                $normalizedStatus = $statusMap[$status] ?? null;
                if (! $normalizedStatus) {
                    $validationErrors[] = "Row {$rowNum}: Invalid status '{$status}'. Valid values: approved, rejected, returned, concurred, not_concurred";

                    continue;
                }

                // Parse date using the export service helper
                $parsedDate = null;
                if ($dateValue) {
                    $parsedDate = $this->dailyWorkExportService->parseExcelDate($dateValue);
                    if (!$parsedDate) {
                        $validationErrors[] = "Row {$rowNum}: Could not parse date '{$dateValue}'";
                        continue;
                    }
                } else {
                    $parsedDate = now()->format('Y-m-d');
                }

                $parsedData[] = [
                    'rfi_number' => $rfiNumber,
                    'status' => $normalizedStatus,
                    'date' => $parsedDate,
                    'row' => $rowNum,
                ];
            }

            if (! empty($validationErrors)) {
                return response()->json([
                    'validation_errors' => $validationErrors,
                    'message' => 'Some rows have validation errors. Please fix them and try again.',
                ], 422);
            }

            if (empty($parsedData)) {
                return response()->json(['error' => 'No valid data rows found in the file'], 422);
            }

            // Find daily works by RFI numbers
            $rfiNumbers = array_column($parsedData, 'rfi_number');
            $dailyWorks = DailyWork::whereIn('number', $rfiNumbers)
                ->withCount(['objections as active_objections_count' => function ($query) {
                    $query->whereIn('status', ['draft', 'submitted', 'under_review']);
                }])
                ->get()
                ->keyBy('number');

            // Separate into found/not found
            $notFound = [];
            $foundData = [];
            foreach ($parsedData as $data) {
                if (! isset($dailyWorks[$data['rfi_number']])) {
                    $notFound[] = [
                        'rfi_number' => $data['rfi_number'],
                        'row' => $data['row'],
                    ];
                } else {
                    $foundData[] = array_merge($data, ['work' => $dailyWorks[$data['rfi_number']]]);
                }
            }

            // Check for objections
            $worksWithObjections = array_filter($foundData, fn ($d) => ($d['work']->active_objections_count ?? 0) > 0);
            $worksWithoutObjections = array_filter($foundData, fn ($d) => ($d['work']->active_objections_count ?? 0) === 0);

            if (count($worksWithObjections) > 0 && ! $skipObjected && ! $overrideObjected) {
                return response()->json([
                    'requires_decision' => true,
                    'total_count' => count($foundData),
                    'objected_count' => count($worksWithObjections),
                    'clean_count' => count($worksWithoutObjections),
                    'not_found_count' => count($notFound),
                    'objected_works' => array_map(fn ($d) => [
                        'id' => $d['work']->id,
                        'number' => $d['work']->number,
                        'location' => $d['work']->location,
                        'active_objections_count' => $d['work']->active_objections_count,
                        'status' => $d['status'],
                    ], array_values($worksWithObjections)),
                    'message' => 'Some RFIs have active objections. Please choose to skip them or override with a reason.',
                ], 422);
            }

            $updated = [];
            $skipped = [];
            $failed = [];

            // Process works without objections
            foreach ($worksWithoutObjections as $data) {
                try {
                    $data['work']->update([
                        'rfi_response_status' => $data['status'],
                        'rfi_response_date' => $data['date'],
                    ]);
                    $updated[] = [
                        'id' => $data['work']->id,
                        'number' => $data['work']->number,
                        'status' => $data['status'],
                        'dailyWork' => $data['work']->fresh(['inchargeUser', 'assignedUser']),
                    ];
                } catch (\Exception $e) {
                    $failed[] = [
                        'id' => $data['work']->id,
                        'number' => $data['work']->number,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Process works with objections
            foreach ($worksWithObjections as $data) {
                if ($skipObjected) {
                    $skipped[] = [
                        'id' => $data['work']->id,
                        'number' => $data['work']->number,
                        'active_objections_count' => $data['work']->active_objections_count,
                    ];

                    continue;
                }

                if ($overrideObjected) {
                    try {
                        \App\Models\RfiSubmissionOverrideLog::logOverride(
                            dailyWorkId: $data['work']->id,
                            oldDate: $data['work']->rfi_response_date?->format('Y-m-d'),
                            newDate: $data['date'],
                            activeObjectionsCount: $data['work']->active_objections_count,
                            reason: $overrideReason.' (Import response status: '.$data['status'].')',
                            userId: auth()->id()
                        );

                        $data['work']->update([
                            'rfi_response_status' => $data['status'],
                            'rfi_response_date' => $data['date'],
                        ]);
                        $updated[] = [
                            'id' => $data['work']->id,
                            'number' => $data['work']->number,
                            'status' => $data['status'],
                            'override_logged' => true,
                            'dailyWork' => $data['work']->fresh(['inchargeUser', 'assignedUser']),
                        ];
                    } catch (\Exception $e) {
                        $failed[] = [
                            'id' => $data['work']->id,
                            'number' => $data['work']->number,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            return response()->json([
                'message' => 'Import completed. '.count($updated).' RFI(s) updated, '.count($skipped).' skipped, '.count($failed).' failed.',
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'not_found' => $notFound,
                'updated_count' => count($updated),
                'skipped_count' => count($skipped),
                'failed_count' => count($failed),
                'not_found_count' => count($notFound),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Bulk Import Response Status Error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => 'An error occurred: '.$e->getMessage()], 500);
        }
    }

    /**
     * Download template for bulk import response status.
     */
    public function downloadResponseStatusTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $tempFile = $this->dailyWorkExportService->generateResponseStatusTemplate();

        return response()->download($tempFile, 'rfi_response_status_import_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
