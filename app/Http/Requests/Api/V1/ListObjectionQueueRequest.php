<?php

namespace App\Http\Requests\Api\V1;

use App\Models\RfiObjection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListObjectionQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(RfiObjection::$statuses)],
            'category' => ['nullable', 'string', Rule::in(RfiObjection::$categories)],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
