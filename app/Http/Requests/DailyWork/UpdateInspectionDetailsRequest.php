<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInspectionDetailsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dailyWork = DailyWork::find($this->input('id'));

        if (! $dailyWork) {
            return true; // Let exists validation rule fail and return 422
        }

        return $this->user()->can('updateInspectionDetails', $dailyWork);
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
        return [
            'id.required' => 'Daily Work ID is required.',
            'id.exists' => 'Daily Work not found.',
            'inspection_details.max' => 'Inspection details cannot exceed 1000 characters.',
        ];
    }
}
