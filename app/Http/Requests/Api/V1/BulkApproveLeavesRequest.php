<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BulkApproveLeavesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'leave_ids' => ['required', 'array', 'min:1', 'max:100'],
            'leave_ids.*' => ['integer', 'distinct', 'exists:leaves,id'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
