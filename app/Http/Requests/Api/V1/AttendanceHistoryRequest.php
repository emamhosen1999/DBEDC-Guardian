<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'currentMonth' => ['nullable', 'integer', 'between:1,12'],
            'currentYear' => ['nullable', 'integer', 'between:2000,2100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scope' => ['nullable', 'string', 'in:self,team'],
            'employee' => ['nullable', 'string', 'max:120'],
        ];
    }
}
