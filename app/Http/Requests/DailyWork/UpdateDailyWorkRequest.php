<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDailyWorkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dailyWork = DailyWork::find($this->input('id'));

        if (! $dailyWork) {
            return false;
        }

        return $this->user()->can('update', $dailyWork);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCompleted = $this->input('status') === DailyWork::STATUS_COMPLETED;
        $isEmbankment = $this->input('type') === 'Embankment';
        $currentId = $this->input('id');

        return [
            'id' => ['required', 'integer', 'exists:daily_works,id'],
            'date' => ['required', 'date'],
            'number' => [
                'required',
                'string',
                Rule::unique('daily_works', 'number')->ignore($currentId),
            ],
            'planned_time' => ['required', 'string', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'status' => ['required', Rule::in(DailyWork::$statuses)],
            'inspection_result' => [
                $isCompleted ? 'required' : 'nullable',
                Rule::in(DailyWork::$inspectionResults),
            ],
            'type' => ['required', 'string', Rule::in(DailyWork::$types)],
            'description' => ['required', 'string', 'min:10', 'max:500'],
            'location' => ['required', 'string', 'enhanced_location'],
            'side' => ['required', 'string', Rule::in(DailyWork::$sides)],
            'qty_layer' => $this->getQtyLayerValidation(),
            'completion_time' => $this->getCompletionTimeValidation(),
            'inspection_details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $validStatuses = implode(', ', DailyWork::$statuses);
        $validResults = implode(', ', DailyWork::$inspectionResults);
        $validTypes = implode(', ', DailyWork::$types);
        $validSides = implode(', ', DailyWork::$sides);

        return [
            'id.required' => 'Daily Work ID is required.',
            'id.integer' => 'Daily Work ID must be an integer.',
            'id.exists' => 'Daily Work not found.',
            'date.required' => 'RFI Date is required.',
            'number.required' => 'RFI Number is required.',
            'number.unique' => 'A daily work with this RFI number already exists.',
            'planned_time.required' => 'RFI Time is required.',
            'planned_time.regex' => 'RFI Time must be in HH:MM format (24-hour).',
            'status.required' => 'Status is required.',
            'status.in' => "Status must be one of: {$validStatuses}.",
            'inspection_result.required' => 'Inspection result is required for completed work.',
            'inspection_result.in' => "Inspection result must be one of: {$validResults}.",
            'type.required' => 'Type is required.',
            'type.in' => "Type must be one of: {$validTypes}.",
            'description.required' => 'Description is required.',
            'description.min' => 'Description must be at least 10 characters.',
            'description.max' => 'Description cannot exceed 500 characters.',
            'location.required' => 'Location is required.',
            'location.enhanced_location' => 'Location must be a valid chainage format (e.g., K14+500, K30-K35) within valid jurisdiction.',
            'side.required' => 'Road Type is required.',
            'side.in' => "Road Type must be one of: {$validSides}.",
            'qty_layer.required' => 'Layer No. is required for embankment work.',
            'qty_layer.regex' => 'Layer No. must be a valid number.',
            'completion_time.required' => 'Completion time is required when status is completed.',
            'completion_time.regex' => 'Completion time must be in HH:MM format (24-hour).',
            'completion_time.after' => 'Completion time must be after planned time.',
            'inspection_details.max' => 'Inspection details cannot exceed 1000 characters.',
        ];
    }

    /**
     * Get qty_layer validation rule based on work type
     */
    private function getQtyLayerValidation(): array
    {
        $type = $this->input('type');

        if ($type === DailyWork::TYPE_EMBANKMENT) {
            return ['required', 'string', 'regex:/^[0-9]+(\.[0-9]+)?$/'];
        }

        if ($type === DailyWork::TYPE_PAVEMENT) {
            return ['nullable', 'string', 'regex:/^[0-9]+(\.[0-9]+)?$/'];
        }

        return ['nullable', 'string'];
    }

    /**
     * Get completion time validation rule
     */
    private function getCompletionTimeValidation(): array
    {
        $status = $this->input('status');

        if ($status === DailyWork::STATUS_COMPLETED) {
            return ['required', 'string', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', 'after:planned_time'];
        }

        return ['nullable', 'string', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/'];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateCrossFieldRules($validator);
        });
    }

    /**
     * Perform cross-field validation rules
     */
    private function validateCrossFieldRules($validator): void
    {
        $data = $this->all();
        $errors = [];

        // Validate embankment work requirements
        if (($data['type'] ?? null) === DailyWork::TYPE_EMBANKMENT) {
            if (empty($data['qty_layer'])) {
                $errors['qty_layer'] = 'Layer number is required for embankment work.';
            }

            // Check if side is appropriate for embankment work
            if (!in_array($data['side'] ?? null, ['TR-R', 'TR-L', 'Both'])) {
                $errors['side'] = 'Embankment work must be on TR-R, TR-L, or Both sides.';
            }
        }

        // Validate pavement work requirements
        if (($data['type'] ?? null) === DailyWork::TYPE_PAVEMENT) {
            // Pavement work typically requires specific chainage ranges
            if (!preg_match('/K\d+\+/', $data['location'] ?? '')) {
                $errors['location'] = 'Pavement work location should include specific chainage (e.g., K14+500).';
            }
        }

        // Validate structure work requirements
        if (($data['type'] ?? null) === DailyWork::TYPE_STRUCTURE) {
            // Structure work should have specific location patterns
            if (!preg_match('/K\d+/', $data['location'] ?? '')) {
                $errors['location'] = 'Structure work location should specify kilometer (e.g., K25).';
            }
        }

        // Validate time logic
        if (!empty($data['planned_time']) && !empty($data['completion_time'])) {
            $plannedTime = strtotime($data['planned_time']);
            $completionTime = strtotime($data['completion_time']);

            if ($completionTime < $plannedTime) {
                $errors['completion_time'] = 'Completion time cannot be before planned time.';
            }
        }

        foreach ($errors as $field => $message) {
            $validator->errors()->add($field, $message);
        }
    }
}
