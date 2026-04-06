<?php

namespace App\Http\Requests\Api\V1;

use App\Models\RfiObjection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDailyWorkObjectionsRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(RfiObjection::$statuses)],
        ];
    }
}
