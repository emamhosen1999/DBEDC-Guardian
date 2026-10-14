<?php

namespace App\Http\Requests\Jurisdiction;

use App\Models\Jurisdiction;
use Illuminate\Validation\Rule;

class StoreJurisdictionRequest extends SaveJurisdictionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Jurisdiction::class) ?? false;
    }

    protected function ignoreId(): ?int
    {
        return null;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'incharge' => ['required', Rule::exists('users', 'employee_id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'incharge.required' => 'Jurisdiction in-charge is required.',
            'incharge.exists' => 'The selected in-charge is not an active employee.',
        ];
    }
}
