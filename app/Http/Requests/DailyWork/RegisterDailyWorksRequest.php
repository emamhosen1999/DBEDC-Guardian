<?php

namespace App\Http\Requests\DailyWork;

use App\Models\DailyWork;
use App\Services\DailyWork\DailyWorkFilters;
use App\Services\DailyWork\DailyWorkRegisterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The register query string: every filter, the sort and the page. Everything is validated; the scope is applied by the service. */
class RegisterDailyWorksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', DailyWork::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort' => ['nullable', Rule::in(array_keys(DailyWorkRegisterService::SORTS))],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'objections' => ['nullable', Rule::in(DailyWorkFilters::OBJECTIONS)],
            'quality' => ['nullable', Rule::in(DailyWorkFilters::QUALITY)],
            'trashed' => ['nullable', 'boolean'],
            'import_batch' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'The end date must be on or after the start date.'];
    }

    public function filters(): DailyWorkFilters
    {
        $in = $this->query();
        // Seeing deleted works needs the restore permission: it is the only reason to look at them.
        if (! $this->user()->can('daily-works.restore')) {
            unset($in['trashed']);
        }

        return DailyWorkFilters::fromArray($in);
    }
}
