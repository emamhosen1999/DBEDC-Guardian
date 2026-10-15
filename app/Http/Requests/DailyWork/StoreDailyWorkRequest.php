<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use Illuminate\Foundation\Http\FormRequest;

/** Create a daily work. The rules are DailyWorkRules; the RFI number must be new (a duplicate says which work already has it). */
class StoreDailyWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DailyWork::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return DailyWorkRules::fields($this->all(), false);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return DailyWorkRules::messages();
    }
}
