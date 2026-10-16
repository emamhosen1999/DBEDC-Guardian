<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * A blank or zero currentMonth/currentYear (stale or empty app state, e.g. year=0) means "not given": default it to the current
     * one. Any other out-of-range value still fails validation with a 422, so a real client bug stays visible.
     */
    protected function prepareForValidation(): void
    {
        $blank = fn (mixed $v): bool => $v === null || $v === '' || (is_numeric($v) && (int) $v === 0);
        $merge = [];
        if ($this->has('currentMonth') && $blank($this->input('currentMonth'))) {
            $merge['currentMonth'] = now()->month;
        }
        if ($this->has('currentYear') && $blank($this->input('currentYear'))) {
            $merge['currentYear'] = now()->year;
        }
        if ($merge) {
            $this->merge($merge);
        }
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
