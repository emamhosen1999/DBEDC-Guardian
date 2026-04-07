<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListDailyWorkObjectionsRequest;
use App\Http\Requests\Api\V1\ListDailyWorksRequest;
use App\Http\Requests\Api\V1\RejectDailyWorkObjectionRequest;
use App\Http\Requests\Api\V1\ResolveDailyWorkObjectionRequest;
use App\Http\Requests\Api\V1\StoreDailyWorkObjectionRequest;
use App\Http\Requests\Api\V1\UpdateDailyWorkAssignedRequest;
use App\Http\Requests\Api\V1\UpdateDailyWorkInchargeRequest;
use App\Http\Requests\Api\V1\UpdateDailyWorkStatusRequest;
use App\Http\Requests\Api\V1\UploadDailyWorkObjectionFilesRequest;
use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DailyWorkController extends Controller
{
    /**
     * @var array<int, array<int, array{id:int,name:string}>>
     */
    private array $assigneeCandidatesCache = [];

    /**
     * @var array<int, array{id:int,name:string}>|null
     */
    private ?array $inchargeCandidatesCache = null;

    public function index(ListDailyWorksRequest $request): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) $request->input('perPage', 10);

        $query = $this->buildFilteredDailyWorksQuery($user, $request)
            ->with([
                'inchargeUser:id,name',
                'assignedUser:id,name',
            ])
            ->withCount(['activeObjections']);

        $dailyWorks = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json([
            'success' => true,
            'data' => [
                'daily_works' => $dailyWorks->getCollection()
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
            ],
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

        if (! $this->canAccessDailyWork($request->user(), $dailyWork)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update this daily work.',
            ], 403);
        }

        $status = (string) $request->input('status');

        $updateData = [
            'status' => $status,
        ];

        if ($request->filled('inspection_result')) {
            $updateData['inspection_result'] = $request->input('inspection_result');
        }

        if ($status === DailyWork::STATUS_COMPLETED && ! $dailyWork->completion_time) {
            $updateData['completion_time'] = now();
        }

        if ($status === DailyWork::STATUS_NEW) {
            $updateData['completion_time'] = null;
            $updateData['inspection_result'] = null;
        }

        $dailyWork->update($updateData);

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

        $dailyWork->update([
            'incharge' => $request->input('incharge'),
        ]);

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

        $dailyWork->update([
            'assigned' => $request->input('assigned'),
        ]);

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
            DB::beginTransaction();

            $rangeFrom = $request->input('chainage_range_from', $request->input('chainage_from'));
            $rangeTo = $request->input('chainage_range_to', $request->input('chainage_to'));

            $objection = new RfiObjection;
            $objection->title = $request->input('title');
            $objection->category = $request->input('category', RfiObjection::CATEGORY_OTHER);
            $objection->description = $request->input('description');
            $objection->reason = $request->input('reason');
            $objection->status = $request->input('status', RfiObjection::STATUS_DRAFT);
            $objection->created_by = (int) $request->user()->id;

            if (Schema::hasColumn('rfi_objections', 'type')) {
                $objection->type = $request->input('type');
            }

            if (Schema::hasColumn('rfi_objections', 'chainage_from')) {
                $objection->chainage_from = $rangeFrom;
            }

            if (Schema::hasColumn('rfi_objections', 'chainage_to')) {
                $objection->chainage_to = $rangeTo;
            }

            if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
                $objection->setAttribute('daily_work_id', $dailyWork->id);
            }

            $objection->save();

            if (Schema::hasTable('daily_work_objection')) {
                $objection->dailyWorks()->syncWithoutDetaching([
                    $dailyWork->id => [
                        'attached_by' => $request->user()->id,
                        'attached_at' => now(),
                        'attachment_notes' => $request->input('attachment_notes'),
                    ],
                ]);
            }

            if (Schema::hasTable('objection_chainages')) {
                $specificChainages = array_values(array_filter(
                    array_map('trim', preg_split('/\s*,\s*/', (string) $request->input('specific_chainages', ''))),
                    fn (string $chainage): bool => $chainage !== ''
                ));

                $objection->syncChainages($specificChainages, $rangeFrom, $rangeTo);
            }

            DB::commit();

            $objection = $objection->fresh(['createdBy:id,name']) ?? $objection;

            return response()->json([
                'success' => true,
                'message' => 'Objection created successfully.',
                'data' => $this->transformObjection($objection),
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();
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
            $objection->submit('Submitted for review');

            return response()->json([
                'success' => true,
                'message' => 'Objection submitted for review.',
                'data' => $this->transformObjection($objection->fresh(['createdBy:id,name']) ?? $objection),
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
            $objection->startReview('Review started');

            return response()->json([
                'success' => true,
                'message' => 'Objection is now under review.',
                'data' => $this->transformObjection($objection->fresh(['createdBy:id,name']) ?? $objection),
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
            $objection->resolve($request->input('resolution_notes'));

            return response()->json([
                'success' => true,
                'message' => 'Objection resolved successfully.',
                'data' => $this->transformObjection($objection->fresh(['createdBy:id,name']) ?? $objection),
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
            $objection->reject($reason);

            return response()->json([
                'success' => true,
                'message' => 'Objection rejected.',
                'data' => $this->transformObjection($objection->fresh(['createdBy:id,name']) ?? $objection),
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
            foreach ($request->file('files') as $file) {
                $media = $objection
                    ->addMedia($file)
                    ->usingFileName($this->generateUniqueMediaFileName($file))
                    ->toMediaCollection('objection_files');

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

    private function buildFilteredDailyWorksQuery(User $user, ListDailyWorksRequest $request): Builder
    {
        $query = DailyWork::query();
        $userDesignationTitle = $this->getUserDesignationTitle($user);

        if ($this->isPrivilegedUser($user)) {
            // Managers and admin roles can access all daily works.
        } elseif ($userDesignationTitle === 'Supervision Engineer') {
            $query->where('incharge', $user->id);
        } elseif (in_array($userDesignationTitle, ['Quality Control Inspector', 'Asst. Quality Control Inspector'], true)) {
            $query->where('assigned', $user->id);
        } else {
            $query->where(function ($dailyWorkQuery) use ($user) {
                $dailyWorkQuery
                    ->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('date', [$request->input('date_from'), $request->input('date_to')]);
        } elseif ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        } elseif ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');

            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('inspection_details', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('only_with_objections')) {
            $query->whereHas('objections', function ($objectionQuery) {
                $objectionQuery->whereIn('rfi_objections.status', RfiObjection::$activeStatuses);
            });
        }

        return $query;
    }

    private function canAccessDailyWork(User $user, DailyWork $dailyWork): bool
    {
        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        $userDesignationTitle = $this->getUserDesignationTitle($user);

        if ($userDesignationTitle === 'Supervision Engineer') {
            return (int) $dailyWork->incharge === (int) $user->id;
        }

        if (in_array($userDesignationTitle, ['Quality Control Inspector', 'Asst. Quality Control Inspector'], true)) {
            return (int) $dailyWork->assigned === (int) $user->id;
        }

        return (int) $dailyWork->incharge === (int) $user->id
            || (int) $dailyWork->assigned === (int) $user->id;
    }

    private function canSubmitObjection(User $user, RfiObjection $objection): bool
    {
        return (int) $objection->created_by === (int) $user->id || $this->isPrivilegedUser($user);
    }

    private function canViewObjectionFiles(User $user, DailyWork $dailyWork, RfiObjection $objection): bool
    {
        return $this->isPrivilegedUser($user)
            || (int) $objection->created_by === (int) $user->id
            || $this->canAccessDailyWork($user, $dailyWork);
    }

    private function canManageObjectionFiles(User $user, DailyWork $dailyWork, RfiObjection $objection): bool
    {
        if (! in_array($objection->status, [RfiObjection::STATUS_DRAFT, RfiObjection::STATUS_SUBMITTED], true)) {
            return false;
        }

        return $this->isPrivilegedUser($user)
            || (int) $objection->created_by === (int) $user->id
            || $this->canAccessDailyWork($user, $dailyWork);
    }

    private function canReviewObjection(User $user): bool
    {
        return $this->isPrivilegedUser($user);
    }

    private function findObjectionForDailyWork(int $dailyWorkId, int $objectionId): ?RfiObjection
    {
        return RfiObjection::query()
            ->with(['createdBy:id,name'])
            ->where('id', $objectionId)
            ->where(function ($objectionQuery) use ($dailyWorkId) {
                $objectionQuery->whereHas('dailyWorks', function ($dailyWorkQuery) use ($dailyWorkId) {
                    $dailyWorkQuery->where('daily_works.id', $dailyWorkId);
                });

                if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
                    $objectionQuery->orWhere('daily_work_id', $dailyWorkId);
                }
            })
            ->first();
    }

    private function isPrivilegedUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'Daily Work Manager',
            'HR Manager',
            'Project Manager',
            'Consultant',
            'Super Administrator',
            'Administrator',
        ]);
    }

    private function transformDailyWork(DailyWork $dailyWork, User $user): array
    {
        $canUpdateIncharge = $this->canUpdateIncharge($user);
        $canUpdateAssigned = $this->canUpdateAssigned($user, $dailyWork);
        $canUpdateStatus = $this->canUpdateStatus($user, $dailyWork);
        $canViewIncharge = $this->canViewIncharge($user);
        $canViewAssigned = $this->canViewAssigned($user, $dailyWork);

        $inchargeCandidates = $canUpdateIncharge ? $this->getInchargeCandidates() : [];

        if ($canUpdateIncharge && $dailyWork->inchargeUser) {
            $hasCurrentIncharge = collect($inchargeCandidates)->contains(function (array $candidate) use ($dailyWork): bool {
                return (int) $candidate['id'] === (int) $dailyWork->inchargeUser->id;
            });

            if (! $hasCurrentIncharge) {
                $inchargeCandidates[] = [
                    'id' => (int) $dailyWork->inchargeUser->id,
                    'name' => $dailyWork->inchargeUser->name,
                ];
            }
        }

        $assignedCandidates = $canUpdateAssigned
            ? $this->getAssigneeCandidatesForIncharge($dailyWork->incharge ? (int) $dailyWork->incharge : null)
            : [];

        if ($canUpdateAssigned && $dailyWork->assignedUser) {
            $hasCurrentAssigned = collect($assignedCandidates)->contains(function (array $candidate) use ($dailyWork): bool {
                return (int) $candidate['id'] === (int) $dailyWork->assignedUser->id;
            });

            if (! $hasCurrentAssigned) {
                $assignedCandidates[] = [
                    'id' => (int) $dailyWork->assignedUser->id,
                    'name' => $dailyWork->assignedUser->name,
                ];
            }
        }

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
            'assignment_options' => [
                'incharge_candidates' => $inchargeCandidates,
                'assigned_candidates' => $assignedCandidates,
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

    private function generateUniqueMediaFileName(mixed $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);

        return substr((string) $baseName, 0, 100).'_'.time().'_'.uniqid().'.'.$extension;
    }

    private function countObjectionFiles(RfiObjection $objection): int
    {
        return $objection->media()
            ->where('collection_name', 'objection_files')
            ->count();
    }

    private function canUpdateStatus(User $user, DailyWork $dailyWork): bool
    {
        return $this->canAccessDailyWork($user, $dailyWork);
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
        if (! Schema::hasColumn('users', 'designation_id') || ! Schema::hasTable('designations')) {
            return '';
        }

        if (! $user->relationLoaded('designation')) {
            $user->load('designation:id,title');
        }

        return trim((string) ($user->designation?->title ?? ''));
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
            ->where('active', true)
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
            ->where('active', true)
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
