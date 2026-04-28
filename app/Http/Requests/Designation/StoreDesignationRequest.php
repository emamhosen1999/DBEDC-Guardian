<?php

namespace App\Http\Requests\Designation;

use Illuminate\Foundation\Http\FormRequest;

class StoreDesignationRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'department_id' => 'nullable|integer|exists:departments,id',
            'parent_id' => 'nullable|integer|exists:designations,id',
            'description' => 'nullable|string|max:1000',
            'hierarchy_level' => 'nullable|integer|min:0|max:10',
            'is_active' => 'nullable|boolean',
            'salary_range_min' => 'nullable|numeric|min:0',
            'salary_range_max' => 'nullable|numeric|min:0|gte:salary_range_min',
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
            'title.required' => 'Designation title is required.',
            'department_id.exists' => 'Selected department does not exist.',
            'parent_id.exists' => 'Selected parent designation does not exist.',
            'salary_range_max.gte' => 'Maximum salary must be greater than or equal to minimum salary.',
        ];
    }
}
