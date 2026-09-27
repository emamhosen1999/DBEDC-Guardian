<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\StoreOffboardingRequest;
use App\Http\Requests\HR\UpdateOffboardingRequest;
use App\Jobs\ProcessOffboardingLwd;
use App\Models\HRM\Offboarding;
use App\Models\HRM\OffboardingTask;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OffboardingController extends Controller
{
    /**
     * List offboardings with filtering and pagination.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();

        $query = Offboarding::with(['employee:employee_id,name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,name', 'creator:employee_id,name', 'tasks'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('reason'), fn ($q, $reason) => $q->where('reason', $reason))
            ->when($request->input('search'), function ($q, $search) {
                $q->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$search}%")->orWhere('employee_id', 'like', "%{$search}%"));
            });

        // Department managers see their own department only
        if ($user->hasRole('Department Manager') && $user->department_id) {
            $query->whereHas('employee', fn ($eq) => $eq->where('department_id', $user->department_id));
        }

        $offboardings = $query->orderByDesc('created_at')->paginate($request->input('per_page', 15));

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($offboardings);
        }

        // Stats summary for header cards
        $stats = [
            'total' => Offboarding::whereNotIn('status', [Offboarding::STATUS_CANCELLED])->count(),
            'in_progress' => Offboarding::where('status', Offboarding::STATUS_IN_PROGRESS)->count(),
            'absconded' => Offboarding::where('reason', Offboarding::REASON_ABSCONDED)->count(),
            'completed' => Offboarding::where('status', Offboarding::STATUS_COMPLETED)->count(),
            'active_cases' => \App\Models\HRM\AbsenceCase::whereIn('stage', [
                \App\Models\HRM\AbsenceCase::STAGE_MONITORING,
                \App\Models\HRM\AbsenceCase::STAGE_NOTICE_SENT,
                \App\Models\HRM\AbsenceCase::STAGE_SHOW_CAUSE,
            ])->count(),
        ];

        // Active absence cases for the tab
        $absenceCases = \App\Models\HRM\AbsenceCase::with(['employee:employee_id,name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,name'])
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
            'employee.designation:id,name',
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
    public function store(StoreOffboardingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tasks = $data['tasks'] ?? [];
        unset($data['tasks']);

        // Resolve employee
        $employee = User::where('employee_id', $data['employee_id'])->firstOrFail();
        $data['employee_id'] = $employee->employee_id;

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

        // Dispatch the LWD processing job
        ProcessOffboardingLwd::dispatch($offboarding)->afterCommit();

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

        DB::transaction(function () use ($offboarding, $data, $tasks) {
            $offboarding->update($data);

            // Sync tasks: update existing, create new, delete removed
            $incomingIds = collect($tasks)->pluck('id')->filter()->all();
            $offboarding->tasks()->whereNotIn('id', $incomingIds)->delete();

            foreach ($tasks as $taskData) {
                if (! empty($taskData['id'])) {
                    OffboardingTask::where('id', $taskData['id'])->update($taskData);
                } else {
                    $offboarding->tasks()->create($taskData);
                }
            }
        });

        // Re-dispatch LWD job if the date changed
        if ($lwdChanged) {
            ProcessOffboardingLwd::dispatch($offboarding->fresh())->afterCommit();
        }

        return response()->json($offboarding->fresh()->load('tasks'));
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

        $employees = User::whereNull('deleted_at')
            ->whereNotIn('id', $activeEmployeeIds)
            ->select('employee_id', 'name', 'department_id')
            ->with('department:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->limit(50)
            ->get();

        return response()->json($employees);
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

        // If all tasks are completed, check if offboarding can be marked completed
        $pendingCount = $offboarding->tasks()
            ->whereNotIn('status', [OffboardingTask::STATUS_COMPLETED, OffboardingTask::STATUS_NOT_APPLICABLE])
            ->count();

        if ($pendingCount === 0 && $offboarding->status === Offboarding::STATUS_IN_PROGRESS) {
            $offboarding->update(['status' => Offboarding::STATUS_COMPLETED]);
        }

        return response()->json([
            'task' => $task->fresh(),
            'offboarding' => $offboarding->fresh()->load('tasks'),
        ]);
    }

    /**
     * List absence cases with filtering.
     */
    public function absenceCases(Request $request): JsonResponse
    {
        $cases = \App\Models\HRM\AbsenceCase::with(['employee:employee_id,name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,name'])
            ->when($request->input('stage'), fn ($q, $stage) => $q->where('stage', $stage))
            ->orderByDesc('streak_days')
            ->paginate($request->input('per_page', 25));

        return response()->json($cases);
    }

    /**
     * Generate official return-to-work or show-cause notice letter.
     */
    public function generateNotice(int $caseId, string $type, \App\Services\Attendance\AbsenceNoticeService $noticeService): JsonResponse
    {
        $case = \App\Models\HRM\AbsenceCase::with(['employee.department', 'employee.designation'])->findOrFail($caseId);

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
    public function resolveAbsenceCase(Request $request, int $caseId): JsonResponse
    {
        $case = \App\Models\HRM\AbsenceCase::findOrFail($caseId);

        $data = $request->validate([
            'action' => 'required|in:regularize,lwp,abscond',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = User::where('employee_id', $case->user_id)->firstOrFail();

        if ($data['action'] === 'abscond') {
            $existing = Offboarding::where('employee_id', $user->id)
                ->whereNotIn('status', [Offboarding::STATUS_CANCELLED])
                ->first();

            if (! $existing) {
                $offboarding = Offboarding::create([
                    'employee_id' => $user->id,
                    'initiation_date' => now()->toDateString(),
                    'last_working_date' => $case->first_absent_date ?? now()->subDay()->toDateString(),
                    'reason' => Offboarding::REASON_ABSCONDED,
                    'status' => Offboarding::STATUS_IN_PROGRESS,
                    'notes' => $data['notes'] ?? 'Auto-converted from prolonged absence case #'.$case->id,
                    'created_by' => $request->user()->id,
                ]);

                // Seed checklist tasks
                foreach ($this->defaultClearanceChecklist($offboarding) as $task) {
                    $offboarding->tasks()->create($task);
                }

                ProcessOffboardingLwd::dispatch($offboarding)->afterCommit();

                $case->offboarding_id = $offboarding->id;
            }

            $case->stage = \App\Models\HRM\AbsenceCase::STAGE_ABSCONDED;
            $case->outcome = \App\Models\HRM\AbsenceCase::OUTCOME_ABSCONDED;
            $case->addTimelineEntry('Converted to Absconded Offboarding', $data['notes'] ?? null);
            $case->save();

            return response()->json([
                'message' => 'Employee marked as absconded and offboarding initiated.',
                'case' => $case,
            ]);
        }

        // Return to work (regularize or LWP)
        $case->stage = \App\Models\HRM\AbsenceCase::STAGE_RETURNED;
        $case->outcome = $data['action'] === 'regularize'
            ? \App\Models\HRM\AbsenceCase::OUTCOME_REGULARIZED
            : \App\Models\HRM\AbsenceCase::OUTCOME_LWP;
        $case->addTimelineEntry("Case resolved: {$data['action']}", $data['notes'] ?? null);
        $case->save();

        return response()->json([
            'message' => 'Absence case closed.',
            'case' => $case,
        ]);
    }
}
