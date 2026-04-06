<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DailyWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDailyWorkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(DailyWork::$statuses)],
            'inspection_result' => ['nullable', 'string', Rule::in(DailyWork::$inspectionResults)],
        ];
    }
}
