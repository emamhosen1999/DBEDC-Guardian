<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDailyWorkStatusRequest extends FormRequest
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

        return $this->user()->can('updateStatus', $dailyWork);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:daily_works,id'],
            'status' => ['required', Rule::in(DailyWork::$statuses)],
            'inspection_result' => ['nullable', Rule::in(DailyWork::$inspectionResults)],
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
            'id.required' => 'Daily Work ID is required.',
            'id.exists' => 'Daily Work not found.',
            'status.required' => 'Status is required.',
            'status.in' => "Status must be one of: {$validStatuses}.",
            'inspection_result.in' => "Inspection result must be one of: {$validResults}.",
        ];
    }
}
