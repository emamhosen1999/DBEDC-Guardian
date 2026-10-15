<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use App\Rules\EmployeeInViewerScope;
use Illuminate\Validation\Rule;

/**
 * The ONE definition of what a daily work must look like when it is created or edited. The Form Requests (the web and the
 * API entry points) and DailyWorkValidationService (kept for the callers that validate a bare request) all read it here,
 * so the rules can never drift apart. Location is checked by the chainage service through `custom_location`
 * (a readable chainage that a jurisdiction covers on the work's date).
 */
final class DailyWorkRules
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, mixed>|string>
     */
    public static function fields(array $input, bool $updating): array
    {
        $completed = ($input['status'] ?? null) === DailyWork::STATUS_COMPLETED;
        $embankment = ($input['type'] ?? null) === DailyWork::TYPE_EMBANKMENT;

        $rules = [
            'date' => ['required', 'date'],
            'number' => ['required', 'string', 'max:255'],
            'planned_time' => ['required', 'string'],
            'status' => ['required', Rule::in(DailyWork::$statuses)],
            'inspection_result' => [$completed ? 'required' : 'nullable', Rule::in(DailyWork::$inspectionResults)],
            'type' => ['required', Rule::in(DailyWork::$types)],
            'description' => ['required', 'string'],
            'location' => ['required', 'string', 'custom_location'],
            'side' => ['required', Rule::in(DailyWork::$sides)],
            'qty_layer' => [$embankment ? 'required' : 'nullable', 'string'],
            'completion_time' => [$completed ? 'required' : 'nullable', 'string'],
            'inspection_details' => ['nullable', 'string', 'max:1000'],
            // Optional on the form: the assignee. Changing it is gated on daily-works.assign by the controller.
            'assigned' => ['nullable', Rule::exists('users', 'employee_id')->where('is_active', true)->whereNull('deleted_at'), new EmployeeInViewerScope(auth()->user(), $updating ? DailyWork::query()->whereKey(request()->input('id'))->value('assigned') : null, 'assignee')],
        ];
        if ($updating) {
            $rules = ['id' => ['required', 'integer', 'exists:daily_works,id'], 'lock_version' => ['required', 'integer', 'min:0']] + $rules;
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        $statuses = implode(', ', DailyWork::$statuses);
        $results = implode(', ', DailyWork::$inspectionResults);

        return [
            'id.required' => 'Daily Work ID is required.',
            'id.integer' => 'Daily Work ID must be an integer.',
            'id.exists' => 'Daily Work not found.',
            'date.required' => 'RFI Date is required.',
            'number.required' => 'RFI Number is required.',
            'planned_time.required' => 'RFI Time is required.',
            'planned_time.string' => 'RFI Time must be a string.',
            'status.required' => 'Status is required.',
            'status.in' => "Status must be one of: {$statuses}.",
            'inspection_result.required' => 'Inspection result is required for completed work.',
            'inspection_result.in' => "Inspection result must be one of: {$results}.",
            'type.required' => 'Type is required.',
            'type.in' => 'Type must be one of: '.implode(', ', DailyWork::$types).'.',
            'description.required' => 'Description is required.',
            'location.required' => 'Location is required.',
            'location.custom_location' => 'The :attribute must start with \'K\' and be in the range K0 to K48.',
            'side.required' => 'Road Type is required.',
            'side.in' => 'Road Type must be one of: '.implode(', ', DailyWork::$sides).'.',
            'qty_layer.required' => 'Layer No. is required when the type is Embankment.',
            'completion_time.required' => 'Completion time is required when status is completed.',
            'inspection_details.max' => 'Inspection details cannot exceed 1000 characters.',
            'assigned.exists' => 'The assignee must be an active employee.',
        ];
    }
}
