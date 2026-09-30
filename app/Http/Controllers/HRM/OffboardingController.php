<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\StoreOffboardingRequest;
use App\Http\Requests\HR\UpdateOffboardingRequest;
use App\Jobs\ProcessOffboardingLwd;
use App\Models\HRM\AbsenceCase;
use App\Models\HRM\Offboarding;
use App\Models\HRM\OffboardingTask;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Attendance\AbsenceNoticeService;
use App\Services\HR\OffboardingInitiationNotifier;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OffboardingController extends Controller
{
    /** Task attributes a client may write (mirrors the FormRequest rules). */
    private const TASK_FIELDS = ['task', 'description', 'due_date', 'completed_date', 'status', 'assigned_to', 'notes'];

    public function __construct(private readonly DepartmentScope $scope) {}

    /**
     * List offboardings with filtering and pagination.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();

        $query = Offboarding::with(['employee:employee_id,name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,title', 'creator:employee_id,name', 'tasks'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('reason'), fn ($q, $reason) => $q->where('reason', $reason))
            ->when($request->input('search'), function ($q, $search) {
                $q->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$search}%")->orWhere('employee_id', 'like', "%{$search}%"));
            });

        // Department scope: managed departments, reporting sub-tree and self (global roles: everyone).
        $this->scope->applyToEmployeeOwned($query, $user);

        $offboardings = $query->orderByDesc('created_at')->paginate($request->input('per_page', 15));

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($offboardings);
        }

        // Stats summary for header cards — same scope as the list.
        $scoped = fn () => $this->scope->applyToEmployeeOwned(Offboarding::query(), $user);
        $stats = [
            'total' => $scoped()->whereNotIn('status', [Offboarding::STATUS_CANCELLED])->count(),
            'in_progress' => $scoped()->where('status', Offboarding::STATUS_IN_PROGRESS)->count(),
            'absconded' => $scoped()->where('reason', Offboarding::REASON_ABSCONDED)->count(),
            'completed' => $scoped()->where('status', Offboarding::STATUS_COMPLETED)->count(),
            'active_cases' => $this->scope->applyToEmployeeOwned(AbsenceCase::query(), $user, 'user_id')
                ->whereIn('stage', [
                    AbsenceCase::STAGE_MONITORING,
                    AbsenceCase::STAGE_NOTICE_SENT,
                    AbsenceCase::STAGE_SHOW_CAUSE,
                ])->count(),
        ];

        // Active absence cases for the tab
        $absenceCases = $this->scope->applyToEmployeeOwned(
            AbsenceCase::with(['employee:employee_id,name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,title']),
            $user,
            'user_id',
        )
            ->orderByDesc('streak_days')
            ->limit(50)
            ->get();

        return Inertia::render('HR/Offboarding', [
            'title' => 'Employee Offboarding',
            'offboardings' => $offboardings,
            'stats' => $stats,
            'absenceCases' => $absenceCases,
            'filters' => $request->only(['status', 'reason', 'search']),
        ]);
    }

    /**
     * Show a single offboarding with tasks.
     */
    public function show(int $id): JsonResponse
    {
        $offboarding = Offboarding::with([
            'employee:employee_id,name,department_id,designation_id,work_location_id',
            'employee.department:id,name',
            'employee.designation:id,title',
            'tasks.assignee:employee_id,name',
            'creator:employee_id,name',
            'updater:employee_id,name',
        ])->findOrFail($id);

        $this->authorize('view', $offboarding);

        return response()->json($offboarding);
    }

    /**
     * Create a new offboarding process.
     */
    public function store(StoreOffboardingRequest $request, OffboardingInitiationNotifier $notifier): JsonResponse
    {
        $data = $request->validated();
        $tasks = $data['tasks'] ?? [];
        unset($data['tasks']);

        // Resolve employee
        $employee = User::where('employee_id', $data['employee_id'])->firstOrFail();
        $data['employee_id'] = $employee->employee_id;

        // Nobody offboards themselves through the admin flow; the target must be in the
        // actor's scope and (for non-global actors) outranked by them.
        abort_if($employee->employee_id === $request->user()->employee_id, 403, 'You cannot initiate your own offboarding.');
        abort_unless($this->scope->canManage($request->user(), $employee), 403, 'You cannot offboard this employee.');

        // Check for duplicate active offboarding
        $existing = Offboarding::where('employee_id', $employee->employee_id)
            ->whereNotIn('status', [Offboarding::STATUS_COMPLETED, Offboarding::STATUS_CANCELLED])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'An active offboarding process already exists for this employee.',
                'existing_id' => $existing->id,
            ], 422);
        }

        $offboarding = DB::transaction(function () use ($data, $tasks) {
            $offboarding = Offboarding::create($data);

            // Seed default clearance checklist if no tasks provided
            if (empty($tasks)) {
                $tasks = $this->defaultClearanceChecklist($offboarding);
            }

            foreach ($tasks as $task) {
                $offboarding->tasks()->create($task);
            }

            return $offboarding;
        });

        // LWD effects (access revocation, biometric removal) are queued for the
        // end of the last working day — never on day 1 of the notice period.
        ProcessOffboardingLwd::dispatchFor($offboarding);

        $notifier->send($offboarding, $employee);

        Log::info('Offboarding initiated', [
            'offboarding_id' => $offboarding->id,
            'employee_id' => $offboarding->employee_id,
            'reason' => $offboarding->reason,
            'lwd' => $offboarding->last_working_date->toDateString(),
        ]);

        return response()->json(
            $offboarding->load('tasks'),
            201
        );
    }

    /**
     * Update an existing offboarding.
     */
    public function update(UpdateOffboardingRequest $request, int $id): JsonResponse
    {
        $offboarding = Offboarding::findOrFail($id);
        $this->authorize('update', $offboarding);

        $data = $request->validated();
        $tasks = $data['tasks'] ?? [];
        unset($data['tasks']);

        $lwdChanged = isset($data['last_working_date'])
            && $offboarding->last_working_date?->toDateString() !== $data['last_working_date'];

        $previousStatus = $offboarding->status;
        $requestedStatus = $data['status'] ?? null;
        unset($data['status']);

        if ($lwdChanged) {
            // New LWD: the LWD effects must be (re)run for the new date.
            $data['lwd_processed_at'] = null;
        }

        DB::transaction(function () use ($offboarding, $data, $tasks, $requestedStatus, $previousStatus) {
            $offboarding->update($data);

            // Sync tasks: update existing, create new, delete removed
            $incomingIds = collect($tasks)->pluck('id')->filter()->all();
            $offboarding->tasks()->whereNotIn('id', $incomingIds)->delete();

            foreach ($tasks as $taskData) {
                $fields = Arr::only($taskData, self::TASK_FIELDS);

                if (! empty($taskData['id'])) {
                    // Scoped to this offboarding — a task id from another process must not be writable.
                    $task = $offboarding->tasks()->whereKey($taskData['id'])->first();
                    if (! $task) {
                        throw ValidationException::withMessages([
                            'tasks' => 'A submitted task does not belong to this offboarding.',
                        ]);
                    }
                    $task->update($fields);
                } else {
                    $offboarding->tasks()->create($fields);
                }
            }

            if ($requestedStatus === null) {
                return;
            }

            $completing = $requestedStatus === Offboarding::STATUS_COMPLETED
                && $previousStatus !== Offboarding::STATUS_COMPLETED;

            if ($completing) {
                $blockers = $offboarding->completionBlockers();
                if ($blockers) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Offboarding cannot be completed yet.',
                        'completion_blockers' => $blockers,
                    ], 422));
                }
            }

            $offboarding->update(['status' => $requestedStatus]);

            if ($completing) {
                $this->finalizeCompletion($offboarding);
            } elseif ($requestedStatus !== Offboarding::STATUS_COMPLETED && $previousStatus === Offboarding::STATUS_COMPLETED) {
                // Reopened: bring the employee back from former-employee state.
                User::withTrashed()->where('employee_id', $offboarding->employee_id)->restore();
            }
        });

        // LWD changed: queue the effects for the new date (a stale earlier job exits harmlessly).
        if ($lwdChanged) {
            ProcessOffboardingLwd::dispatchFor($offboarding->fresh());
        }

        $fresh = $offboarding->fresh()->load('tasks');

        return response()->json(array_merge($fresh->toArray(), [
            'completion_blockers' => $fresh->status === Offboarding::STATUS_COMPLETED ? [] : $fresh->completionBlockers(),
        ]));
    }

    /**
     * Completing an offboarding: make sure LWD access revocation has run, then
     * move the employee to former-employee (soft-delete). Callers must have
     * verified completionBlockers() is empty (which implies the LWD has passed).
     */
    private function finalizeCompletion(Offboarding $offboarding): void
    {
        if (! $offboarding->fresh()->lwd_processed_at) {
            ProcessOffboardingLwd::dispatchSync($offboarding);
        }

        User::where('employee_id', $offboarding->employee_id)->first()?->delete();
    }

    /**
     * Cancel (soft-delete sense) an offboarding.
     */
    public function destroy(int $id): JsonResponse
    {
        $offboarding = Offboarding::findOrFail($id);
        $this->authorize('delete', $offboarding);

        $offboarding->update(['status' => Offboarding::STATUS_CANCELLED]);

        Log::info('Offboarding cancelled', ['offboarding_id' => $id]);

        return response()->json(['message' => 'Offboarding cancelled.']);
    }

    /**
     * Get employees eligible for offboarding (not already in an active process).
     */
    public function eligibleEmployees(Request $request): JsonResponse
    {
        $activeEmployeeIds = Offboarding::whereNotIn('status', [
            Offboarding::STATUS_COMPLETED,
            Offboarding::STATUS_CANCELLED,
        ])->pluck('employee_id');

        $actor = $request->user();

        $query = User::whereNull('users.deleted_at')
            ->whereNotIn('employee_id', $activeEmployeeIds)
            ->where('employee_id', '!=', $actor->employee_id)
            ->select('employee_id', 'name', 'department_id')
            ->with('department:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"));

        $this->scope->applyToUsers($query, $actor);

        return response()->json($query->limit(50)->get());
    }

    /**
     * Default clearance checklist seeded into every new offboarding.
     */
    private function defaultClearanceChecklist(Offboarding $offboarding): array
    {
        $lwd = $offboarding->last_working_date;

        return [
            ['task' => 'Return company assets (laptop, phone, keys, ID card)', 'due_date' => $lwd?->toDateString()],
            ['task' => 'Revoke system access & email accounts', 'due_date' => $lwd?->toDateString()],
            ['task' => 'Remove biometric enrollment from all devices', 'due_date' => $lwd?->toDateString()],
            ['task' => 'Handover pending work & documentation', 'due_date' => $lwd?->copy()->subDays(2)?->toDateString()],
            ['task' => 'Settle pending leave balance', 'due_date' => $lwd?->toDateString()],
            ['task' => 'Clear financial obligations (advances, loans)', 'due_date' => $lwd?->toDateString()],
            ['task' => 'Conduct exit interview', 'due_date' => $lwd?->toDateString()],
            ['task' => 'Issue experience / relieving letter', 'due_date' => $lwd?->copy()->addDays(7)?->toDateString()],
        ];
    }

    /**
     * Update status or notes of an individual clearance checklist task.
     */
    public function updateTask(Request $request, int $id, int $taskId): JsonResponse
    {
        $offboarding = Offboarding::findOrFail($id);
        $this->authorize('update', $offboarding);

        $task = $offboarding->tasks()->findOrFail($taskId);

        $data = $request->validate([
            'status' => 'required|in:pending,in_progress,completed,not-applicable',
            'notes' => 'nullable|string|max:500',
            'completed_date' => 'nullable|date',
        ]);

        if ($data['status'] === OffboardingTask::STATUS_COMPLETED && empty($data['completed_date'])) {
            $data['completed_date'] = now()->toDateString();
        }

        $task->update($data);

        // Ticking the last task only auto-completes when nothing else blocks it
        // (LWD reached, assets returned, F&F paid). Otherwise it stays in_progress.
        $blockers = $offboarding->completionBlockers();

        if ($offboarding->status === Offboarding::STATUS_IN_PROGRESS && empty($blockers)) {
            DB::transaction(function () use ($offboarding) {
                $offboarding->update(['status' => Offboarding::STATUS_COMPLETED]);
                $this->finalizeCompletion($offboarding);
            });
        }

        $fresh = $offboarding->fresh();

        return response()->json([
            'task' => $task->fresh(),
            'offboarding' => $fresh->load('tasks'),
            'completion_blockers' => $fresh->status === Offboarding::STATUS_COMPLETED ? [] : $blockers,
        ]);
    }

    /**
     * List absence cases with filtering.
     */
    public function absenceCases(Request $request): JsonResponse
    {
        $cases = $this->scope->applyToEmployeeOwned(
            AbsenceCase::with(['employee:employee_id,name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,title']),
            $request->user(),
            'user_id',
        )
            ->when($request->input('stage'), fn ($q, $stage) => $q->where('stage', $stage))
            ->orderByDesc('streak_days')
            ->paginate($request->input('per_page', 25));

        return response()->json($cases);
    }

    /**
     * Generate official return-to-work or show-cause notice letter.
     */
    public function generateNotice(Request $request, int $caseId, string $type, AbsenceNoticeService $noticeService): JsonResponse
    {
        $case = AbsenceCase::with(['employee.department', 'employee.designation'])->findOrFail($caseId);
        abort_unless($this->scope->canManage($request->user(), $case->user_id), 403, 'This absence case is outside your scope.');

        $notice = match ($type) {
            'return_to_work' => $noticeService->generateReturnToWorkNotice($case),
            'show_cause' => $noticeService->generateShowCauseNotice($case),
            default => abort(400, 'Invalid notice type'),
        };

        // Record notice sent in timeline
        $case->notices_sent = ($case->notices_sent ?? 0) + 1;
        $case->addTimelineEntry("Generated {$notice['subject']}", 'Notice generated on '.now()->toDateTimeString());
        $case->save();

        return response()->json($notice);
    }

    /**
     * Resolve an absence case: return to work (regularize/LWP) or convert to absconded offboarding.
     */
    public function resolveAbsenceCase(Request $request, int $caseId, OffboardingInitiationNotifier $notifier): JsonResponse
    {
        $case = AbsenceCase::findOrFail($caseId);
        abort_unless($this->scope->canManage($request->user(), $case->user_id), 403, 'This absence case is outside your scope.');

        $data = $request->validate([
            'action' => 'required|in:regularize,lwp,abscond',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = User::where('employee_id', $case->user_id)->firstOrFail();

        if ($data['action'] === 'abscond') {
            $existing = Offboarding::where('employee_id', $user->employee_id)
                ->whereNotIn('status', [Offboarding::STATUS_CANCELLED])
                ->first();

            if (! $existing) {
                $offboarding = Offboarding::create([
                    'employee_id' => $user->employee_id,
                    'initiation_date' => now()->toDateString(),
                    'last_working_date' => $case->first_absent_date ?? now()->subDay()->toDateString(),
                    'reason' => Offboarding::REASON_ABSCONDED,
                    'status' => Offboarding::STATUS_IN_PROGRESS,
                    'notes' => $data['notes'] ?? 'Auto-converted from prolonged absence case #'.$case->id,
                    'created_by' => $request->user()->employee_id,
                ]);

                // Seed checklist tasks
                foreach ($this->defaultClearanceChecklist($offboarding) as $task) {
                    $offboarding->tasks()->create($task);
                }

                ProcessOffboardingLwd::dispatchFor($offboarding);

                $notifier->send($offboarding, $user);

                $case->offboarding_id = $offboarding->id;
            }

            $case->stage = AbsenceCase::STAGE_ABSCONDED;
            $case->outcome = AbsenceCase::OUTCOME_ABSCONDED;
            $case->addTimelineEntry('Converted to Absconded Offboarding', $data['notes'] ?? null);
            $case->save();

            return response()->json([
                'message' => 'Employee marked as absconded and offboarding initiated.',
                'case' => $case,
            ]);
        }

        // Return to work (regularize or LWP)
        $case->stage = AbsenceCase::STAGE_RETURNED;
        $case->outcome = $data['action'] === 'regularize'
            ? AbsenceCase::OUTCOME_REGULARIZED
            : AbsenceCase::OUTCOME_LWP;
        $case->addTimelineEntry("Case resolved: {$data['action']}", $data['notes'] ?? null);
        $case->save();

        return response()->json([
            'message' => 'Absence case closed.',
            'case' => $case,
        ]);
    }
}
