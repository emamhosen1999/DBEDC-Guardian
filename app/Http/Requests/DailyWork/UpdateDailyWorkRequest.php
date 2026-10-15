<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyWorkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dailyWork = DailyWork::find($this->input('id'));

        if (! $dailyWork) {
            return false;
        }

        return $this->user()->can('update', $dailyWork);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return DailyWorkRules::fields($this->all(), true);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return DailyWorkRules::messages();
    }
}
