<?php

namespace App\Http\Requests\Jurisdiction;

use App\Models\Jurisdiction;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateJurisdictionRequest extends SaveJurisdictionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Jurisdiction::class) ?? false;
    }

    protected function ignoreId(): ?int
    {
        return $this->route('jurisdiction') instanceof Jurisdiction ? $this->route('jurisdiction')->id : ($this->integer('id') ?: null);
    }

    protected function kindValue(): string
    {
        return Jurisdiction::query()->withTrashed()->find($this->ignoreId())?->kind?->value ?? 'section';
    }

    public function rules(): array
    {
        return parent::rules() + [
            'id' => ['required', Rule::exists('jurisdictions', 'id')->whereNull('deleted_at')],
            // Changing the in-charge is a team change: it closes the history row and needs jurisdiction.team.manage.
            'incharge' => ['nullable', Rule::exists('users', 'employee_id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'id.exists' => 'Jurisdiction not found.',
            'incharge.exists' => 'The selected in-charge is not an active employee.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $v): void {
            $current = Jurisdiction::query()->find($this->ignoreId());
            if ($current !== null && $this->filled('incharge') && (string) $this->input('incharge') !== (string) $current->incharge && ! $this->user()->can('manageTeam', $current)) {
                $v->errors()->add('incharge', 'Changing the in-charge needs the "Manage team" permission.');
            }
        });
    }
}
