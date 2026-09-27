<?php

namespace App\Http\Requests\HR;

use App\Models\HRM\Offboarding;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOffboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $offboarding = $this->route('id') ? Offboarding::find($this->route('id')) : null;
        if (! $offboarding) {
            return false;
        }

        return $this->user()?->can('hr.offboarding.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'initiation_date' => 'required|date',
            'last_working_date' => 'required|date',
            'exit_interview_date' => 'nullable|date',
            'resignation_received_at' => 'nullable|date',
            'notice_days_required' => 'nullable|integer|min:0',
            'notice_shortfall_days' => 'nullable|integer|min:0',
            'reason' => 'required|string|in:resignation,termination,retirement,end-of-contract,end_contract,absconded,resignation_without_notice,other',
            'status' => 'required|in:pending,in_progress,completed,cancelled',
            'notes' => 'nullable|string',
            'tasks' => 'array',
            'tasks.*.id' => 'nullable|exists:offboarding_tasks,id',
            'tasks.*.task' => 'required|string',
            'tasks.*.description' => 'nullable|string',
            'tasks.*.due_date' => 'nullable|date',
            'tasks.*.completed_date' => 'nullable|date',
            'tasks.*.status' => 'required|in:pending,in_progress,completed,not-applicable',
            'tasks.*.assigned_to' => 'nullable|exists:users,employee_id',
            'tasks.*.notes' => 'nullable|string',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('tasks')) {
            $this->merge(['tasks' => []]);
        }
    }
}
