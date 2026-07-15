<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListDailyWorkObjectionsRequest;
use App\Http\Requests\Api\V1\ListDailyWorksRequest;
use App\Http\Requests\Api\V1\ListMyObjectionsRequest;
use App\Http\Requests\Api\V1\ListObjectionQueueRequest;
use App\Http\Requests\Api\V1\RejectDailyWorkObjectionRequest;
use App\Http\Requests\Api\V1\ResolveDailyWorkObjectionRequest;
use App\Http\Requests\Api\V1\StoreDailyWorkObjectionRequest;
use App\Http\Requests\Api\V1\UpdateDailyWorkAssignedRequest;
use App\Http\Requests\Api\V1\UpdateDailyWorkInchargeRequest;
use App\Http\Requests\Api\V1\UpdateDailyWorkStatusRequest;
use App\Http\Requests\Api\V1\UploadDailyWorkObjectionFilesRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use App\Repositories\DailyWorkRepository;
use App\Services\DailyWork\DailyWorkQueryService;
use App\Services\Project\DailyWorkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DailyWorkController extends Controller
{
    use ApiResponse;

    protected DailyWorkRepository $dailyWorkRepository;

    protected DailyWorkQueryService $dailyWorkQueryService;

    protected DailyWorkService $dailyWorkService;

    /**
     * @var array<int, array<int, array{id:int,name:string}>>
     */
    private array $assigneeCandidatesCache = [];

    /**
     * @var array<int, array{id:int,name:string}>|null
     */
    private ?array $inchargeCandidatesCache = null;

    public function __construct(
        DailyWorkRepository $dailyWorkRepository,
        DailyWorkQueryService $dailyWorkQueryService,
        DailyWorkService $dailyWorkService
    ) {
        $this->dailyWorkRepository = $dailyWorkRepository;
        $this->dailyWorkQueryService = $dailyWorkQueryService;
        $this->dailyWorkService = $dailyWorkService;
    }

    public function index(ListDailyWorksRequest $request): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) $request->input('perPage', 10);
        $type = $request->input('type');

        // Build the visibility/status/date/search/objection-filtered query WITHOUT the
        // type filter so the cross-type summary counts (type-tab badges, overview) stay
        // accurate. The type filter is applied only to the paginated list below.
        $baseQuery = $this->dailyWorkService
            ->buildFilteredDailyWorksQuery($user, $request->except('type'));

        $this->applyDatesFilter($baseQuery, $request);
        $this->applyInspectionAndResponseFilters($baseQuery, $request);

        $listQuery = (clone $baseQuery)
            ->when($type !== null && $type !== '', function (Builder $query) use ($type): void {
                $query->where('type', $type);
            })
            ->with([
                'inchargeUser:id,name',
                'assignedUser:id,name',
            ])
            ->withCount(['activeObjections'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        $dailyWorks = $listQuery
            ->paginate($perPage)
            ->appends($request->query());

        $rows = $dailyWorks->getCollection();

        $payload = [
            'daily_works' => $rows
                ->map(function (DailyWork $dailyWork) use ($user) {
                    return $this->transformDailyWork($dailyWork, $user);
                })
                ->values(),
            'pagination' => [
                'current_page' => $dailyWorks->currentPage(),
                'last_page' => $dailyWorks->lastPage(),
                'per_page' => $dailyWorks->perPage(),
                'total' => $dailyWorks->total(),
            ],
            // Candidate lists are returned ONCE per response (not embedded per row) to
            // avoid duplicating the same supervisor/assignee arrays across every record.
            'assignment_options' => $this->buildTopLevelAssignmentOptions($rows, $user),
        ];

        // The aggregate summary is expensive relative to a single page, so it is only
        // computed when the client explicitly asks for it (filter/date changes), not on
        // every pagination step.
        if ($request->boolean('include_summary')) {
            $payload['summary'] = $this->buildDailyWorksSummary(clone $baseQuery);
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function selectableDates(ListDailyWorksRequest $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->buildFilteredDailyWorksQuery($user, $request);

        $dates = (clone $query)
            ->whereNotNull('date')
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->map(function (mixed $dateValue) {
                return $this->normalizeDate($dateValue);
            })
            ->filter()
            ->unique()
            ->values();

        $latestDate = $dates->last();

        return response()->json([
            'success' => true,
            'data' => [
                'dates' => $dates,
                'latest_date' => $latestDate,
                'total_dates' => $dates->count(),
            ],
        ]);
    }

    public function myObjections(ListMyObjectionsRequest $request): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) $request->input('perPage', 10);

        $baseQuery = RfiObjection::query()
            ->where('created_by', (int) $user->id);

        $query = (clone $baseQuery)
            ->with([
                'createdBy:id,name',
                'dailyWorks:id,number,date,location,type,status',
            ])
            ->withCount('dailyWorks');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');

            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('chainage_from', 'like', "%{$search}%")
                    ->orWhere('chainage_to', 'like', "%{$search}%");
            });
        }

        $objections = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        $statistics = $this->buildObjectionStatistics($baseQuery);

        return response()->json([
            'success' => true,
            'data' => [
                'objections' => $objections->getCollection()
                    ->map(fn (RfiObjection $objection): array => $this->transformObjectionForMobileList($objection, $user))
                    ->values(),
                'pagination' => [
                    'current_page' => $objections->currentPage(),
                    'last_page' => $objections->lastPage(),
                    'per_page' => $objections->perPage(),
                    'total' => $objections->total(),
                ],
                'statistics' => $statistics,
            ],
        ]);
    }

    public function objectionQueue(ListObjectionQueueRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->canReviewObjection($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access objection queue.',
            ], 403);
        }

        $perPage = (int) $request->input('perPage', 10);
        $baseQuery = RfiObjection::query();

        $query = (clone $baseQuery)
            ->with([
                'createdBy:id,name',
                'dailyWorks:id,number,date,location,type,status',
            ])
            ->withCount('dailyWorks');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', (int) $request->input('created_by'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');

            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('chainage_from', 'like', "%{$search}%")
                    ->orWhere('chainage_to', 'like', "%{$search}%")
                    ->orWhereHas('createdBy', function (Builder $creatorQuery) use ($search): void {
                        $creatorQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $objections = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        $creatorOptions = User::query()
            ->whereIn('id', RfiObjection::query()->select('created_by')->whereNotNull('created_by')->distinct())
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function (User $creator): array {
                return [
                    'id' => (int) $creator->id,
                    'name' => $creator->name,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'objections' => $objections->getCollection()
                    ->map(fn (RfiObjection $objection): array => $this->transformObjectionForMobileList($objection, $user))
                    ->values(),
                'pagination' => [
                    'current_page' => $objections->currentPage(),
                    'last_page' => $objections->lastPage(),
                    'per_page' => $objections->perPage(),
                    'total' => $objections->total(),
                ],
                'statistics' => $this->buildObjectionStatistics($baseQuery),
                'creators' => $creatorOptions,
            ],
        ]);
    }

    public function show(Request $request, int $dailyWorkId): JsonResponse
    {
        $dailyWork = DailyWork::query()
            ->with([
                'inchargeUser:id,name',
                'assignedUser:id,name',
            ])
            ->withCount(['activeObjections'])
            ->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this daily work.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformDailyWork($dailyWork, $request->user()),
        ]);
    }

    public function updateStatus(UpdateDailyWorkStatusRequest $request, int $dailyWorkId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canUpdateStatus($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update the status of this daily work.',
            ], 403);
        }

        $status = (string) $request->input('status');

        $this->dailyWorkService->updateStatus(
            $dailyWork,
            $status,
            $request->input('inspection_result'),
            false // API updates do not modify submission_time
        );

        $dailyWork->load([
            'inchargeUser:id,name',
            'assignedUser:id,name',
        ])->loadCount(['activeObjections']);

        return response()->json([
            'success' => true,
            'message' => 'Daily work status updated successfully.',
            'data' => $this->transformDailyWork($dailyWork, $request->user()),
        ]);
    }

    public function updateIncharge(UpdateDailyWorkInchargeRequest $request, int $dailyWorkId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canUpdateIncharge($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update incharge for this daily work.',
            ], 403);
        }

        $this->dailyWorkService->updateIncharge($dailyWork, $request->input('incharge'));

        $dailyWork->load([
            'inchargeUser:id,name',
            'assignedUser:id,name',
        ])->loadCount(['activeObjections']);

        return response()->json([
            'success' => true,
            'message' => 'Daily work incharge updated successfully.',
            'data' => $this->transformDailyWork($dailyWork, $request->user()),
        ]);
    }

    public function updateAssigned(UpdateDailyWorkAssignedRequest $request, int $dailyWorkId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canUpdateAssigned($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update assigned user for this daily work.',
            ], 403);
        }

        $this->dailyWorkService->updateAssigned($dailyWork, $request->input('assigned'));

        $dailyWork->load([
            'inchargeUser:id,name',
            'assignedUser:id,name',
        ])->loadCount(['activeObjections']);

        return response()->json([
            'success' => true,
            'message' => 'Daily work assigned user updated successfully.',
            'data' => $this->transformDailyWork($dailyWork, $request->user()),
        ]);
    }

    public function objections(ListDailyWorkObjectionsRequest $request, int $dailyWorkId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access objections for this daily work.',
            ], 403);
        }

        $perPage = (int) $request->input('perPage', 10);

        $query = RfiObjection::query()
            ->select('rfi_objections.*')
            ->with(['createdBy:id,name'])
            ->where(function ($objectionQuery) use ($dailyWork) {
                $objectionQuery->whereHas('dailyWorks', function ($dailyWorkQuery) use ($dailyWork) {
                    $dailyWorkQuery->where('daily_works.id', $dailyWork->id);
                });

                if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
                    $objectionQuery->orWhere('daily_work_id', $dailyWork->id);
                }
            })
            ->distinct();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $objections = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json([
            'success' => true,
            'data' => [
                'objections' => $objections->getCollection()
                    ->map(function (RfiObjection $objection) {
                        return $this->transformObjection($objection);
                    })
                    ->values(),
                'pagination' => [
                    'current_page' => $objections->currentPage(),
                    'last_page' => $objections->lastPage(),
                    'per_page' => $objections->perPage(),
                    'total' => $objections->total(),
                ],
            ],
        ]);
    }

    public function storeObjection(StoreDailyWorkObjectionRequest $request, int $dailyWorkId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to create objections for this daily work.',
            ], 403);
        }

        try {
            $objection = $this->dailyWorkService->storeObjection($dailyWork, $request->all(), $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Objection created successfully.',
                'data' => $this->transformObjection($objection),
            ], 201);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create objection.',
            ], 500);
        }
    }

    public function submitObjection(Request $request, int $dailyWorkId, int $objectionId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this daily work.',
            ], 403);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canSubmitObjection($request->user(), $objection)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to submit this objection.',
            ], 403);
        }

        try {
            $objection = $this->dailyWorkService->submitObjection($objection);

            return response()->json([
                'success' => true,
                'message' => 'Objection submitted for review.',
                'data' => $this->transformObjection($objection),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit objection.',
            ], 500);
        }
    }

    public function startReviewObjection(Request $request, int $dailyWorkId, int $objectionId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this daily work.',
            ], 403);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canReviewObjection($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to review this objection.',
            ], 403);
        }

        try {
            $objection = $this->dailyWorkService->startReviewObjection($objection);

            return response()->json([
                'success' => true,
                'message' => 'Objection is now under review.',
                'data' => $this->transformObjection($objection),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start objection review.',
            ], 500);
        }
    }

    public function resolveObjection(ResolveDailyWorkObjectionRequest $request, int $dailyWorkId, int $objectionId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this daily work.',
            ], 403);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canReviewObjection($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to review this objection.',
            ], 403);
        }

        try {
            $objection = $this->dailyWorkService->resolveObjection($objection, $request->input('resolution_notes'));

            return response()->json([
                'success' => true,
                'message' => 'Objection resolved successfully.',
                'data' => $this->transformObjection($objection),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve objection.',
            ], 500);
        }
    }

    public function rejectObjection(RejectDailyWorkObjectionRequest $request, int $dailyWorkId, int $objectionId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this daily work.',
            ], 403);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canReviewObjection($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to review this objection.',
            ], 403);
        }

        try {
            $reason = $request->input('resolution_notes', $request->input('rejection_reason'));
            $objection = $this->dailyWorkService->rejectObjection($objection, $reason);

            return response()->json([
                'success' => true,
                'message' => 'Objection rejected.',
                'data' => $this->transformObjection($objection),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject objection.',
            ], 500);
        }
    }

    public function objectionFiles(Request $request, int $dailyWorkId, int $objectionId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canViewObjectionFiles($request->user(), $dailyWork, $objection)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view objection files for this daily work.',
            ], 403);
        }

        $files = $objection->getMedia('objection_files')
            ->map(function ($media) {
                return $this->transformObjectionFile($media);
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'files' => $files,
                'total' => $files->count(),
            ],
        ]);
    }

    public function uploadObjectionFiles(UploadDailyWorkObjectionFilesRequest $request, int $dailyWorkId, int $objectionId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canManageObjectionFiles($request->user(), $dailyWork, $objection)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to upload objection files for this daily work.',
            ], 403);
        }

        $uploadedFiles = [];

        try {
            $files = $this->dailyWorkService->uploadObjectionFiles($objection, $request->file('files'));
            foreach ($files as $media) {
                $uploadedFiles[] = $this->transformObjectionFile($media);
            }

            return response()->json([
                'success' => true,
                'message' => count($uploadedFiles).' file(s) uploaded successfully.',
                'data' => [
                    'files' => $uploadedFiles,
                    'total_files' => $this->countObjectionFiles($objection),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload objection files.',
            ], 500);
        }
    }

    public function deleteObjectionFile(Request $request, int $dailyWorkId, int $objectionId, int $mediaId): JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canManageObjectionFiles($request->user(), $dailyWork, $objection)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete objection files for this daily work.',
            ], 403);
        }

        $media = $objection->getMedia('objection_files')->where('id', $mediaId)->first();

        if (! $media) {
            return response()->json([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        try {
            $media->delete();

            return response()->json([
                'success' => true,
                'message' => 'File deleted successfully.',
                'data' => [
                    'total_files' => $this->countObjectionFiles($objection),
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete objection file.',
            ], 500);
        }
    }

    public function downloadObjectionFile(Request $request, int $dailyWorkId, int $objectionId, int $mediaId): BinaryFileResponse|JsonResponse
    {
        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return response()->json([
                'success' => false,
                'message' => 'Daily work not found.',
            ], 404);
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return response()->json([
                'success' => false,
                'message' => 'Objection not found for this daily work.',
            ], 404);
        }

        if (! $this->canViewObjectionFiles($request->user(), $dailyWork, $objection)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to download objection files for this daily work.',
            ], 403);
        }

        $media = $objection->getMedia('objection_files')->where('id', $mediaId)->first();

        if (! $media) {
            return response()->json([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        return response()->download($media->getPath(), $media->file_name);
    }

    public function objectionMetadata(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'categories' => collect(RfiObjection::$categories)->map(function (string $category) {
                    return [
                        'value' => $category,
                        'label' => RfiObjection::$categoryLabels[$category] ?? ucfirst(str_replace('_', ' ', $category)),
                    ];
                })->values(),
                'statuses' => collect(RfiObjection::$statuses)->map(function (string $status) {
                    return [
                        'value' => $status,
                        'label' => RfiObjection::$statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
                    ];
                })->values(),
                'types' => collect(RfiObjection::$types)->map(function (string $type) {
                    return [
                        'value' => $type,
                        'label' => $type,
                    ];
                })->values(),
            ],
        ]);
    }

    private function buildObjectionStatistics(Builder $baseQuery): array
    {
        $statuses = (clone $baseQuery)->pluck('status');
        $pendingStatuses = [
            RfiObjection::STATUS_SUBMITTED,
            RfiObjection::STATUS_UNDER_REVIEW,
        ];

        return [
            'total' => $statuses->count(),
            'active' => $statuses->filter(fn (mixed $status): bool => in_array((string) $status, RfiObjection::$activeStatuses, true))->count(),
            'resolved' => $statuses->filter(fn (mixed $status): bool => (string) $status === RfiObjection::STATUS_RESOLVED)->count(),
            'rejected' => $statuses->filter(fn (mixed $status): bool => (string) $status === RfiObjection::STATUS_REJECTED)->count(),
            'pending' => $statuses->filter(fn (mixed $status): bool => in_array((string) $status, $pendingStatuses, true))->count(),
        ];
    }

    private function transformObjectionForMobileList(RfiObjection $objection, User $user): array
    {
        $transformedObjection = $this->transformObjection($objection);

        $dailyWorks = $objection->dailyWorks
            ->map(function (DailyWork $dailyWork): array {
                return [
                    'id' => (int) $dailyWork->id,
                    'number' => $dailyWork->number,
                    'date' => $this->normalizeDate($dailyWork->date),
                    'location' => $dailyWork->location,
                    'type' => $dailyWork->type,
                    'status' => $dailyWork->status,
                ];
            })
            ->values();

        $transformedObjection['daily_works'] = $dailyWorks;
        $transformedObjection['daily_works_count'] = $dailyWorks->count();
        $transformedObjection['primary_daily_work_id'] = $dailyWorks->isNotEmpty()
            ? (int) ($dailyWorks->first()['id'] ?? 0)
            : null;

        $canReviewObjection = $this->canReviewObjection($user);
        $canResolveOrReject = in_array(
            (string) $objection->status,
            [RfiObjection::STATUS_SUBMITTED, RfiObjection::STATUS_UNDER_REVIEW],
            true
        );

        $transformedObjection['permissions'] = [
            'can_submit' => $this->canSubmitObjection($user, $objection)
                && (string) $objection->status === RfiObjection::STATUS_DRAFT,
            'can_review' => $canReviewObjection
                && (string) $objection->status === RfiObjection::STATUS_SUBMITTED,
            'can_resolve' => $canReviewObjection && $canResolveOrReject,
            'can_reject' => $canReviewObjection && $canResolveOrReject,
        ];

        return $transformedObjection;
    }

    private function buildFilteredDailyWorksQuery(User $user, ListDailyWorksRequest $request): Builder
    {
        return $this->dailyWorkService->buildFilteredDailyWorksQuery($user, $request->all());
    }

    /**
     * Constrain the query to an explicit set of dates (multi-date selection). This lets
     * the mobile client select non-contiguous dates in a single request instead of
     * pulling every page for each date and merging client-side.
     */
    private function applyDatesFilter(Builder $query, ListDailyWorksRequest $request): void
    {
        $dates = $request->input('dates');

        if (! is_array($dates)) {
            return;
        }

        $normalized = collect($dates)
            ->map(fn ($value) => $this->normalizeDate($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($normalized) > 0) {
            // Compare on the DATE part only. The `date` column is cast to 'date'
            // (serialized as 'Y-m-d 00:00:00'), so an exact whereIn on 'Y-m-d'
            // strings misses on stores that keep the time component. whereDate is
            // DB-agnostic (DATE(date) = ?) and matches the pattern used elsewhere.
            $query->where(function (Builder $dateQuery) use ($normalized): void {
                foreach ($normalized as $date) {
                    $dateQuery->orWhereDate('date', $date);
                }
            });
        }
    }

    /**
     * Apply the inspection-result and RFI-response filters server-side. The sentinel
     * value "none" matches records with no result recorded (null/empty).
     */
    private function applyInspectionAndResponseFilters(Builder $query, ListDailyWorksRequest $request): void
    {
        $inspection = $request->input('inspection_result');

        if ($inspection !== null && $inspection !== '') {
            if ($inspection === 'none') {
                $query->where(function (Builder $sub): void {
                    $sub->whereNull('inspection_result')->orWhere('inspection_result', '');
                });
            } else {
                $query->where('inspection_result', $inspection);
            }
        }

        $response = $request->input('rfi_response_status');

        if ($response !== null && $response !== '') {
            if ($response === 'none') {
                $query->where(function (Builder $sub): void {
                    $sub->whereNull('rfi_response_status')->orWhere('rfi_response_status', '');
                });
            } else {
                $query->where('rfi_response_status', $response);
            }
        }
    }

    /**
     * Build the assignment option lists ONCE per response instead of embedding them on
     * every row. The incharge candidate list is global (only exposed to privileged
     * users); assignee candidates are keyed by the in-charge id and only built for the
     * in-charges present on the current page that this user is allowed to reassign.
     *
     * @param  \Illuminate\Support\Collection<int, DailyWork>  $dailyWorks
     * @return array{incharge_candidates: array<int, array{id:int,name:string}>, assigned_candidates_by_incharge: array<int, array<int, array{id:int,name:string}>>}
     */
    private function buildTopLevelAssignmentOptions($dailyWorks, User $user): array
    {
        $inchargeCandidates = $this->canUpdateIncharge($user)
            ? $this->getInchargeCandidates()
            : [];

        $assignedByIncharge = [];

        foreach ($dailyWorks as $dailyWork) {
            if (! $this->canUpdateAssigned($user, $dailyWork)) {
                continue;
            }

            $inchargeId = $dailyWork->incharge ? (int) $dailyWork->incharge : null;

            if (! $inchargeId || array_key_exists($inchargeId, $assignedByIncharge)) {
                continue;
            }

            $assignedByIncharge[$inchargeId] = $this->getAssigneeCandidatesForIncharge($inchargeId);
        }

        return [
            'incharge_candidates' => $inchargeCandidates,
            'assigned_candidates_by_incharge' => $assignedByIncharge,
        ];
    }

    /**
     * Compute the aggregate summary (overview + breakdowns) across ALL matching rows
     * (every type) for the current filters, using grouped COUNT queries rather than
     * loading rows into memory. Mirrors the buckets the mobile client previously
     * computed client-side after pulling every page.
     *
     * @return array<string, mixed>
     */
    private function buildDailyWorksSummary(Builder $baseQuery): array
    {
        $total = (int) (clone $baseQuery)->count();

        $statusRaw = (clone $baseQuery)
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $inspectionRaw = (clone $baseQuery)
            ->select('inspection_result')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('inspection_result')
            ->pluck('aggregate', 'inspection_result');

        $responseRaw = (clone $baseQuery)
            ->select('rfi_response_status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('rfi_response_status')
            ->pluck('aggregate', 'rfi_response_status');

        $typeRaw = (clone $baseQuery)
            ->select('type')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $status = [
            'new' => (int) ($statusRaw['new'] ?? 0),
            'in_progress' => (int) ($statusRaw['in-progress'] ?? 0),
            'pending' => (int) ($statusRaw['pending'] ?? 0),
            'completed' => (int) ($statusRaw['completed'] ?? 0),
            'rejected' => (int) ($statusRaw['rejected'] ?? 0),
            'resubmission' => (int) ($statusRaw['resubmission'] ?? 0),
            'emergency' => (int) ($statusRaw['emergency'] ?? 0),
        ];

        $inspectionKnownKeys = ['pass', 'fail', 'conditional', 'pending', 'approved', 'rejected'];
        $inspection = [];
        $inspectionKnownTotal = 0;
        foreach ($inspectionKnownKeys as $key) {
            $count = (int) ($inspectionRaw[$key] ?? 0);
            $inspection[$key] = $count;
            $inspectionKnownTotal += $count;
        }
        // Anything without a recognised inspection result (incl. null/empty) is "none".
        $inspection['none'] = max($total - $inspectionKnownTotal, 0);

        $responseKnownKeys = ['approved', 'rejected', 'returned', 'concurred', 'not_concurred'];
        $response = [];
        $responseKnownTotal = 0;
        foreach ($responseKnownKeys as $key) {
            $count = (int) ($responseRaw[$key] ?? 0);
            $response[$key] = $count;
            $responseKnownTotal += $count;
        }
        $response['none'] = max($total - $responseKnownTotal, 0);

        $type = [
            'structure' => (int) ($typeRaw[DailyWork::TYPE_STRUCTURE] ?? 0),
            'embankment' => (int) ($typeRaw[DailyWork::TYPE_EMBANKMENT] ?? 0),
            'pavement' => (int) ($typeRaw[DailyWork::TYPE_PAVEMENT] ?? 0),
        ];
        $type['other'] = max($total - ($type['structure'] + $type['embankment'] + $type['pavement']), 0);

        $assigned = (int) (clone $baseQuery)->whereNotNull('assigned')->count();
        $missingIncharge = (int) (clone $baseQuery)->whereNull('incharge')->count();
        $objections = (int) (clone $baseQuery)->whereHas('activeObjections')->count();
        $totalResubmissions = (int) (clone $baseQuery)->sum('resubmission_count');

        $completed = $status['completed'];
        $pipeline = $status['in_progress'] + $status['pending'] + $status['resubmission'];
        $pending = max($total - $completed, 0);
        $completionRate = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
        $avgResubmissions = $total > 0 ? round($totalResubmissions / $total, 1) : 0;

        return [
            'overview' => [
                'total' => $total,
                'completed' => $completed,
                'pending' => $pending,
                'in_progress' => $pipeline,
                'objections' => $objections,
                'completion_rate' => $completionRate,
                'total_resubmissions' => $totalResubmissions,
                'avg_resubmissions' => $avgResubmissions,
            ],
            'status' => $status,
            'inspection' => $inspection,
            'response' => $response,
            'type' => $type,
            'assignment' => [
                'assigned' => $assigned,
                'unassigned' => max($total - $assigned, 0),
                'missing_incharge' => $missingIncharge,
            ],
        ];
    }

    private function canAccessDailyWork(User $user, DailyWork $dailyWork): bool
    {
        return $this->dailyWorkService->canAccessDailyWork($user, $dailyWork);
    }

    private function canSubmitObjection(User $user, RfiObjection $objection): bool
    {
        return $this->dailyWorkService->canSubmitObjection($user, $objection);
    }

    private function canViewObjectionFiles(User $user, DailyWork $dailyWork, RfiObjection $objection): bool
    {
        return $this->dailyWorkService->canViewObjectionFiles($user, $dailyWork, $objection);
    }

    private function canManageObjectionFiles(User $user, DailyWork $dailyWork, RfiObjection $objection): bool
    {
        return $this->dailyWorkService->canManageObjectionFiles($user, $dailyWork, $objection);
    }

    private function canReviewObjection(User $user): bool
    {
        return $this->dailyWorkService->canReviewObjection($user);
    }

    private function findObjectionForDailyWork(int $dailyWorkId, int $objectionId): ?RfiObjection
    {
        return $this->dailyWorkService->findObjectionForDailyWork($dailyWorkId, $objectionId);
    }

    private function isPrivilegedUser(User $user): bool
    {
        return $this->dailyWorkService->isPrivilegedUser($user);
    }

    private function transformDailyWork(DailyWork $dailyWork, User $user): array
    {
        $canUpdateIncharge = $this->canUpdateIncharge($user);
        $canUpdateAssigned = $this->canUpdateAssigned($user, $dailyWork);
        $canUpdateStatus = $this->canUpdateStatus($user, $dailyWork);
        $canViewIncharge = $this->canViewIncharge($user);
        $canViewAssigned = $this->canViewAssigned($user, $dailyWork);

        return [
            'id' => (int) $dailyWork->id,
            'date' => $this->normalizeDate($dailyWork->date),
            'number' => $dailyWork->number,
            'status' => $dailyWork->status,
            'inspection_result' => $dailyWork->inspection_result,
            'rfi_response_status' => $dailyWork->rfi_response_status,
            'rfi_response_date' => $this->normalizeDate($dailyWork->rfi_response_date),
            'type' => $dailyWork->type,
            'description' => $dailyWork->description,
            'location' => $dailyWork->location,
            'side' => $dailyWork->side,
            'qty_layer' => $dailyWork->qty_layer,
            'planned_time' => $dailyWork->planned_time,
            'completion_time' => $this->normalizeDateTime($dailyWork->completion_time),
            'inspection_details' => $dailyWork->inspection_details,
            'resubmission_count' => (int) ($dailyWork->resubmission_count ?? 0),
            'resubmission_date' => $dailyWork->resubmission_date,
            'rfi_submission_date' => $this->normalizeDate($dailyWork->rfi_submission_date),
            'active_objections_count' => (int) ($dailyWork->active_objections_count ?? 0),
            'has_active_objections' => (bool) $dailyWork->has_active_objections,
            'incharge' => $dailyWork->incharge ? (int) $dailyWork->incharge : null,
            'assigned' => $dailyWork->assigned ? (int) $dailyWork->assigned : null,
            'incharge_user' => ($canViewIncharge || $canUpdateIncharge) && $dailyWork->inchargeUser ? [
                'id' => (int) $dailyWork->inchargeUser->id,
                'name' => $dailyWork->inchargeUser->name,
            ] : null,
            'assigned_user' => ($canViewAssigned || $canUpdateAssigned) && $dailyWork->assignedUser ? [
                'id' => (int) $dailyWork->assignedUser->id,
                'name' => $dailyWork->assignedUser->name,
            ] : null,
            'permissions' => [
                'can_update_status' => $canUpdateStatus,
                'can_update_incharge' => $canUpdateIncharge,
                'can_update_assigned' => $canUpdateAssigned,
                'can_view_incharge' => $canViewIncharge,
                'can_view_assigned' => $canViewAssigned,
            ],
            'created_at' => $this->normalizeDateTime($dailyWork->created_at),
            'updated_at' => $this->normalizeDateTime($dailyWork->updated_at),
        ];
    }

    private function transformObjection(RfiObjection $objection): array
    {
        return [
            'id' => (int) $objection->id,
            'title' => $objection->title,
            'category' => $objection->category,
            'category_label' => $objection->category_label,
            'status' => $objection->status,
            'status_label' => $objection->status_label,
            'type' => $objection->type,
            'chainage_from' => $objection->chainage_from,
            'chainage_to' => $objection->chainage_to,
            'description' => $objection->description,
            'reason' => $objection->reason,
            'is_active' => (bool) $objection->is_active,
            'files_count' => (int) $objection->files_count,
            'created_by' => $objection->createdBy ? [
                'id' => (int) $objection->createdBy->id,
                'name' => $objection->createdBy->name,
            ] : null,
            'created_at' => $this->normalizeDateTime($objection->created_at),
            'updated_at' => $this->normalizeDateTime($objection->updated_at),
        ];
    }

    private function transformObjectionFile(mixed $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->file_name,
            'url' => $media->getUrl(),
            'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'is_image' => str_starts_with((string) $media->mime_type, 'image/'),
            'is_pdf' => $media->mime_type === 'application/pdf',
            'created_at' => $this->normalizeDateTime($media->created_at),
        ];
    }

    private function countObjectionFiles(RfiObjection $objection): int
    {
        return $objection->media()
            ->where('collection_name', 'objection_files')
            ->count();
    }

    /**
     * Authorize a STATUS mutation. This is deliberately distinct from
     * canAccessDailyWork() (read visibility): being able to VIEW a daily work
     * — e.g. because your manager (report_to) is its incharge — must NOT grant
     * the right to change it. Mirrors the web DailyWorkPolicy@updateStatus
     * intent so web and mobile agree: a privileged manager, or the record's own
     * incharge/assigned worker, may mutate; a mere viewer may not.
     */
    private function canUpdateStatus(User $user, DailyWork $dailyWork): bool
    {
        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        return (int) $dailyWork->incharge === (int) $user->id
            || (int) $dailyWork->assigned === (int) $user->id;
    }

    private function canUpdateIncharge(User $user): bool
    {
        return $this->isPrivilegedUser($user);
    }

    private function canUpdateAssigned(User $user, DailyWork $dailyWork): bool
    {
        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        return (int) $dailyWork->incharge === (int) $user->id;
    }

    private function canViewIncharge(User $user): bool
    {
        return $this->isPrivilegedUser($user);
    }

    private function canViewAssigned(User $user, DailyWork $dailyWork): bool
    {
        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        return (int) $dailyWork->incharge === (int) $user->id;
    }

    private function getUserDesignationTitle(User $user): string
    {
        return $this->dailyWorkService->getUserDesignationTitle($user);
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function getInchargeCandidates(): array
    {
        if ($this->inchargeCandidatesCache !== null) {
            return $this->inchargeCandidatesCache;
        }

        $query = User::query()
            ->whereNull('deleted_at')
            ->select(['id', 'name'])
            ->orderBy('name');

        if (Schema::hasColumn('users', 'designation_id') && Schema::hasTable('designations')) {
            $query->whereHas('designation', function (Builder $designationQuery) {
                $designationQuery->where('title', 'Supervision Engineer');
            });
        }

        $candidates = $query
            ->get()
            ->map(function (User $candidate): array {
                return [
                    'id' => (int) $candidate->id,
                    'name' => $candidate->name,
                ];
            })
            ->values()
            ->all();

        $this->inchargeCandidatesCache = $candidates;

        return $this->inchargeCandidatesCache;
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function getAssigneeCandidatesForIncharge(?int $inchargeUserId): array
    {
        if (! $inchargeUserId) {
            return [];
        }

        if (array_key_exists($inchargeUserId, $this->assigneeCandidatesCache)) {
            return $this->assigneeCandidatesCache[$inchargeUserId];
        }

        $candidates = User::query()
            ->whereNull('deleted_at')
            ->where('report_to', $inchargeUserId)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(function (User $candidate): array {
                return [
                    'id' => (int) $candidate->id,
                    'name' => $candidate->name,
                ];
            })
            ->values()
            ->all();

        $this->assigneeCandidatesCache[$inchargeUserId] = $candidates;

        return $this->assigneeCandidatesCache[$inchargeUserId];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && $value !== '') {
            return substr($value, 0, 10);
        }

        return null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
