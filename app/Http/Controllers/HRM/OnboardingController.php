<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\StoreOnboardingRequest;
use App\Http\Requests\HR\UpdateOnboardingRequest;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\HRM\Onboarding;
use App\Models\HRM\OnboardingTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * List onboarding records with filtering, stats, and pagination.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();

        $query = Onboarding::with([
            'employee' => fn ($q) => $q->withTrashed()->select('employee_id', 'name', 'department_id', 'designation_id', 'work_location_id'),
            'employee.department:id,name',
            'employee.designation:id,title',
            'creator:employee_id,name',
            'tasks.assignee:employee_id,name',
        ])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('employee_id', 'like', "%{$search}%")
                        ->orWhereHas('employee', fn ($eq) => $eq->withTrashed()->where('name', 'like', "%{$search}%"));
                });
            });

        // Department managers see their own department only
        if ($user->hasRole('Department Manager') && $user->department_id) {
            $query->whereHas('employee', fn ($eq) => $eq->withTrashed()->where('department_id', $user->department_id));
        }

        $onboardings = $query->orderByDesc('created_at')->paginate($request->input('per_page', 15));

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($onboardings);
        }

        // Stats summary for header cards
        $stats = [
            'total' => Onboarding::whereNotIn('status', [Onboarding::STATUS_CANCELLED])->count(),
            'in_progress' => Onboarding::where('status', Onboarding::STATUS_IN_PROGRESS)->count(),
            'pending' => Onboarding::where('status', Onboarding::STATUS_PENDING)->count(),
            'completed' => Onboarding::where('status', Onboarding::STATUS_COMPLETED)->count(),
        ];

        return Inertia::render('HR/Onboarding', [
            'title' => 'Employee Onboarding',
            'onboardings' => $onboardings,
            'stats' => $stats,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    /**
     * Show a single onboarding process with its full task checklist.
     */
    public function show(int $id): JsonResponse
    {
        $onboarding = Onboarding::with([
            'employee' => fn ($q) => $q->withTrashed()->select('employee_id', 'name', 'email', 'phone', 'department_id', 'designation_id', 'work_location_id', 'date_of_joining'),
            'employee.department:id,name',
            'employee.designation:id,title',
            'employee.workLocation:id,name',
            'tasks.assignee:employee_id,name',
            'creator:employee_id,name',
            'updater:employee_id,name',
        ])->findOrFail($id);

        $this->authorize('view', $onboarding);

        return response()->json($onboarding);
    }

    /**
     * Create a new onboarding process.
     */
    public function store(StoreOnboardingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tasks = $data['tasks'] ?? [];
        unset($data['tasks']);

        $employee = User::where('employee_id', $data['employee_id'])->firstOrFail();
        $data['employee_id'] = $employee->employee_id;

        // Check for duplicate active onboarding
        $existing = Onboarding::where('employee_id', $employee->employee_id)
            ->whereNotIn('status', [Onboarding::STATUS_COMPLETED, Onboarding::STATUS_CANCELLED])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'An active onboarding process already exists for this employee.',
                'existing_id' => $existing->id,
            ], 422);
        }

        $onboarding = DB::transaction(function () use ($data, $tasks, $employee) {
            $onboarding = Onboarding::create($data);

            if (empty($tasks)) {
                $tasks = $this->defaultInductionChecklist($onboarding);
            }

            foreach ($tasks as $task) {
                $onboarding->tasks()->create($task);
            }

            return $onboarding;
        });

        // Automatically queue biometric hardware provisioning
        $devicesQueued = $this->queueBiometricEnrollment($employee);

        return response()->json([
            'message' => 'Onboarding process initiated successfully.',
            'onboarding' => $onboarding->load(['employee.department', 'employee.designation', 'tasks']),
            'devices_queued' => $devicesQueued,
        ], 201);
    }

    /**
     * Update an onboarding process.
     */
    public function update(UpdateOnboardingRequest $request, int $id): JsonResponse
    {
        $onboarding = Onboarding::findOrFail($id);
        $this->authorize('update', $onboarding);

        $data = $request->validated();
        $tasks = $data['tasks'] ?? [];
        unset($data['tasks']);

        $onboarding->update($data);

        // Update tasks if provided
        foreach ($tasks as $taskData) {
            if (! empty($taskData['id'])) {
                $task = $onboarding->tasks()->find($taskData['id']);
                if ($task) {
                    $task->update($taskData);
                }
            } else {
                $onboarding->tasks()->create($taskData);
            }
        }

        // Auto-complete check
        if ($onboarding->isCompletable() && $onboarding->status !== Onboarding::STATUS_COMPLETED) {
            $onboarding->update([
                'status' => Onboarding::STATUS_COMPLETED,
                'actual_completion_date' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Onboarding updated successfully.',
            'onboarding' => $onboarding->fresh(['employee.department', 'employee.designation', 'tasks']),
        ]);
    }

    /**
     * Toggle or update a single onboarding task.
     */
    public function updateTask(Request $request, int $id, int $taskId): JsonResponse
    {
        $onboarding = Onboarding::findOrFail($id);
        $this->authorize('update', $onboarding);

        $task = $onboarding->tasks()->findOrFail($taskId);

        $request->validate([
            'status' => 'required|in:pending,in_progress,completed,not-applicable',
            'notes' => 'nullable|string',
            'completed_date' => 'nullable|date',
        ]);

        $status = $request->input('status');
        $completedDate = $status === OnboardingTask::STATUS_COMPLETED
            ? ($request->input('completed_date') ?? now()->toDateString())
            : null;

        $task->update([
            'status' => $status,
            'completed_date' => $completedDate,
            'notes' => $request->input('notes', $task->notes),
        ]);

        // If all tasks are finished, transition onboarding status to completed
        if ($onboarding->isCompletable() && $onboarding->status !== Onboarding::STATUS_COMPLETED) {
            $onboarding->update([
                'status' => Onboarding::STATUS_COMPLETED,
                'actual_completion_date' => now(),
            ]);
        } elseif (! $onboarding->isCompletable() && $onboarding->status === Onboarding::STATUS_COMPLETED) {
            $onboarding->update([
                'status' => Onboarding::STATUS_IN_PROGRESS,
                'actual_completion_date' => null,
            ]);
        } elseif ($onboarding->status === Onboarding::STATUS_PENDING && $status === OnboardingTask::STATUS_COMPLETED) {
            $onboarding->update(['status' => Onboarding::STATUS_IN_PROGRESS]);
        }

        return response()->json([
            'message' => 'Task updated.',
            'task' => $task,
            'onboarding' => $onboarding->fresh(['tasks', 'employee']),
        ]);
    }

    /**
     * Manually trigger/re-push biometric ADD_USER command to hardware terminals.
     */
    public function syncBiometric(int $id): JsonResponse
    {
        $onboarding = Onboarding::with('employee')->findOrFail($id);
        $this->authorize('update', $onboarding);

        $employee = $onboarding->employee;
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $queued = $this->queueBiometricEnrollment($employee);

        return response()->json([
            'message' => "Biometric enrollment command queued to {$queued} hardware devices.",
            'devices_queued' => $queued,
        ]);
    }

    /**
     * Soft-delete an onboarding process.
     */
    public function destroy(int $id): JsonResponse
    {
        $onboarding = Onboarding::findOrFail($id);
        $this->authorize('delete', $onboarding);

        $onboarding->delete();

        return response()->json(['message' => 'Onboarding process deleted successfully.']);
    }

    /**
     * List active employees who do not yet have an active or completed onboarding.
     */
    public function eligibleEmployees(): JsonResponse
    {
        $existingEmpIds = Onboarding::whereNotIn('status', [Onboarding::STATUS_CANCELLED])
            ->pluck('employee_id')
            ->toArray();

        $employees = User::whereNotIn('employee_id', $existingEmpIds)
            ->with(['department:id,name', 'designation:id,title'])
            ->select('employee_id', 'name', 'department_id', 'designation_id', 'date_of_joining')
            ->orderBy('name')
            ->get();

        return response()->json($employees);
    }

    /**
     * Dispatch biometric device ADD_USER command to relevant terminals.
     */
    public function queueBiometricEnrollment(User $employee): int
    {
        try {
            $deviceIds = $employee->resolvedBiometricDeviceIds();
            $devices = empty($deviceIds)
                ? BiometricDevice::where('is_active', true)->get()
                : BiometricDevice::whereIn('id', $deviceIds)->where('is_active', true)->get();

            $count = 0;
            foreach ($devices as $device) {
                BiometricDeviceCommand::create([
                    'biometric_device_id' => $device->id,
                    'command_type' => 'ADD_USER',
                    'payload' => [
                        'pin' => (string) $employee->employee_id,
                        'name' => (string) $employee->name,
                        'privilege' => 0,
                    ],
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                ]);
                $count++;
            }

            Log::info("OnboardingController: queued ADD_USER on {$count} devices for {$employee->employee_id} ({$employee->name})");

            return $count;
        } catch (\Throwable $e) {
            Log::error("OnboardingController: failed to queue biometric enrollment for {$employee->employee_id}", [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Default standard 8-task induction checklist for DBEDC Expressway operations.
     */
    private function defaultInductionChecklist(Onboarding $onboarding): array
    {
        $startDate = Carbon::parse($onboarding->start_date);
        $expectedDate = Carbon::parse($onboarding->expected_completion_date);

        return [
            [
                'task' => 'Statutory Compliance & Service Book (Form 7)',
                'description' => 'Bangladesh Labour Act 2006 Form 7 Service Book setup, nominee declaration form, and medical clearance certificate.',
                'due_date' => $startDate->copy()->addDays(2)->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
            [
                'task' => 'NID & Academic Certificate Verification',
                'description' => 'Physical verification and archival of national identity card, educational certificates, and background clearance.',
                'due_date' => $startDate->copy()->addDays(3)->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
            [
                'task' => 'Company ID Card & Email Setup',
                'description' => 'Issue official RFID access ID card, configure corporate email address (@dhakabypass.com), and generate ERP credentials.',
                'due_date' => $startDate->copy()->addDays(1)->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
            [
                'task' => 'Biometric Terminal Enrollment',
                'description' => 'Sync employee PIN to plaza/TMC biometric terminals and enroll fingerprints/facial recognition on physical device.',
                'due_date' => $startDate->copy()->addDays(1)->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
            [
                'task' => 'IT Assets & Corporate SIM Handover',
                'description' => 'Issue designated computer/laptop, official corporate SIM card, and peripheral accessories with serial record.',
                'due_date' => $startDate->copy()->addDays(2)->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
            [
                'task' => 'Uniform & Safety PPE Gear Issuance',
                'description' => 'Distribute high-visibility reflective safety jacket, safety shoes, hardhat, and expressway operations uniform.',
                'due_date' => $startDate->copy()->addDays(2)->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
            [
                'task' => 'Expressway Safety Induction & SOP Training',
                'description' => 'Complete highway safety induction, emergency traffic response protocols, and Traffic Monitoring Center SOP briefing.',
                'due_date' => $startDate->copy()->addDays(7)->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
            [
                'task' => 'Shift Pattern & Initial Roster Assignment',
                'description' => 'Assign shift rotation pattern and anchor date in attendance roster system to ensure scheduling coverage.',
                'due_date' => $expectedDate->toDateString(),
                'status' => OnboardingTask::STATUS_PENDING,
            ],
        ];
    }
}
