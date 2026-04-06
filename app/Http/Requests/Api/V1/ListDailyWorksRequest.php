<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DailyWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDailyWorksRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(DailyWork::$statuses)],
            'type' => ['nullable', 'string', Rule::in(DailyWork::$types)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'only_with_objections' => ['nullable', 'boolean'],
        ];
    }
}
