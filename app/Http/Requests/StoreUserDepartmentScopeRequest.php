<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Services\Access\DepartmentScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant (or renew) a department scope on a user. Authorized BEFORE validation:
 * only a global actor who outranks the grantee (the route adds the
 * department.scopes.manage permission). The controller re-checks for list/revoke.
 */
class StoreUserDepartmentScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $grantee = User::find($this->route('id'));
        $scope = app(DepartmentScope::class);

        return $grantee !== null
            && $scope->isGlobal($this->user())
            && $scope->outranks($this->user(), $grantee);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'scope_type' => ['required', 'string', Rule::in(UserDepartmentScope::TYPES)],
            'starts_at' => ['nullable', 'date'],
            // An acting charge is temporary by definition, so it must carry an end.
            'expires_at' => array_values(array_filter([
                Rule::requiredIf(fn () => $this->input('scope_type') === UserDepartmentScope::TYPE_ACTING),
                'nullable',
                'date',
                'after:now',
                $this->filled('starts_at') ? 'after:starts_at' : null,
            ])),
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'expires_at.required' => 'An acting charge needs an end date.',
            'expires_at.after' => 'The end date must be in the future and after the start date.',
        ];
    }
}
