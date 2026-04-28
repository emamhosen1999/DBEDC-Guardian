<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PaginateRequest extends FormRequest
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
            'page' => 'nullable|integer|min:1|max:1000',
            'perPage' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'sort' => 'nullable|string|max:50',
            'sortDirection' => 'nullable|in:asc,desc',
            'filters' => 'nullable|array',
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
            'page.max' => 'Page number cannot exceed 1000.',
            'perPage.max' => 'Items per page cannot exceed 100.',
            'sortDirection.in' => 'Sort direction must be either asc or desc.',
        ];
    }
}
