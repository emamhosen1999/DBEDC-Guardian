<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;

class ExportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'startDate' => 'nullable|date|before_or_equal:today',
            'endDate' => 'nullable|date|after_or_equal:startDate|before_or_equal:today',
            'format' => 'nullable|in:excel,pdf,csv',
            'columns' => 'nullable|array',
            'columns.*' => 'string|max:100',
            'filters' => 'nullable|array',
            'maxRecords' => 'nullable|integer|min:1|max:10000',
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
            'startDate.before_or_equal' => 'Start date must be today or in the past.',
            'endDate.after_or_equal' => 'End date must be on or after the start date.',
            'endDate.before_or_equal' => 'End date must be today or in the past.',
            'format.in' => 'Format must be one of: excel, pdf, csv.',
            'maxRecords.max' => 'Maximum records cannot exceed 10,000.',
        ];
    }
}
