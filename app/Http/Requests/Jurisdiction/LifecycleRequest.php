<?php

namespace App\Http\Requests\Jurisdiction;

use App\Models\Jurisdiction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Split, merge, retire and restore. Each action has its own permission (the policy ability named by the route); a
 * back-dated effective date is checked by the service (jurisdiction.history.correct + a reason).
 */
class LifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match ($this->route()?->getName()) {
            'jurisdictions.split', 'jurisdictions.merge' => 'restructure',
            'jurisdictions.retire' => 'retire',
            'jurisdictions.restore' => 'restore',
            default => null,
        };

        return $ability !== null && ($this->user()?->can($ability, Jurisdiction::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $active = Rule::exists('users', 'employee_id')->where('is_active', true)->whereNull('deleted_at');
        $part = fn (string $k) => [
            "{$k}.code" => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,31}$/', Rule::unique('jurisdictions', 'code'), $k === 'second' ? 'different:first.code' : 'string'],
            "{$k}.name" => ['required', 'string', 'max:120', Rule::unique('jurisdictions', 'location')->whereNull('deleted_at'), $k === 'second' ? 'different:first.name' : 'string'],
            "{$k}.incharge" => ['nullable', $active],
        ];

        $rules = ['effective_date' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:500']];

        return match ($this->route()?->getName()) {
            'jurisdictions.split' => $rules + ['at_m' => ['required', 'integer', 'min:1', 'max:48999']] + $part('first') + $part('second'),
            'jurisdictions.merge' => $rules + ['with' => ['required', 'integer', Rule::exists('jurisdictions', 'id')->whereNull('deleted_at')],
                'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,31}$/', Rule::unique('jurisdictions', 'code')],
                'name' => ['required', 'string', 'max:120', Rule::unique('jurisdictions', 'location')->whereNull('deleted_at')],
                'incharge' => ['required', $active]],
            'jurisdictions.retire' => $rules + ['confirm_team' => ['nullable', 'boolean'], 'confirm_open_works' => ['nullable', 'boolean']],
            default => $rules + ['incharge' => ['nullable', $active]],
        };
    }

    protected function prepareForValidation(): void
    {
        $all = $this->all();
        foreach (['code', 'first.code', 'second.code'] as $key) {
            if (is_string(data_get($all, $key))) {
                data_set($all, $key, strtoupper(trim(data_get($all, $key))));
            }
        }
        $this->replace($all);
    }
}
