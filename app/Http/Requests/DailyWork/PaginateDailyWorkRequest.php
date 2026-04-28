<?php

namespace App\Http\Requests\DailyWork;

use Illuminate\Foundation\Http\FormRequest;

class PaginateDailyWorkRequest extends FormRequest
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
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:new,in-progress,completed,rejected,resubmission,pending,emergency',
            'search' => 'nullable|string|max:255',
            'inCharge' => 'nullable|integer|exists:users,id',
            'jurisdiction' => 'nullable|integer|exists:jurisdictions,id',
        ];
    }
}
