<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyWorkAssignedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'assigned' => ['present', 'nullable', 'string', 'exists:users,employee_id'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
