<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncPullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'cursor' => ['required', 'date'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(['attendance', 'leaves', 'daily_works'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
