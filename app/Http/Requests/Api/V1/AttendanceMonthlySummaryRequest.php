<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceMonthlySummaryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * A blank or zero month/year (stale or empty app state, e.g. year=0) means "not given": default it to the current
     * one. Any other out-of-range value still fails validation with a 422, so a real client bug stays visible.
     */
    protected function prepareForValidation(): void
    {
        $blank = fn (mixed $v): bool => $v === null || $v === '' || (is_numeric($v) && (int) $v === 0);
        $merge = [];
        if ($this->has('month') && $blank($this->input('month'))) {
            $merge['month'] = now()->month;
        }
        if ($this->has('year') && $blank($this->input('year'))) {
            $merge['year'] = now()->year;
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
