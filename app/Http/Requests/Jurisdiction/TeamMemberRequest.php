<?php

namespace App\Http\Requests\Jurisdiction;

use App\Models\Jurisdiction;
use App\Models\JurisdictionMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Team changes (add, change role, change in-charge, end). Gated on jurisdiction.team.manage. */
class TeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTeam', $this->route('jurisdiction') ?? Jurisdiction::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $active = Rule::exists('users', 'employee_id')->where('is_active', true)->whereNull('deleted_at');

        return [
            'employee_id' => [in_array($this->route()?->getName(), ['jurisdictions.team.add', 'jurisdictions.team.in-charge'], true) ? 'required' : 'nullable', 'string', $active],
            'role' => ['nullable', Rule::in([JurisdictionMember::DEPUTY, JurisdictionMember::MEMBER])],
            'previous_becomes' => ['nullable', Rule::in([JurisdictionMember::DEPUTY, JurisdictionMember::MEMBER])],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Choose an employee.',
            'employee_id.exists' => 'The selected employee is not active.',
            'role.in' => 'Choose deputy in-charge or member.',
        ];
    }
}
