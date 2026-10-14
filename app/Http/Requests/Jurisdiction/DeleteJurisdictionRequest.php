<?php

namespace App\Http\Requests\Jurisdiction;

use App\Models\Jurisdiction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteJurisdictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', Jurisdiction::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['id' => ['required', Rule::exists('jurisdictions', 'id')->whereNull('deleted_at')]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['id.required' => 'Jurisdiction ID is required.', 'id.exists' => 'Jurisdiction not found.'];
    }
}
