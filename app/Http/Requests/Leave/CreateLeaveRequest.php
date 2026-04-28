<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class CreateLeaveRequest extends FormRequest
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
            'user_id' => 'required|integer|exists:users,id',
            'leave_type_id' => 'required|integer|exists:leave_settings,id',
            'fromDate' => 'required|date|after_or_equal:today',
            'toDate' => 'required|date|after_or_equal:fromDate',
            'reason' => 'nullable|string|max:1000',
            'document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
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
            'fromDate.after_or_equal' => 'Leave start date must be today or in the future.',
            'toDate.after_or_equal' => 'Leave end date must be on or after the start date.',
            'document.max' => 'Document must not exceed 5MB.',
        ];
    }
}
