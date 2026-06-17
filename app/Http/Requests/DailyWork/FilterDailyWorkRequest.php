<?php

namespace App\Http\Requests\DailyWork;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FilterDailyWorkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('daily-works.view');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'status' => ['nullable', 'string'],
            'inCharge' => ['nullable'],
            'incharge' => ['nullable'],
            'jurisdiction' => ['nullable'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endDate.after_or_equal' => 'End date must be after or equal to start date.',
            'perPage.max' => 'Maximum items per page is 2000.',
            'search.max' => 'Search term cannot exceed 255 characters.',
        ];
    }
}
