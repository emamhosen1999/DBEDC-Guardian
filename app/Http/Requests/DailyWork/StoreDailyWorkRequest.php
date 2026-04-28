<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDailyWorkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', DailyWork::class);
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

        return [
            'date' => ['required', 'date'],
            'number' => ['required', 'string', 'unique:daily_works,number'],
            'time' => ['required', 'string'],
            'status' => ['required', Rule::in(DailyWork::$statuses)],
            'inspection_result' => [
                $isCompleted ? 'required' : 'nullable',
                Rule::in(DailyWork::$inspectionResults),
            ],
            'type' => ['required', 'string', Rule::in(['Embankment', 'Structure', 'Pavement'])],
            'description' => ['required', 'string'],
            'location' => ['required', 'string', 'custom_location'],
            'side' => ['required', 'string'],
            'qty_layer' => [$isEmbankment ? 'required' : 'nullable', 'string'],
            'completion_time' => [$isCompleted ? 'required' : 'nullable', 'string'],
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

        return [
            'date.required' => 'RFI Date is required.',
            'number.required' => 'RFI Number is required.',
            'number.unique' => 'A daily work with this RFI number already exists.',
            'time.required' => 'RFI Time is required.',
            'status.required' => 'Status is required.',
            'status.in' => "Status must be one of: {$validStatuses}.",
            'inspection_result.required' => 'Inspection result (Pass/Fail) is required for completed work.',
            'inspection_result.in' => "Inspection result must be one of: {$validResults}.",
            'type.required' => 'Type is required.',
            'type.in' => 'Type must be either Embankment, Structure, or Pavement.',
            'description.required' => 'Description is required.',
            'location.required' => 'Location is required.',
            'location.custom_location' => 'The location must start with \'K\' and be in the range K0 to K48.',
            'side.required' => 'Road Type is required.',
            'qty_layer.required' => 'Layer No. is required when the type is Embankment.',
            'completion_time.required' => 'Completion time is required when status is completed.',
            'inspection_details.max' => 'Inspection details cannot exceed 1000 characters.',
        ];
    }
}
